# 40 — The WhatsApp AI on two questions: unlimited data for a business, and covering another area

26 September 2026. **A check, not a change.** No plugin code, setting, knowledge-base row or uCRM record was
changed. The fixes in §8 are proposals that wait for the operator's approval.

**27 September:** the check ran on the server (§10), the operator decided both questions, and **5.18.44 was built**
from those decisions (§11). §1–§9 are the record as it stood before; where the build departs from them, §11.3
says so. The deploy command and what to send back are in §12.

**27 September, 06:14 UTC:** 5.18.44 was deployed and PASSED (§13). The same morning the operator added a rule for
indoor coverage, and settled which of two approved texts was right about Business data. **5.18.45** was built from
both (§14); its deploy is §15.

**27 September, 07:00 UTC:** the 5.18.45 deploy stopped at its own backup, before changing anything, and
5.18.44 stays live. The deploy script was fixed and rehearsed (§15.1); the command is the same.

**27 September, 07:54 UTC:** the same command, run again, deployed 5.18.45 and PASSED (§16). Three of its eleven
replies should have been refused and were not. They, and the operator's report on the quotation summary, are
**5.18.46** (docs/41).

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
- `scripts/harness/deploy-5.18.44/rehearse.sh` runs **the pinned `deploy-5.18.44.sh` itself** (`--after-only`) against
  a sandbox container: a fake `docker`, this checkout's plugin at the container's path with its brain pointed at a
  fake AI provider, a fake uCRM, and a plugin database built by the real migrations with the knowledge rows as
  5.18.43 shipped them. **46/46 on three consecutive runs**, no residue. Seven scenarios, labelled as the harness
  prints them:
  - **1 — 5.18.43's rows.** K dry-runs, corrects MANY_USERS_HOTSPOT to the 5.18.44 seed text word for word, then
     dry-runs again and finds nothing. A drifted row that is still as seeded is untouched, and so is a person's
     row. The tool runs as the database's owner, twice as a dry run and once for real. All eight AI checks pass,
     and the ten questions are ten calls on the fake provider.
  - **1b — the same with no person's row** — the case the server will meet, since every row there is as seeded
     (§10). Only the one row changes.
  - **2 — run again.** Nothing is written.
  - **3 — MANY_USERS_HOTSPOT edited by a person.** Reported, and left as it is; AI says whose wording it reads.
  - **4 — an older tool in the container.** Refused, nothing written. That tool would ignore `--only` and
     `--dry-run` and refresh every seeded row.
  - **5 — no AI key.** The questions are not asked, and that is a FAIL.
  - **6 — the container serves another commit.** `--after-only` stops before anything is written or asked.

  Five weakened copies of the script (section 7) each fail:
  - the refresh not limited to the one row;
  - an older tool run anyway;
  - the tool run as root;
  - the questions not asked, but reported as asked;
  - no dry run before the correction.

  Stage V is `deploy-5.18.43.sh`'s, unchanged (25 ok live on 26 Sep). Here a stand-in answers it, and its lines
  are not judged: every FAIL in these runs is a V line.
- **What the first run of that rehearsal found — in the harness, not the script.** It expected the deploy-mode
  line *"the container already serves"*, which `--after-only` never prints; it now expects `ok live commit is
  a4abe5e`. More telling: the copy with `--only` removed **was not caught**. The sandbox always held a person's
  row, the tool then printed *"Edited by hand"*, and the script stopped at that branch and wrote nothing. The
  weakened copy was safe there by accident, so the rehearsal could not show that `--only` did any work. It now
  runs that copy on an estate with no person's row, beside the control (scenario 1b), where the copy rewrites the
  drifted row and is caught.

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
4. **What the log should show**, stage by stage (rehearsed, §11.6):
   - **A:** `plugin commit a4abe5e (expected a4abe5e)`, and the live commit, which is the rollback commit.
   - **K:** `ok K MANY_USERS_HOTSPOT now reads the 5.18.44 wording…`, then `ok K a second dry run has nothing left
     to do`. The dry run before it names that one row and no other. A `note K … was edited by a person` means
     somebody changed the row in the knowledge screen. It is then left as it is and the assistant reads that wording: send the
     log, and change nothing.
   - **V:** the same lines as the 5.18.43 deploy, all `ok`.
   - **AI:** eight `ok` lines, the network equipment listed with a router and an access point, and the ten replies
     to read. **The replies are the measurement** (§11.7): they are read against the baseline, not passed or failed.
   - **F:** `5.18.44: PASSED`.
5. **Running it again is safe.** Once the container serves `a4abe5e` the script skips the deploy. Stage K then
   finds nothing left to do, and stage AI asks the ten questions again — ten more model calls.

## 13. The 5.18.44 deploy — 27 September 2026, 06:14 UTC

**PASSED: 34 ok, 0 failed, 1 note.** The operator ran the pinned command: `a4abe5e` over `04155df`.

- **A.** The backup was taken (101 MB and 92 KB) and UISP health was recorded.
- **B.** The container serves `a4abe5e`.
- **K.** MANY_USERS_HOTSPOT was corrected, as the database's owner (`1000:1000`), with a dry run before and after.
- **V.** Twenty `ok`, and no fatal error in the container log.
- **AI.**
  - The check read 5.18.44.
  - NETWORK EQUIPMENT lists five items: the MikroTik ← router, the Ruijie ← access point, the cable, the
    connectors and the consultancy.
  - Knowledge reaches the assistant up to 1,000 characters; the data-allowance fact is stated; MANY_USERS_HOTSPOT
    reads whole at 994 characters.
  - The ten questions were asked.

The operator pasted the terminal rather than sending the log file. Nothing this command prints is secret: the key
reads "set (not shown)", and conversations are masked.

### 13.1 The one note was the deploy script's fault, not the plugin's

The note said *"no access point is recognised among the network equipment"*. Yet the listing a few lines above it
showed `Ruijie Reyee RG-RAP6262(G) 1,100,000 ← access point`.

**Cause, measured:**

- `aihas` ran `printf "$AIOUT" | grep -q` under `set -o pipefail`.
- `grep -q` leaves at its first match. If `printf` is still writing, it dies of SIGPIPE, and `pipefail` then
  reports the whole pipeline as failed.
- It is a race. On a 33 KB copy of the output, 2 of 2,000 early matches were lost here.
- At 1 MB the pipe form loses 20 of 20. A here-string loses 0 of 2,000 at either size.

`deploy-5.18.45.sh` uses a here-string. Its rehearsal tries the matcher 20 times on 1 MB of output, and runs the
same test on the pipe form as the control. `deploy-5.18.44.sh` is left exactly as it ran.

### 13.2 What the assistant said: ten questions, 5.18.44 live

| Question | Reply |
|---|---|
| A1 "a WiFi business in my trading centre" | **refused by the price check** — the fallback, staff alerted |
| A1 "50 people, unlimited, which package?" | the higher-capacity Residential plan, 329,000, unlimited data; offers the network design; hands over |
| A2 "unlimited business plans?" | "…then switch to unlimited standard data once that block is used up" — **wrong** (§13.3) |
| A3 "How much is Business 500?" | 285,000, and the Business-plan note appended |
| A4 "sell internet around my shop" | Residential, 329,000, "no data cap"; no mention of a reseller programme |
| B1 "cover 200 m around my hotspot" | **refused by the price check** |
| B1 "two access points and the MikroTik" | 700,000 + 2 × 1,100,000 = **2,900,000** — right |
| B2 "WiFi to my other building" | MikroTik, Ruijie, cable, connectors, consultancy = **2,297,500** — right; the site survey; hands over |
| B3 "price of the access point and MikroTik" | 1,100,000 and 700,000 — right (5.18.43 could not quote the MikroTik) |
| C1 "installed at my home" | Mini Kit 2,249,000 + installation 150,000 = **2,399,000**; no network equipment added — right |

**The two refusals are the two open design questions.** The price check found an amount it could not match, but
this log could not say which. The check tool now prints the amounts it could not match, with the draft (§14.3).
The 5.18.43 answers from the morning check, the baseline, never arrived, so this comparison is with the live
conversations of §10.

### 13.3 Two approved texts contradicted each other, and the operator decided

- **BUSINESS_PLANS** said *"after that block is used, unlimited standard data continues"* and *"then it behaves
  like standard data"*.
- **The note appended to Business replies** (`PlanFenceGuard::DEFAULT_NOTE`) said *"the connection drops to about
  1 Mbps until more is bought"*.

In A2 the assistant repeated the first. The contradiction was older than 5.18.44: that sentence was always inside
the old 600-character cut.

**The operator, 27 Sep: "Drops to ~1 Mbps".** BUSINESS_PLANS is corrected in 5.18.45 (§14.2).

The only other text in this repository with the same claim is `dishnet-web/site/guide-how-much-data.html`. That
page is the **Sudan** website, about Sudan's Priority plans, a different market, and it was left alone.

### 13.4 Two hand-overs gave the word "reason" as their reason

In A1's second turn and in B2, the hand-over reason was literally *"reason"*. The prompt's marker legend has read
`<<ESCALATE reason>>` since 2 September, and the model sometimes copies it word for word. Staff are still alerted,
but the reason tells them nothing.

The legend is in South Sudan's prompt too, so a clearer one would change both installs. That makes it **proposal
P9, for approval**. It was not changed.

### 13.5 The operator's rule for indoor coverage

> "Ruijie Reyee RG-RAP6262(G) … this is out door and for indoor if some one want to cover more floor we have to
> suggest starlink routers"

This is built in 5.18.45 (§14.1).

## 14. 5.18.45 — as built, 27 September 2026

### 14.1 More floors inside one building: Starlink routers

- **Which products are Starlink routers.** The shop catalogue already records it: category *Router*, matched on
  the exact product name, as the shop does. Two of the twenty live accessories qualify:
  - **Router Mini**, 301,000 — fits Standard 4, Standard 4 X, Mini and Gen 2 kits (not Gen 1);
  - **Router 3 | Starlink V4 or V5, Mini**, 827,000 — fits Standard 4, Standard 4 X, Mini, Gen 2 and Gen 3 kits.

  Nothing is guessed from a name: *Router 3 Mount* is a mount. This is `NetworkEquipment::starlinkRouters`, and
  the prompt and the price check both use it.
- **The prompt.**
  - Those two lines in ACCESSORIES are marked *"Starlink router: Wi-Fi inside the building, working with the other
    Starlink routers as a mesh; fits …"*.
  - A new rule, **MORE FLOORS OR ROOMS INSIDE ONE BUILDING — STARLINK ROUTERS**:
    - more Starlink routers as a mesh, never the outdoor access point or the MikroTik;
    - offer the routers that fit their kit, each with its price;
    - if the kit is unknown, name the routers and what each fits, and ask which kit they have **in the same reply**
      (both routers fit every kit DishNet sells);
    - one router for each floor beyond the one the main router is on, as quantity × price = amount, then a TOTAL;
      otherwise one router, and the price of each more;
    - the site survey confirms the number;
    - never a coverage figure;
    - a hand-over to book the survey.
  - NETWORK EQUIPMENT gains *"This is for OUTDOORS and other buildings…"*.
- **The price check** allows 1 to 5 of one Starlink router, alone or with any combination of the kit and the
  installation. That adds 160 permitted totals, and **not one round figure that 5.18.44 refused**:
  - with the test's inputs, 78 of the 199 round amounts pass, the same list in both versions;
  - with the three router-related accessories alone, 73 of 199, again the same list.

  With both kits in one total, Mini + Standard + 2 × Router Mini = 5,500,000 is round, but 5.18.44 already let
  that figure through another way.
- **Where it applies.** Only where the hardware module is on **and** a Starlink router is listed.
  - South Sudan is byte-identical: all 50 fingerprints match.
  - Uganda with no router listed is byte-identical to 5.18.44: 26 fingerprints, fixed from `a4abe5e` — 24 prompts
    on three paths, and 2 price-check lists.
- **Why one per floor beyond the main router's:** it mirrors the access-point rule the operator approved. The
  survey decides.

### 14.2 BUSINESS_PLANS: about 1 Mbps, as the operator answered

- *"when that block is used up, the connection drops to about 1 Mbps until more is bought"* replaces *"unlimited
  standard data continues"*.
- *"and then the connection drops to about 1 Mbps"* replaces *"then it behaves like standard data"*.
- The short form now reads *"after it, about 1 Mbps until more is bought"*.
- **981 characters** (the limit is 1,000) and **296** (the limit is 300), so both reach the assistant whole.
- The row agrees with the note appended to Business replies now. Every phrase the existing tests pin was kept.
- Stage K corrects that one row, exactly as in 5.18.44.

### 14.3 The check tool

- It lists the Starlink routers, with what each fits.
- For each knowledge row it says what the row claims happens after the priority block: *"the approved fact"*, or
  *"NOT the approved fact"*.
- It asks **eleven** questions. The new one is **B4**: *"The WiFi does not reach the upper floors of my house. It
  has 3 floors. What do I need and how much?"*.
- For a reply the price check refuses, it prints the **amounts it could not match** and **the draft**, masked like
  every reply.
- `scripts/harness/ai-check/rehearse.sh` now runs against 5.18.44, what the server runs, and 5.18.45: **230/230,
  twice**. Four new weakened copies are each caught.

### 14.4 Proofs

- `tests/test_ai_indoor_routers.php`: **84 assertions**. **Twelve weakened copies each fail it**, each at the
  assertion written for it:
  - every accessory taken for a router, and none ever a router;
  - the routers on every install;
  - the outdoor line where no router is listed;
  - no floors rule, and no marking;
  - the main router's floor priced too;
  - no router totals, more than five, and never with the kit;
  - BUSINESS_PLANS reverted, and its short form reverted.
- The 5.18.44 suite is unchanged: 159.
- The full suite: **210 suites, exit 0, twice; the 189 that print totals report 8,510 passed, 0 failed on both runs** (8,426 in 5.18.44, plus the new 84).
- `scripts/harness/deploy-5.18.45/rehearse.sh` runs the pinned deploy script against a sandbox container: **53/53 on two consecutive runs**, over seven scenarios and the matcher test. Six weakened copies each fail, the matcher turned back into a pipe among them. Its first run found one fault, in the harness: the detector for "questions not asked, reported as asked" still looked for "ten".
  Its new matcher test tries the check 20 times on 1 MB of output: the here-string finds it 20 times out of 20,
  while the 5.18.44 pipe form, as the control, finds it 0 times.
- `tests/conversation-suite.php` has two new live scenarios for the server: `ug_more_floors` must name a Starlink
  router and never the Ruijie, and `ug_business_after_priority` must never say standard data continues.

### 14.5 Recorded, not changed

- **P8:** the price check's prompt-digits rule.
- **P9:** the `<<ESCALATE reason>>` legend (§13.4).
- **F-1 still holds.** A wrong figure that happens to equal a real combination passes.
- **What the model does is still measured, not assumed.** The replies in stage AI are the measurement.

## 15. Deploy, and what to send back

1. **The deploy:**
   ```
   cd /opt/dishnet && git pull origin claude/study-this-jhe2eg \
     && mkdir -p /root/dnb-5.18.45 \
     && bash scripts/deploy-5.18.45.sh 2>&1 | tee /root/dnb-5.18.45/deploy-$(date -u +%Y%m%dT%H%M%SZ).log
   ```
   It takes a backup, then asks you to type `DEPLOY`. It then:
   - deploys;
   - corrects BUSINESS_PLANS (stage K: a dry run first, that row only, only while still as seeded);
   - re-checks the public pages (stage V);
   - asks the assistant the eleven questions (stage AI).

   Send back **the log file**.
2. **What the log should show:**
   - **A:**
     - `plugin commit 0850e59 (expected 0850e59)`, and the live commit `a4abe5e`, which is the rollback commit;
     - `ok backed up plugin.sqlite3 …` and `ok backed up dishnet.sqlite …`, each `one consistent copy (VACUUM INTO …),
       integrity ok`;
     - `ok backed up …/.dishnet-hybrid-sudan-data … without the live databases`, and the plugin's `data` folder;
     - possibly `note while tar read … It said:` with tar's own words. That is logs being written, and it is not
       a failure;
     - `GO`.
   - **K:** `ok K BUSINESS_PLANS now reads the 5.18.45 wording…`, then `ok K a second dry run has nothing left to do`.
   - **V:** as on 27 Sep, all `ok`.
   - **AI:**
     - the Starlink routers are listed;
     - BUSINESS_PLANS reads 981 characters;
     - no row says standard data continues;
     - the eleven replies, for reading. **B4** is the new one.
   - **F:** `5.18.45: PASSED`.
3. **Running it again is safe.** Once the container serves the pinned commit, the deploy is skipped.

### 15.1 The first run stopped at the backup — 27 September, 07:00:32 UTC

**What the log said.** Stage A showed:
- the plugin commit `0850e59`, as expected, and the live commit `a4abe5e`;
- `FAIL backup of /home/unms/data/ucrm/ucrm/data/plugins/.dishnet-hybrid-sudan-data failed`;
- `ok backed up …/dishnet-hybrid-sudan/data → …/data.tar.gz (88K)`;
- the UISP health recorded;
- `STOP: NO-GO: the backup did not complete`.

**Nothing was deployed, and 5.18.44 stays live.** The operator pasted the terminal. This script prints no secret, so
that did no harm; the log file is still the thing to send.

**Why — most likely, not proven.** The script threw tar's own words away (`2>/dev/null`), so the log cannot say.
- The same backup passed at 06:14 for 5.18.44.
- GNU tar exits 1, *"file changed as we read it"*, when a file grows while it reads it. The rehearsal reproduces
  exactly that.
- The data directory holds the plugin's two live databases (`plugin.sqlite3`, `dishnet.sqlite`) and its logs. They
  are written all the time, and most at the top of the hour. This run started 32 seconds past 07:00.

In that case the archive tar writes is complete, but a copy of a SQLite file taken while it is written can be torn.
**Such a copy is not a backup to rely on**, so treating exit 1 as a failure was not wrong. What was missing was a
way to copy a live database.

**What changed — the script only.** The plugin, the pin `0850e59` and every other stage are unchanged.
1. `plugin.sqlite3` and `dishnet.sqlite` are each copied inside the container by SQLite itself (`VACUUM INTO`, one
   read transaction), so the copy is the database as of one moment.
   - It runs as the database's owner, so no `-wal` or `-shm` file changes hands.
   - The copy is checked with `integrity_check`, and its sha256 is compared on both sides.
   - The temporary file in the container's `/tmp` is removed.
2. tar archives the rest of the data directory **without** the live database files and their `-wal`, `-shm` and
   `-journal`. A database one level down (for example in `backups/`) stays in.
3. tar exit 1 becomes a **note**, once the archive reads back, with tar's own words. Runs of four or more digits
   are masked, because file names can carry phone numbers. Exit 2 or more, or an archive that cannot be read back,
   is a **FAIL** with tar's words — and NO-GO, as before.

**Proof.** `scripts/harness/deploy-5.18.45/rehearse.sh` now gives **108/108 on two consecutive runs** (it was 53).
It runs in deploy mode while both databases get a row every 2 ms and a log gets a line every 2 ms.
- **Control:** the script as the operator ran it (`4fe4cb7`) stops exactly as it did live — FAIL on the data
  directory, NO-GO, nothing said about why. Bare tar exits 1 with *"file changed as we read it"*.
- **The fixed script:**
  - both copies are `ok`, and pass `integrity_check` again outside the container;
  - the copy holds every knowledge row, and the rows written up to one moment — fewer than were written by the end;
  - the directory is archived without the live databases, and with the copy one level down;
  - tar's line is shown with the phone-length number masked;
  - `GO`, then stage B stops, since no terminal can type DEPLOY. Nothing is deployed, no knowledge row is written,
    and no temporary copy is left behind.
- **Four real failures each stop it NO-GO, with the reason:**
  - a database that is not a database (SQLite's words);
  - tar exit 2 (tar's words);
  - an archive that cannot be read back;
  - a copy changed on its way out with its size intact — only the sha256 tells.
- **Seven weakened copies are each caught:**
  - the live databases archived as well;
  - the copies made as root;
  - tar's words thrown away;
  - a failed copy only noted;
  - tar exit 2 accepted;
  - the archive not read back;
  - the sha256 not compared.
- **Not exercised:** the integrity check of the copy. No way was found to make `VACUUM INTO` produce a copy that
  fails it, so it stays as a second check behind the sha256.

**The first run's leftover.** `/root/dnb-5.18.45/backup-20260927T070032Z/` holds that run's partial backup. It can be
kept or deleted; nothing reads it.

**Next:** the same command as in item 1, run again.

## 16. The 5.18.45 deploy — 27 September 2026, 07:54 UTC

**PASSED: 39 ok, 0 failed, 0 notes.** The operator ran the same command again after the fix of §15.1 (the pull
brought `9ed6dce`): `0850e59` over `a4abe5e`.

- **A. The backup worked live, as rehearsed.**
  - `plugin.sqlite3`: one consistent copy (`VACUUM INTO` as `1000:1000`, SQLite 3.48.0), integrity ok, 224 tables,
    the same sha256 on both sides; 22 MB.
  - `dishnet.sqlite` is not in this install's data directory: *"nothing to copy"*. That is this install, not a fault.
  - The data directory was archived without the live databases (97 MB), and the plugin's `data` folder too (92 KB).
  - tar exited 0 both times, so there was no note.
  - UISP health was recorded, then `GO`.
- **B.** The container serves `0850e59`.
- **K.** BUSINESS_PLANS reads the 5.18.45 wording; a second dry run has nothing left to do.
- **V.** All `ok`, and no fatal error in the container log.
- **AI.** Every line `ok`:
  - the check read 5.18.45;
  - NETWORK EQUIPMENT lists 5: the MikroTik ← router, the Ruijie ← access point, cable, connectors, consultancy;
  - knowledge reaches the assistant up to 1,000 characters;
  - STARLINK ROUTERS lists 2;
  - the data-allowance fact is stated;
  - BUSINESS_PLANS reads 981 characters, whole, and no row says standard data continues;
  - MANY_USERS_HOTSPOT reads 994 characters;
  - the eleven questions were asked: *"0 refused by the price check · 1 with the Business-plan note added"*.

The operator pasted the terminal again. This command prints no secret; the log file is still the thing to send.

### 16.1 What the assistant said: eleven questions, 5.18.45 live

| Question | Reply |
|---|---|
| A1 "a WiFi business in my trading centre" | the higher-capacity Residential plan, unlimited; offers to design the network and asks for the users or the area |
| A1 "50 people, unlimited, which package?" | Residential, 329,000; lists the kit, installation, MikroTik, one access point, cable and consultancy, then **"TOTAL FOR SETUP: [Sum of setup costs]"** — **an unfilled slot, sent** |
| A2 "unlimited business plans?" | no; Business plans are blocks of priority data, and the speed drops once one is used; recommends Residential — as the corrected row says |
| A3 "How much is Business 500?" | 285,000, and the Business-plan note appended |
| A4 "sell internet around my shop" | the higher-capacity Residential plan, unlimited standard data; asks where the shop is |
| B1 "cover 200 m around my hotspot" | MikroTik, access point, cable, connectors, consultancy, then **"1993500 UGX"** — **wrong: 1,897,500**; the survey |
| B1 "two access points and the MikroTik" | 700000 + 2 × 700000 = **2100000** — right |
| B2 "WiFi to my other building" | the same five items, then **"1999500 UGX"** — **wrong: 1,897,500**; the survey |
| B3 "price of the access point and MikroTik" | 700,000 and 700,000 — right |
| B4 "3 floors; the upper floors have no WiFi" (new) | the two Starlink routers, 301,000 and 827,000, and which kit the customer has; no outdoor equipment — the rule held |
| C1 "installed at my home" | Mini Kit 2,249,000 + installation 150,000 = **2,399,000**, then Residential at 329,000 a month; nothing extra added |

### 16.2 Three replies the price check should have stopped

B1 and B2 wrote a wrong total without commas, and A1 left a template slot. **None was refused.** The check read
only amounts written with separators, the prompt gives every price without them, and a slot holds no amount at
all. The fix is **5.18.46** (docs/41 §3).

### 16.3 Noted, not changed

- **B4** did not say the survey confirms the number of routers. It also said the Router Mini "works with Mini kit"
  and Router 3 "with Standard kit", which is narrower than the catalogue (docs/41 §4).
- **C1** priced a home with the higher-capacity plan, not Residential Lite.
- **uCRM changed a price between the runs.** The Ruijie access point was 1,100,000 at 06:14 and 700,000 at 07:55,
  so B1's second turn is now 2,100,000 (2,900,000 in §13.2).

### 16.4 The quotation summary

The same day the operator reported the quotation summary's "Monthly" line on order 000114, and chose a clearer
message. It is built in 5.18.46, with the price check (docs/41 §1–§2).
