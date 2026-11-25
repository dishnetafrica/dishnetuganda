# 76 — Uganda: the quotation built in code, quoted customers, the "assistant off" list, and the week's fixes (5.18.107 design, 21 November 2026; re-scoped 25 November)

**Status:** DESIGN; nothing is built. **Re-scoped on 25 Nov** at the owner's word: this file is 5.18.107, and Parts H,
S, N and L moved to 5.18.108 (docs/77). The re-scope and Part W were reviewed twice (reviews 7 and 8, §R.9, §R.10);
review 8's changes are **not yet reviewed** (§R.7).

- **Written:** 21 Nov 2026, from a read-only map of `release/5.18.106` (bf29cbd). Five readers made the map, and a
  critic spot-checked at least two citations per map (§B.7).
- **Reviewed six times on 21 Nov,** all seven parts as they then stood, and **a seventh time on 25 Nov**. Each review
  was adversarial, in two lenses, and every finding then went to a separate checker that tried to refute it:
  - **Review 1:** correctness against the code, and safety, rollout and the owner's intent. 41 findings: 30 confirmed,
    11 partly confirmed.
  - **Review 2:** whether review 1's fixes were taken correctly, and new defects. 34 findings: 30 confirmed, 4 partly
    confirmed.
  - **Review 3:** the same two lenses on the third draft. 20 findings: 17 confirmed, 3 partly confirmed.
  - **Review 4:** Part X and F-7 for the first time, and review 3's fixes. 19 findings: 14 confirmed, 4 partly
    confirmed, 1 refuted.
  - **Review 5:** review 4's changes. 16 findings: 15 confirmed, 1 partly confirmed.
  - **Review 6:** review 5's changes. 8 findings: 5 confirmed, 3 partly confirmed.
  - **Review 7 (25 Nov):** Part W, the re-scope, and review 6's changes to Part X. 29 findings: 13 confirmed, 14
    partly confirmed, 2 refuted.
  - **Review 8 (25 Nov):** review 7's changes. 16 findings: 7 confirmed, 8 partly confirmed, 1 refuted.
  - Every finding that held is taken; the refuted ones are kept as notes. §R says where.
- **Re-scoped, 25 Nov.** The owner split the next version in two. **5.18.107** keeps Part Q (the quotation built in
  code), Part K (quoted customers), Part X (the "assistant off" list), F-1, F-7 and F-8, and gains **Part W**, the
  week's items (§W): one reply to a burst of messages, the topic rule built in, the office line on "customer care", the
  transcription default, the AI Knowledge Base screen, a two-prices warning, and two fixes for the deploy check and the
  rehearsal. **5.18.108** gets Parts H, S, N and L, carried to docs/77 (§R.8 says what moved word for word). Their
  sections here are stubs, so that every cross-reference still resolves.
- **The owner's decisions for 5.18.107 are taken** (§O): O7, O10, O11 and O13, each as recommended, on 25 Nov.
- **Code base:** every file cited under `dishnet-hybrid-sudan/` is byte-identical in the live `release/5.18.105`
  (7835906), except the follow-up files 5.18.106 changed (`cron/followup_{close,run,scan,send}.php`,
  `lib/FollowUpService.php`). 5.18.106 (bf29cbd) is live since 22 Nov.
- **Order:** 5.18.107 is cut from 5.18.106 and can be deployed only after it; 5.18.108 is cut from 5.18.107.
- **Scope:** every part is Uganda-only (`StaffJobsGate::applies`). Each part that changes what customers receive has its
  own switch, **off by default**; the exceptions are said where they apply:
  - Part X's list is its own switch. While `wa_no_ai` cannot be read (a failed 092, a missing table or a corrupt
    store), no Uganda customer gets an AI reply, and each `ai.reply` ends in a staff alert with no hand-over (§X.5); the
    deploy script checks 092 (§M);
  - F-1, F-7 and F-8 are defect fixes;
  - W.2 and W.3 are behaviour the owner asked for, live at the deploy; W.4–W.6 are a default change, a screen fix and
    screens;
  - **O7** removes "Nothing is added on top." from the uCRM quotation's WhatsApp message, the app and KYC quotation
    message and the plugin's fallback PDF at the deploy, on the owner's 25 Nov decision.

  A rollback of the release is the only undo for O7's and W.2's code; W.3 can also be emptied in settings (§W.3).
  South Sudan must stay byte for byte unchanged.
- **Before the build:** the production values in §N; the owner's confirmation of the defaults W-O1–W-O5 and X-O1
  (§O); and a review of review 8's changes.

Paths below are relative to `dishnet-hybrid-sudan/` unless they start with `docs/` (this repository's root `docs/`) or
are written root `scripts/…` (this repository's root `scripts/`).

**Asked for** (owner, 18–20 Nov; paraphrased, no customer data):

1. **A "Hi" after prices** — moved to 5.18.108 (docs/77).
2. **The quotation for a new connection.**
   - Two options: Standard Kit + Residential (up to 400 Mbps, since 23 Nov named "up to 350 Mbps" in uCRM) first, then
     Mini Kit + Residential Lite (up to 100 Mbps).
   - Each option lists the kit, the installation and the Pivot Mount that fits the kit, inside "TOTAL TO GET CONNECTED
     WITH INSTALLATION".
   - The subscription goes inside a total too.
   - Then "Transport is charged extra." and a closing question.

   Today this is a settings text the model follows imperfectly. It took another Mini mount's price (§B.3). On 21 Nov
   the owner was given a settings text that types the exact figures, both totals included, as a stop-gap until this
   part is built.
3. **A same-day reminder** — moved to 5.18.108 (docs/77).
4. **Name and email** — moved to 5.18.108 (docs/77).
5. **uCRM leads** — moved to 5.18.108 (docs/77).
6. **A customer who already has a valid quotation** (20 Nov, chosen "Answer, no new prices"). Enforced in code:
   - they still get answers to general questions;
   - they get no new prices or totals;
   - they are pointed to the quotation already sent;
   - and the salesperson who sent it is alerted.
7. **Some numbers get no automatic replies** (21 Nov). The owner asked "if i want some of number dont go auto reply how
   can i do it". Chosen: "AI replies + follow-ups" and "Yes, alert".
   - The assistant never answers a number on the list, never follows up, and never sends it the "Hi".
   - Invoices, receipts and payment reminders still go.
   - The team sees the messages in the Inbox as usual.
   - When such a number writes in, its salesperson is alerted (otherwise the central alert number), at most once every
     few hours per customer.
   - The list is managed in AI setup and by a button on each chat in the Inbox.

**Asked for or found in the week of 22–25 Nov** (§W; owner's words paraphrased, no customer data):

8. **One reply to a quick burst of messages** (22 Nov). Two messages a few seconds apart each got the same answer.
9. **Stay on DishNet topics** (22 Nov): "why our ai is talking about other topics ?instead of services we provide". The
   live settings text has carried a topic paragraph since 22 Nov; it goes into the code.
10. **The office number when a customer asks for customer care or a call** (23 Nov). The settings text v9 gives it when
    the model answers, but "customer care" and "call me" are answered by a fixed hand-over before the model is asked.
11. **`gpt-transcribe` as the default transcription model** (24 Nov, the owner's choice for the live install).
12. **The AI Knowledge Base screen cannot be opened** (found 23 Nov, while updating the office answer).
13. **A warning when a plan has two prices in uCRM** (found 23 Nov): the assistant quoted the service plan's price while
    the forms refused the plan, because the product's price differed.
14. **The deploy check ME-CA1 read NOT ESTABLISHED twice** (22 and 23 Nov): it looks for log lines master never writes.
15. **The rehearsal harness's `-u` deletes** (found 22 Nov): the reason 5.18.106's record run could not finish.

---

## A. Summary of the design

| Part | What it does | Switch (default off) | New schema |
|---|---|---|---|
| **Q** | the two-option connection quotation built by code from the uCRM catalogue, placed after the model's text when it writes `<<QUOTATION>>`, on WhatsApp (every selling number) and the website chat | `connection_quote_enabled` | — |
| **K** | **on WhatsApp**, a customer with a live quotation gets no prices from the AI, a pointer to the quotation, and an alert to its owner; no AI-written enquiry follow-ups. The website chat cannot know who the visitor is (§E.5) | `quoted_no_prices_enabled` | — |
| **X** | an "assistant off" list of numbers: no AI reply, no follow-up, no offer, no win-back; invoices, receipts and payment reminders still go; the salesperson is alerted | none: an empty, readable list changes nothing. While the list cannot be read, no Uganda customer gets an AI reply (§X.5) | table `wa_no_ai` (092) |
| **F-1** | enquiry follow-ups stored as `DishNet Plugin`, so a follow-up never makes the AI drop a customer's message | none (a defect fix, Uganda) | — |
| **F-7** | a reply blocked by an opt-out is a final, quiet outcome, not a failed send retried through the AI | none (a defect fix, Uganda) | — |
| **F-8** | the assistant's photo and flyer go as replies (`CLASS_REPLY`), so a STOP no longer drops them; Inbox images get the transactional opt-out check before they are sent as staff | none (a defect fix, Uganda) | — |
| **W.1** | one reply to a burst: the newest typed message of a conversation gathers the older unanswered ones (each within 15 minutes of the next) and answers them together | `ai_burst_merge_enabled` (Uganda) | — |
| **W.2** | rule 8, *STAY ON DISHNET TOPICS*, in the absolute rules, on WhatsApp and the website chat | none: Uganda always, no setting can remove it | — |
| **W.3** | the customer-care hand-over also gives the office line from the knowledge base | none (Uganda) | — |
| **W.4** | `gpt-transcribe` as the code's default model; one list of allowed models | none (no effect on the live install) | — |
| **W.5** | the AI Knowledge Base screen gets its own tab id, an admin-only menu link and a redirect after saving | none (a defect fix, Uganda) | — |
| **W.6** | a warning on the uCRM catalogue screen and the KYC form when a plan's service-plan and product prices differ | none (screens only) | — |
| **W.7** | ME-CA1 judges master from its schedule record, not from a log it never writes | none (the deploy script) | — |
| **W.8** | the rehearsal's stand-in docker gives `-u` children the container's PATH | none (the harness) | — |

Build order (§M): F-1, F-7 and F-8 → X → K → Q → W.1 → W.2–W.6. W.7 goes into the deploy script and W.8 into the
harness. The switch-on order (§K): **K before Q**, then W.1.

**Two new `BrainContext` keys** (15 → 17), so that per-turn facts reach the prompt. Today `DishNetAiBrain`'s config is
fixed when the worker is built.
- `quotation` holds `ref` and `valid_until` (Part K). The owner's first name stays in `QuoteInPlay`'s result, for code
  only (§E.1).
- `turn` holds one boolean, `quotation_offer` (Part Q). 5.18.108's Part N adds `lead_ask` to it (docs/77 §G.2).

**Migration 092** creates `wa_no_ai` (Part X) and nothing else. 5.18.108's tables come after it.

---

## B. What the code does today (release/5.18.106)

The map as made on 21 Nov, for both halves of the split. §B.2 (scheduling), §B.5 (the enquiry ladder) and §B.6
(leads and uCRM) serve 5.18.108's parts (docs/77); the week's items are mapped in §W.

### B.1 The AI reply path

1. **The webhook.** `evo_webhook.php`:
   - stores the inbound row first (`:290`);
   - queues `ai.reply` with `received_at` (`:457-478`);
   - spawns `run_worker.php` in the background for every queued text (`:490-516`).

   Master's `ai_reply` job also runs it once a cycle (`cron/master.php:194`, interval 60).
2. **The checks before the model.** `AiReplyWorker::handle()` checks, in order: a colleague's number, DishNet's own
   numbers, the reply route, the automated-send policy, and a colleague's pause, which parks the message
   (`workers/AiReplyWorker.php:189-235`).
3. **The context.** On the sales number, `buildContext()` reads the catalogue through `salesCatalogue()`, whose raw
   `getProducts` rows still carry ids. It then returns `BrainContext::build(...)` (`:619-620`). `build()` keeps only
   `hardware.name` and `hardware.price` (`lib/BrainContext.php:68-71`, `:203-206`): **no id survives into the
   context**. The brain's own config, `flyer_available` included, is set once in the worker's constructor.
4. **The reply.**
   - The worker asks the brain and passes the answer through `guardReply` (`:752`, `:816-901`).
   - It sends it with `CLASS_REPLY` (`:282`).
   - It claims its own echo, then stores it as role `assistant`, agent `DishNet AI` (`:313-328`). `storeMessage()`
     returns the new row's id (`lib/ConversationService.php:740`, `:821`), but `handle()` does not keep it (`:316`).
   - Then come photo, document, flyer (`:333-343`), lead and escalation (`:347-407`), none of which may throw.
5. **The guard.**
   - `guardReply` returns at once when the model's text is empty (`:819`).
   - Otherwise it runs `ReplyPrivacyGuard::check()`. An amount not on the allowlist, a wrong total or a placeholder
     replaces the whole reply with `SAFE_FALLBACK` ("…passed it to our team…") and escalates (`:892-900`).
   - On a pass it appends operator text in code: `PlanFenceGuard`, then `KitTaxNote` (`:851-868`). That appended text is
     not checked, by design (`:842-846`).
   - The allowlist is built inline as an argument to `check()` (`:826`) and is not kept.
6. **When a send may have gone through.** If WhatsApp does not answer, the worker escalates and returns at `:290-297`,
   before the bookkeeping section.
7. **The markers.** `parseMarkers` folds `<<ESCALATE>>` and `<<QUOTE>>` into one `escalate` flag, telling them apart
   only by the reason text (`lib/DishNetAiBrain.php:1907-1921`). It strips every marker and keeps no position.
8. **Nothing records that a reply gave prices.** The brain's answer has no such flag (`:1975-1976`). The flyer, the most
   common price answer when it is installed, is a separate row with `media_url 'flyer:plans'`, stored after the text by
   `maybeSendFlyer()`, which returns nothing (`workers/AiReplyWorker.php:1310`, `:1326-1346`).

### B.2 Scheduling

- **How master runs.** uCRM runs `main.php` every 5 minutes (`manifest.json:19`, `executionPeriod 5`), which runs
  master. An admin page piggybacks at most once every 5 minutes. `cron/master.php:16-18` documents an optional
  per-minute crontab; **whether the Uganda server has one is not established.**
- **No daemon.** `EventBus::emit` has no delay.
- **The consequence:** a job on master's list runs about every 5 minutes. A 4-minute timer cannot ride that cadence alone
  (§C.3).

### B.3 Prices in replies, and the quotation

- **The settings text.** The quotation the owner wants lives only in `bot_custom_instructions`, added after the DATA
  block (`lib/DishNetAiBrain.php:229-241`). That text also feeds the website chat (`web_chat.php:22`, `:270`).
- **Built-in rules that contradict it:**
  - "MONEY IS TWO SEPARATE THINGS … Never blend the two into a single figure" (`:903-905`);
  - "WHAT IT COSTS TO GET CONNECTED … Build it as a list … Then a clearly labelled TOTAL TO GET CONNECTED. Then the
    monthly plan … never inside it" (`:906-913`);
  - "give the total as the sum of the listed prices" (`:928-934`);
  - "quote everything else anyway" (`:935-941`);
  - no charge outside the data (`:942-943`);
  - the prospect rule "ANSWER FIRST. Give the plans from PLANS with their prices" (`:450-454`);
  - "LEAD WITH THE MINI KIT" (`:1105-1109`);
  - an accessory joins TOTAL TO GET CONNECTED only when chosen (`:1671-1675`);
  - replies of 2–5 sentences (`:376`).
- **Where the markers are offered.** `actionMarkers()` offers the selling markers only when `$channel === 'sales'`. With
  `ai_sales_on_all_numbers` (recorded on), the support and accounts numbers load the catalogue and quote prices without
  them.
- **The guard's allowlist** (`workers/AiReplyWorker.php:997-1101`) holds:
  - catalogue prices;
  - subsets of the **first 6** hardware rows, alone or with one or two of the first 24 accessories (`:1015-1036`);
  - 1–12 months of each plan (`:1078-1081`);
  - every word the customer typed (`:1083`);
  - every digit run in the system prompt (`:1094`, gap **F-6**; also `lib/ReplyPrivacyGuard.php:321-325`).

  `ReplyTotals` then checks the arithmetic of TOTAL lines. It reads a total over the lines above it and stops at an
  earlier total. A "First month: …" line with one amount, followed by "TOTAL WITH THE FIRST MONTH:", adds up as one-time
  total + first month (`lib/ReplyTotals.php:156-191`). A label containing "including" is not read as a total (`:37`).
- **So a wrong mount price passes.** Every accessory price is allowed, and so is kit + installation + any one accessory.
  Nothing checks that a line naming an item carries that item's own price.
- **And a total that includes the first month is refused** unless its digits appear in the prompt (F-6): no generator
  adds a plan price to a hardware sum.
- **The customer's uCRM quotation message.** For a quotation made in uCRM, `webhook.php` quote.add sends it with
  `sendVia('support', …, 'ops_quote_created')` (`:3241`). It already prints, in order (`webhook.php:3050-3064`):
  - "One-time";
  - "First month … (then … per month)";
  - one **Total** that includes the first month;
  - `QuoteTaxLine::TEXT`, which ends "…**Nothing is added on top.**" (`lib/QuoteTaxLine.php:23`).
- **The catalogue.**
  - Kits, mounts and plans come from uCRM through `DishNetTools::getProducts` (60 s cache).
  - Accessories are split out by exact name from `assets/shop/catalogue.json`: 22 items, 20 accessories and 2 kits,
    **no installation entry**. `ShopCatalogue::load()` turns any kind other than `kit` into `accessory`, and
    `ShopCatalogue::resolve()` takes the first match (`:119`).
  - **No field pairs a kit with its mount**, and nothing marks a hardware row as the installation in the catalogue.
- **The installation's id.** It is designated by 5.18.102's `UcrmQuoteLinks` `install` link (`ucrm_quote_links.json`,
  `lib/UcrmQuoteLinks.php:41`), judged per quoting organization as `UcrmQuoteLines::installLine()` reads it (`:353`,
  `:357`). 5.18.103's direct-mode `UcrmCatalogue::installProductId()` reads a **different** store
  (`ucrm_catalogue.json`) and is not used here.
- **History.** The AI's earlier replies come back to the model as history, cut to 400 characters. They are not in the
  allowlist.

### B.4 Quotations sent, and quoted customers

- **How a sent quotation is stored.** Every quotation leaves through `NotificationService::sendVia` and is stored with
  its `event_key`, role `agent`, agent `DishNet Plugin` (`QuoteSendEvidence::EVENTS`). It usually lands on the support
  or accounts conversation, not the one the customer writes on.
- **The tracker.** `cron/quote_chase.php` (every 540 s, only while `quote_tracking_enabled` is on) derives `quote_tracker`
  rows (`migrations/089_quote_followups.sql:23-59`; `lib/QuoteTracker.php`), each holding:
  - the quote number, phone and sent time;
  - `valid_until`, a Kampala date; the quotation expires after 20:00 that day;
  - the resolved owner, `owner_staff_id`, by rules 1–7;
  - a status that leaves `open` once.
- **The brain receives nothing about quotations.** `BrainContext` has 15 fixed keys (`lib/BrainContext.php:62-93`). The
  sign-up posture tells the model to restate the plan's exact name and price for a uCRM person with no service
  (`lib/DishNetAiBrain.php:185-203`).
- **Quotation sends never pause the AI.** `NotificationService` claims its echo, and `DishNet Plugin` is not a colleague.
- **Enquiry follow-ups to a quoted customer are written by the AI and checked for nothing but the plan fence.**
  - The drafter (`FollowUpEvaluator`) has its own prompt, whose only price rule is "Never invent a price". It reads the
    thread, which holds the AI's priced replies.
  - `followup_send.php:206-217` runs only `PlanFenceGuard`.
  - R-06, under which the chase supersedes enquiry follow-ups, needs chase rows. Those exist only while
    `quote_chase_enabled` is on.
  - So with tracking on and the chase off (the recorded state since 13 Nov), nothing stops them. → **F-2**.

### B.5 The enquiry ladder

- **The schedule.** `FollowUpPolicy::SCHEDULE = [1 => 24, 2 => 72]` (`lib/FollowUpPolicy.php:57`) counts hours from the
  customer's last message (`last_customer_at`). It is hard-coded, with no setting.
- **The attempt cap.** `FollowUpService::MAX_ATTEMPTS` = 2 (`lib/FollowUpService.php:23`) is written onto each row when
  it opens (`:125`). The gate and the close job read only the row (`lib/FollowUpPolicy.php:328`,
  `cron/followup_close.php:77`).
- **The send floor.** `nextDueAfterSend` puts the next step no earlier than send + (SCHEDULE[n] − SCHEDULE[n−1]),
  which is 48 h today (`:258-275`).
- **The window.** Monday to Saturday, from 08:00 up to but not including 20:00 (`HOUR_OPEN 8`, `HOUR_CLOSE 20`,
  `:53-54`), in the install's zone. A row due outside the window waits for the next opening.
- **The cycle.**
  - `followup_scan` opens a row only after `SCHEDULE[1]` hours of quiet (`cron/followup_scan.php:100`; `isFollowable`,
    `lib/FollowUpPolicy.php:404`).
  - `followup_run` judges the earliest-due rows (`followup_run_limit`).
  - `followup_send` re-checks everything before sending.
  - 5.18.106 allows one follow-up per quiet spell (F4, docs/75 §C).
- **The daily cap.** `followup_daily_cap` counts per channel per **UTC** day (`lib/FollowUpService.php:816-832`). Its
  recorded live value is 3 (docs/75 §A). In record mode, the ledger has no one-per-person rule either.
- **How a follow-up is stored.** An enquiry follow-up is stored as role `agent`, agent **`DishNet`**
  (`cron/followup_send.php:379-381`). → **F-1**.

### B.6 Leads and uCRM

- **How a lead is made.** Only when the model ends a reply with `<<LEAD {json}>>`.
  - The prompt teaches that line only inside `qualification()`, which needs `ai_lead_capture` **and** `ai_qualification`,
    on a selling number, and never in override mode (`lib/DishNetAiBrain.php:1042`, `:1162`).
  - `AiLeadService` refuses a lead unless the customer stated a requirement plus a location, a pin, a customer type or a
    quote request (`lib/AiLeadService.php:71-85`).
  - A lead with no name gets the phone number as its name.
  - A merge fills empty fields only, so a later real name never replaces the number (`:326-334`).
  - `capture()` assigns a lead to the salesperson whose own number it came in on, but only when it creates the lead
    (5.18.89 D5).
- **Assigned leads alert staff.** `cron_lead_alerts` sends a WhatsApp to the agent at once, a warning before the
  deadline, and an escalation to the supervisor at the deadline. A reassignment after 72 hours starts again.
- **Nothing in code asks for a name or an email.**
- **The uCRM sync.** `UcrmLeadSync` runs behind `ai_crm_lead_sync`, off since 7 Oct.
  - It matches a client by the last nine digits of the phone. With two or more clients on that number it creates
    nothing.
  - Otherwise it creates an `isLead` client, with the email in that create payload only.
  - After that it only PATCHes the GPS position (`lib/UcrmLeadSync.php:107-137, 275-344`).
  - It stores the same `crm_client_id` whether it created the client or linked one matched by phone.
  - **All three gaps are confirmed in code.**
- **The lead's created stamp.** `created_at` is written with `date()`, so in the writing process's zone: UTC when the
  webhook spawns the worker (`run_worker.php` sets no zone), the install's zone when master runs it (`dn_tz_apply()`;
  `lib/UtcClock.php:10-12`). The sync's own stamps use `gmdate()`.
- **A synced lead identifies the caller.** A lead client created this way matches the caller on the next turn
  (`identifyCustomerByPhone`: the search index keeps lead clients). The caller then counts as "identified" with no
  service, which switches off `prospectRules`.
- **Custom attributes.** No code mentions `leadSource`. A working pattern exists: find the attribute by key with
  `GET custom-attributes`, then `PATCH clients/{id}` with `attributes: [{customAttributeId, value}]`
  (`lib/UcrmClientTarget.php:205-245`). An unknown id in a create payload fails the whole create.
- **The organization.** It comes from `ucrm_lead_organization_id`, which is not validated. Otherwise, 5.18.99's rule
  files the lead under the quoting organization (`QuoteOrganization`, validated) (`lib/UcrmLeadSync.php:397-499`).

### B.7a Stopping automatic messages to one number (measured 21 Nov)

- **No per-number "assistant off" switch exists.** The only lever on a single chat is a typed staff message, which pauses
  the assistant there for `wa_human_cooldown_minutes` (default 1440, 24 h).
- **`ContactOptOut` has a scope `all`** (`lib/ContactOptOut.php:37-38`, `:88-106`). It blocks every class except staff,
  so it would stop replies but also invoices, receipts and suspension notices (`CLASS_TRANSACTIONAL`).
- **Nothing on screen creates an `all` row.** `add()` has three callers, each with scope `proactive`:
  - the STOP keyword (`evo_webhook.php:368-374`);
  - a STOP heard in a transcribed voice note (`lib/VoiceTranscription.php:233-243`, source `voice_keyword`);
  - the follow-ups screen's not-interested action (`includes/api/api_followups.php:133`).
- **A blocked AI reply is mishandled.** `EvolutionApiService::optOutRefusal` returns
  `['success' => false, 'suppressed' => true, 'error' => …]`, with no `ok` key (`lib/EvolutionApiService.php:99-107`,
  `:636-637`).
  - `AiReplyWorker` tests `!$send['ok']` (`:288`), finds no may-have-been-sent, and throws "Evolution send failed".
  - EventBus retries, asking the model again each time, until `max_attempts`.
  - When the event dies, `onDead` hands the chat over: it sets `needs_human`, emits `wa.escalation`, and sends the staff
    alert through `AlertService::handover`. The holding line is tried, and the opt-out blocks it too.
  - Nothing reaches the customer, but model calls are spent, and the log reads as a failed send. → **F-7**.
- **Images ignore the reply class.** `EvolutionApiService::sendImage` passes no class, so `sendMedia`'s default,
  `CLASS_PROACTIVE`, applies, and an ordinary STOP (scope `proactive`) suppresses it. The assistant's photo and flyer to
  a customer who once wrote STOP and later asks a question are dropped silently, while the text reply goes out and can
  refer to an image never received. Images typed in the Inbox and sent through `NotificationService::sendImage` are
  affected the same way. → **F-8**.

### B.7 Checks the critic made

**Spot-checked and accurate:**

| Map | Lines checked |
|---|---|
| nudge | master.php 194, 238–241, 308–311; AiReplyWorker 97–106, 282, 313–328; ReplyPrivacyGuard 129–133; HumanTakeover 30, 162–165; WaSendBudget 38–48 |
| reminder | FollowUpPolicy 53–57, 189–204, 231–239, 258–275, 311–321; FollowUpService 816–826; followup_scan 76–101 |
| quote | DishNetAiBrain 903–913, 1105–1109, 1671–1675; AiReplyWorker 999–1081; ReplyTotals 31, 37, 40, 60, 301–305 |
| quoted | BrainContext 62–93; DishNetAiBrain 185–203; 089 23–59; QuoteTracker 377–391, 621–637; NotificationService 1982–1990, 2462–2478 |
| leads | AiLeadService 297–334; UcrmLeadSync 275–344; run_worker 33–48; ConversationService 825–836 |

**Corrections taken:**
- `humanRepliedSince` counts only role `agent` rows (`lib/ConversationService.php:965`). A row stored as role
  `assistant` is never a colleague, whatever its label.
- The webhook text is the **customer's** quotation message.

---

## C. Part H — moved to 5.18.108

The "Hi" after a reply that gave prices is in docs/77 §C, carried word for word on 25 Nov. Its gate 9 holds a number on
the "assistant off" list (§X.3).

---

## D. Part Q — the connection quotation, built in code

### D.1 Why code, and how

**The problem.** The model cannot be made to copy the right mount price reliably (§B.3), and the guard cannot tell which
item a price belongs to.

**The rule.** **The model no longer types any figure of the connection quotation.** It writes `<<QUOTATION>>`, and code
appends a block built from the uCRM catalogue after the model's own text.

**The precedent.** `PlanFenceGuard` and `KitTaxNote` work this way already (`workers/AiReplyWorker.php:842-868`):
operator text, made in code from the catalogue only, after the model's text has passed the guard.

### D.2 The builder — `lib/ConnectionQuote.php`

**Its inputs.**
- **The raw `getProducts` rows, which still carry ids** — never `BrainContext::build`'s output, which keeps only name
  and price (§B.1.3). They are kept **on every selling number**, beside the returned context, the way the turn's
  conversation id is kept:
  - **Sales:** `salesCatalogue()`'s result.
  - **Support and accounts, with sales-on-all:** the raw `$ctx['products']` that `loadCatalogue()` loaded
    (`workers/AiReplyWorker.php:531`, `:546`, `:780`), passed through `salesCatalogue()` and kept beside the context.
    **It is never written back into the legacy `$ctx['products']`.** `BrainContext::catalogue()` adds `stock` and drops
    accessories, so writing it back would change those numbers' prompts, South Sudan's included.
  - **The website chat:** `web_chat.php` reads `$_catalogue`, not `$ctx['products']`.
  - The offer flag (§D.4), the builder in `guardReply` and §D.6's figures all read the same kept rows.
- **The plugin store**, for the installation link.
- **The config.**

**Pairing kits with their mounts and plans.** This is done in `assets/shop/catalogue.json`, which is reviewed with the
code. `load()` today drops unknown fields (`lib/ShopCatalogue.php:25-51`); it is taught to keep these:

| Entry | Fields |
|---|---|
| the Standard kit | `"option": "standard"`, `"mount": "<exact name of Pivot Mount \| Standard 4 and 4 X, Enterprise>"`, `"plan": "<exact name of Residential (up to 350 Mbps)>"` |
| the Mini kit | `"option": "mini"`, `"mount": "<exact name of Pivot Mount \| Mini>"`, `"plan": "<exact name of Residential Lite (up to 100 Mbps)>"` |

The exact live names are measured before the build (§N). The fixture from the live logs names the Mini kit "Starlink
Mini Kit + Mini Router", which today's kit match list does not contain.

**The installation.** Exactly one record is used:
1. **The record:** 5.18.102's `UcrmQuoteLinks` `install` link, judged `ok` for `QuoteOrganization::setting()`'s
   organization, exactly as `UcrmQuoteLines::installLine()` reads it (`lib/UcrmQuoteLines.php:353-357`).
2. **The match:** its product id is matched against a raw hardware row's `id`. This keeps the operator's rule "never a
   name". This is the record §N measures.
3. **The fallback**, if §N shows the link not `ok` on Uganda: a **top-level** `"installation": "<exact uCRM name>"` field
   in `catalogue.json`. It is never an item entry: `load()` would turn an item into an accessory. Which of the two is
   used is fixed at the build.

**Exactly one row must match, for every item it prices.** Zero, or two or more, makes the builder return `null` with the
reason in the log, and the offer flag is not set. Never `ShopCatalogue::resolve()`'s first-wins lookup.
- **each kit:** across every spelling in its match list, compared by `nameKey`, in the hardware rows;
- **each mount:** in the accessory rows;
- **each plan:** among the active service plans;
- **the installation:** by id, or by the exact fallback name.

**Its output, for one option**, all figures taken from the rows and never typed:
- the kit, installation and mount rows, each with its own price;
- the one-time total;
- the plan's monthly price;
- the total with the first month.

**Any missing or unpriced item, the mount included, is a refusal.** A total that leaves the mount out would read as the
whole cost of getting connected, which is less than the real cost. The owner asked for the mount inside that total.

### D.3 The block

Uganda wording; currency and digits formatted as `webhook.php` formats them.

```
*Option 1: <Standard kit name> with <Residential plan name>*
- <kit name> — UGX <price>
- <installation name> — UGX <price>
- <Standard pivot name> — UGX <price>
*TOTAL TO GET CONNECTED WITH INSTALLATION: UGX <one-time sum>*
First month: UGX <plan price>, then the same price per month
*TOTAL WITH THE FIRST MONTH: UGX <one-time sum + plan price>*

*Option 2: <Mini kit name> with <Residential Lite plan name>*
(the same five lines)

Transport is charged extra.
<the block's tax line>
<closing question>
```

- **It matches the uCRM quotation.** A first month next to a total that includes it mirrors the customer's uCRM
  quotation message (`webhook.php:3050-3064`). The first-month line carries one amount, so `ReplyTotals` can read the
  last total as one-time total + first month (§B.3).
- **The tax line** is `connection_quote_tax_line`:
  - Its default is "Kit prices include all taxes — URA taxes and the UCC registration fee are already in them." It never
    says "Nothing is added on top" under the transport line.
  - It contains both "UCC" and "tax", so `KitTaxNote::apply`'s own-words check (`lib/KitTaxNote.php:82-84`) adds no
    second note.
  - The owner's live `ai_fact_kit_taxes`, set on 20 Nov, already reads the same. §N re-reads it, and decision O7 covers
    the phrase everywhere else (answered 25 Nov: drop it from all five places).
- **Which form.** Both options are always given, in the recorded order. A customer who has already chosen gets the form
  of decision **O11**: both options in full, Standard first (answered 25 Nov), and only `<<QUOTATION>>` exists.

### D.4 The offer, the placement, the website

**The offer flag.** `turn.quotation_offer` is a per-turn context leaf, never config.
- **Computed once** in `buildContext()`, **before** the channel branch at `workers/AiReplyWorker.php:617`.
- **Written into `$ctx['turn']`**, so the legacy context that support and accounts return at `:641` carries it, as
  `quotation` does (§E.2.1).
- **On sales**, passed as `'turn' => $ctx['turn']` into `BrainContext::build`'s input.
- **In 5.18.108, Part N's `turn.lead_ask` travels the same way** (docs/77 §G.2).

It is set when all of these hold:
- the switch is on;
- the install is Uganda;
- `ai_hardware_expert` is on (accessories reach the context only then, `lib/BrainContext.php:269-276`);
- the number sells, by the selling predicate, one helper that 5.18.108's Part N will share (docs/77 §G.2):
  `channel === 'sales'` or `ai_sales_on_all_numbers`;
- when Part K's switch is on, the customer has no live quotation and the answer is not `unknown`;
- the builder returns both options from this turn's raw rows.

**Where the prompt offers it.** `actionMarkers()` takes the context and offers `<<QUOTATION>>` only when the flag is
set. The offer sits **outside** its `$channel === 'sales'` branch, so support and accounts with sales-on-all get it too.
`actionMarkers()` is called by both the append and the override prompts (`:566`, `:611-618`).

**Parsing.** `parseMarkers` returns `'quotation' => true|false`. The name joins the safety-net list (`:1961-1963`).

**Placement on WhatsApp.**
1. **Before the early return.** In `guardReply`, **before** the early return at `:819`, it first reads the turn's
   `QuoteInPlay` answer.
2. **Live quotation, Part K on.**
   - The marker is dropped and the builder is never called.
   - `quoted_reply_text` takes the reply's place when both of these hold:
     - the remaining text is empty, or is only `parseMarkers`' holding line (its new `filled` flag, §E.2.4);
     - the parsed reply set **no escalation, or set it only through `<<QUOTE>>`**: `escalate` is false, or
       `quote_requested` is true and `escalate_requested` is false.
   - The §E.2.5 alert is then sent, and the turn counts as a Part K refusal. This covers `<<QUOTE>>` alone and
     `<<QUOTE>><<QUOTATION>>`. Those marker turns never take the empty-reply path at `:274-278`.
   - **Any other empty reply that carries `escalate` keeps today's hand-over**, with the holding line and no Part K alert:
     the brain's `handover()` for a provider failure or an empty model answer, or an external ShopBot hand-over.
3. **The flag is unset** (Part K reported `unknown`, the switch is off, South Sudan, or the marker was not offered). The
   marker is dropped, as today's generic strip does, and neither the builder nor step 5's colleague line runs. A reply
   that is then empty takes today's empty-reply path. This is today's behaviour, not a new price rule.
4. **Otherwise (the flag is set).** The block is built:
   - when the model's text is empty, nothing of the model's is checked, and the block is the reply;
   - when the model also wrote text, the guard checks that text as today, and the block is appended after it, before
     `PlanFenceGuard`.

   Text after the marker cannot be told apart from text before it, because `parseMarkers` keeps no position. So the
   prompt asks for the marker to come last (§D.5).
5. **If the builder refuses at reply time** (the 60 s cache changed): the reply ends with "A colleague will send you the
   full quotation shortly.", and the turn escalates. No half-built quotation is ever sent.
6. **The cap.** The 1,200-character cap applies to the model's text only (`lib/DishNetAiBrain.php:39`).

**The website chat** (`web_chat.php`):
- **The flag** is set in the context it builds at `:377`, by the same conditions **except Part K's**. The visitor is
  anonymous and has no phone, and the lead form's phone is typed by the visitor and unverified. It is **never** used to
  look up a quotation: that would show a quotation number and a salesperson's name to anyone who types a phone.
- **The block** is built right after `$reply = trim(...)` at `:399`, **before** the empty-reply check at `:401`.
  - It is built only when the flag is set.
  - It reads `$_catalogue`, so the flag and the builder see the same rows.
  - A marker-only reply then reaches the normal store and escalation path (`:409-436`) instead of the bail.
  - With the flag unset, the marker is stripped as today and nothing else changes.
- **A refusal at reply time.** The website's own line points the visitor to WhatsApp, and `$result['escalate']` is set.
  The widget cannot receive a colleague's message, so "A colleague will send you…" is not used there.

### D.5 The prompt (Uganda, only while `turn.quotation_offer` is set)

**Added, in `actionMarkers()`:**
> When the customer asks what it costs to get connected, for the price of a kit with installation, or for a quotation for a new connection: write one short sentence, then `<<QUOTATION>>` as the last thing in your reply. In that reply never type a kit, installation, mount, plan or total figure yourself and ask no question of your own: the system adds the options, the totals and the closing question. When the customer later asks about those totals again, write `<<QUOTATION>>` again rather than repeating a figure. In that reply this holds whatever else these instructions say, including anything DishNet adds: no price figure of any kind, and no question of your own, including a request for a name or an email.

**Replaced for this case:**
- the build-it-yourself sentences (`:906-913`);
- "give the total as the sum" (`:928-934`);
- "quote everything else anyway" (`:935-941`);
- the prospect rule's "ANSWER FIRST. Give the plans … with their prices" (`:450-454`), for the quotation question only;
- "LEAD WITH THE MINI KIT" (`:1105-1109`);
- the accessory-only-when-chosen rule (`:1671-1675`).

**Kept**, as binding the model's own text: "Never blend the two into a single figure" (`:903-905`). Only code writes the
total with the first month.

**The owner's Extra instructions still follow the marker line.** In append mode they come last (`:240`, after `:223`);
in override mode they follow `nonNegotiable()` and precede the data block (`:236-237`). The flag does not replace them.
So when the switch goes on, v9's QUOTATIONS paragraph (live since 23 Nov) must be rewritten at the same time, together
with the DATA PLANS and NAME AND EMAIL edits (§K step 2, v10). Otherwise it would tell the model to type the figures the
marker line forbids. The marker line also carries its own precedence sentence, scoped to the reply that carries the
marker. It holds only bans (no price figure, no question of its own), so outranking the built-in rules costs nothing
there. It covers v9's DATA PLANS paragraph (plan prices) and NAME AND EMAIL paragraph (a question at about message 4–5),
which lie outside the paragraph v10 rewrites. NAME AND EMAIL stays until Part N removes it in 5.18.108.

**When the flag is unset, and on South Sudan,** every sentence stays byte for byte.

### D.6 What the guard does now

- **The model's own text** is checked exactly as today.
- **The block's printed figures become permitted, as a separate list:**
  - **Which figures:** the one-time sums and the totals with the first month.
  - **When:** only while the flag's conditions hold and the builder returns both options for this turn.
  - **Why:** the block comes back as history, cut to 400 characters. A later turn that repeats one of its figures would
    otherwise be refused, and the customer would get `SAFE_FALLBACK` and a hand-over.
  - **What changes and what does not:** the list is added to the guard's `values`, taken from the builder's output; in
    5.18.108 Part H's priced test reads it too (docs/77 §C.1). `permittedAmounts()` is not changed in 5.18.107.
    `permittedPrices()` does not exist yet: 5.18.108 creates it by splitting it out of `permittedAmounts()` (docs/77
    §C.1). The allowlist widens by exactly those figures.
- **The root `scripts/lib/ai_check.php`** gets the same list through an optional parameter, so the read-only check tool
  reports what the guard does.
- **Not in this release:** a name↔price check (§P).

### D.7 Settings

| Key | Default |
|---|---|
| `connection_quote_enabled` | off; **needs** `ai_hardware_expert` on (§D.4, read in §N), and `ai_sales_on_all_numbers` for numbers other than sales |
| `connection_quote_transport` | `Transport is charged extra.` (empty omits the line) |
| `connection_quote_tax_line` | "Kit prices include all taxes — URA taxes and the UCC registration fee are already in them." |
| `connection_quote_closing` | `Which option suits you better?` |

---

## E. Part K — a customer who already has a live quotation (WhatsApp)

### E.1 "Live quotation" — `lib/QuoteInPlay.php`

**The lookup.** `QuoteInPlay::forPhone($pdo, $config, $dataDir, $phone, $now)` returns one of:
- `{ref, valid_until, owner_staff_id, owner_source, owner_first_name}`;
- `null`;
- `unknown`.

It runs once per turn, and only while `quoted_no_prices_enabled` is on. Its answer is used by Part K, by Q's offer
(§D.4), and in 5.18.108 by H's gate 8 and N's gate (docs/77 §C.4, §G.2).

1. **From the tracker.** It reads `quote_tracker` by **exact phone** (`idx_qt_phone`), newest first. A quotation is live
   when all of these hold:
   - its status is `open`, `answered` or `taken`;
   - `valid_until` has not passed (20:00 Kampala that day);
   - it has not converted, re-checked with `QuoteTracker::stop()` and its `$only` argument for `paid`, `registered` and
     `accepted`;
   - it is not superseded by a newer quotation.

   An `answered` row must be judged by `valid_until`, not by its status: `qt_status_once` stops it from ever becoming
   `expired`.
2. **The lag.** A quotation sent in the last 48 h that the tracker has not derived yet is found from its stored text on
   any conversation of that phone, through `QuoteSendEvidence::read`. `QuoteChase::holdSets`' `quote_text_48h` already
   does this (`lib/QuoteChase.php:530-603`).
3. **On a read error** it returns `unknown`, and the reply path behaves as today. The error is logged without the phone.

**The owner** is the quotation's owner under rules 1–7 (docs/72 F4): the KYC salesperson, then the lead's assigned
salesperson, then the sender. That is how "the salesperson who sent it" is read (decision O10, answered 25 Nov).
- `owner_first_name` comes from `retailers.json`: letters only, at most 30 characters, absent when the owner is pending
  or none or has no name. It is used only by code, `quoted_reply_text`'s `{salesperson}` and the alert, and never
  reaches the prompt (§E.2.1).
- `line_owner` never stands in for it.

### E.2 In the reply path

1. **The prompt.** A `QUOTATION ALREADY SENT` block comes from a new helper, `quotedRules($ctx)`. It is called both from
   `buildSystemPrompt` (append) and from `nonNegotiable()` (override). The block tells the model to:
   - answer general questions;
   - never state a price, a total or a monthly figure;
   - for any price, discount or "how much" question, say that quotation `<ref>` (valid until `<date>`) has the prices and
     that our sales team will help. It names no salesperson: absolute rule 4 forbids staff names, and rule 4a's only
     exception is the line owner's own name (`lib/DishNetAiBrain.php:323`, `:330-334`). The salesperson is named only in
     code, by `quoted_reply_text`'s `{salesperson}` and the alert.

   It also says, in its own words, that it overrides the sign-up posture's restate rule (`:185-203`). It closes with two
   sentences, which name no heading so that they are true in append and override mode alike:
   - a precedence sentence on the price ban only: "No other instruction, built in or added by DishNet, lifts this:
     state no price, total or monthly figure.";
   - then one that claims no precedence: "Answer the rest of their question about DishNet within your other rules,
     rules 1 to 8 included."

   The ban outranks the built-in rules on purpose, unlike rule 8: rule 3 ("Restate our listed price") and the sign-up
   posture tell the model to state a price. The answering duty stays outside the precedence sentence, so it never
   outranks rule 8's topic limit, rule 1's "say you will check" or rule 5's hand-over. The settings text follows both
   blocks in either mode: last in append mode (`lib/DishNetAiBrain.php:240`), and in override mode after
   `nonNegotiable()`, which carries this block, and before the data block (`:236-237`). v9's DATA PLANS paragraph lists
   monthly prices with no condition, so without the sentence §K step 1 (K on, v9 live) would leave the model two
   contradicting instructions.

   `actionMarkers($ctx, …)` offers no `<<QUOTATION>>`, `<<FLYER>>` or `<<QUOTE>>` while `quotation` is set. Support and
   accounts get the same key in their legacy context.
2. **In code, on every non-empty reply that leaves `guardReply`**, including the marker-only branch of §D.4, for a live
   quotation (not `unknown`):
   - `send_flyer`, `photo` and `doc` are set false;
   - the quotation marker is ignored;
   - **the floor** runs on the final text, after every code-appended block.
3. **The floor.** It replaces the **whole** reply with `quoted_reply_text` when the final text has any of these:
   - any amount (`amountsIn($reply, true)`);
   - a number followed by "million" or "thousand";
   - a number with a bare "m" or "k" next to a currency word (UGX, USh, Shs, shillings), or directly after a decimal
     amount ("2.6m");
   - a figure next to a currency word;
   - or a TOTAL label.

   **What it skips**, so a quoted customer still gets general answers:
   - the digits of the quotation number and of dates;
   - **whole tokens** (amounts or phone shapes) of at least 5 digits that appear verbatim in `ai_fact_payment` (with the
     `PaymentOptions` sentence it carries), `ai_fact_office` or `ai_fact_location_pin`, read by key, or in the
     `OFFICE_LOCATION` row's `wa_answer` that W.3 appended this turn (§W.3). **Never** a figure from `ai_fact_delivery`,
     `ai_fact_prices`, `stock_statement`, `ai_fact_business_cap`, the kit note or the unlimited fact; never a short
     digit run; never a size-word or currency-word match;
   - a phone-shaped run **only when its digits equal a known number**: the customer's own, one in the payment or office
     facts or in that `wa_answer`, `quote_company_phone`, or a staff or office number the prompt states. The phone
     pattern comes from a new public method, `ReplyPrivacyGuard::phoneSpans(string $text)`. It reads
     `self::IDENTIFIER_SHAPES['phone']`, as `amountSpans()` reads its own constants, and returns the matches with their
     offsets. `IDENTIFIER_SHAPES` stays byte for byte the same, so the r107 hunk is one added method and `check()` is
     unchanged by construction;
   - a number-plus-unit run (the space optional, so "15m" equals "15 m") that appears in this turn's hardware, accessory
     or network text, unless it sits next to a currency word;
   - on the accounts number, the account block's own values: balance, latest invoice amount, last payment.

   When it fires, flyer, photo, document and lead are dropped (`:892-900`), and an audit event is stored (metadata
   only).
4. **`<<QUOTE>>` no longer escalates a quoted customer.**
   - `parseMarkers` returns three new flags beside the unchanged `escalate`:
     - `quote_requested`: only from `<<QUOTE>>`;
     - `escalate_requested`: only from `<<ESCALATE>>`;
     - `filled`: true only when the model's text was empty and the holding line "Let me get someone from the team to help
       you with this." was put in its place (`lib/DishNetAiBrain.php:1971-1972`).

     South Sudan ignores all three.
   - For a live quotation with the switch on, `escalate` is cleared **only when `quote_requested` is true and
     `escalate_requested` is false**, and the alert below is sent instead. The reason text is never read for this.
   - **When `filled` is true and `escalate_requested` is false**, the holding line is replaced by `quoted_reply_text`.
     The turn counts as a Part K refusal (§D.4 step 2), so a quoted customer never gets the holding line without a
     hand-over behind it.
   - A guard block returns its own `escalate` and still hands over.
   - South Sudan, and the switch off, behave as today.
5. **The alert.** For a live quotation, the owner is told when any of these holds:
   - the floor fired;
   - `quote_requested` is set;
   - the reply sent names the quotation number;
   - the reply says the sales team will help: the phrase "sales team" or the word "salesperson", ignoring case. No
     other wording counts, so the fixed `human_ask` hand-over, the holding line and `ReplyPrivacyGuard::SAFE_FALLBACK`
     do not match it.

   **On a turn that keeps today's hand-over** (`escalate` kept, not set only through `<<QUOTE>>`) and whose text the
   floor did not replace, no Part K alert is sent; the hand-over's own alert stands. This is §D.4's rule for empty
   replies, stated here for every hand-over: the `human_ask` turn (§W.3) and a guard block alike.

   **How it is sent:**
   - `AlertService::notifyTo(<owner's phone>, 'quoted:<ref>', <text>, cooldown)`;
   - the owner's phone comes from `owner_staff_id` → `retailers.json` → `StaffDirectory::phoneOf`, as
     `QuoteDailyList::scope` does;
   - when the owner is `pending` or `none` or has no phone, it goes to the central `alert_whatsapp`;
   - the cooldown is `quoted_alert_cooldown_hours` (6), per quotation.

   **Never `Handover::escalate`.** It would set `needs_human`, mark the quotation `taken`, close the chase and stop
   follow-ups for good.

### E.3 In the follow-ups (fixes F-2, under the same switch)

- **The scan.** The scan's SQL leaves out a phone with a live tracker row, beside `$quoteSql`, only where the table
  exists:

  `AND NOT EXISTS (SELECT 1 FROM quote_tracker t WHERE t.phone = c.phone AND t.status IN ('open','answered','taken') AND datetime(t.valid_until || ' 17:00:00') >= <now UTC>)`

  17:00 UTC is 20:00 Kampala. This is stricter than §E.1: a converted quotation is held back until its `valid_until`,
  which is accepted. `QuoteInPlay::forPhone` runs in PHP only as a backstop, for the 48-hour lag.
- **The sender.** `followup_send` closes an enquiry draft to a phone with a live quotation
  (`superseded: a quotation is in play`), before the window and the ledger.
- **The quotation chase** is unchanged.

### E.4 Settings

| Key | Default |
|---|---|
| `quoted_no_prices_enabled` | off; **needs** `quote_tracking_enabled`, which the ladder tool reports |
| `quoted_reply_text` | "Your quotation {ref} has all the prices, valid until {valid_until}. {salesperson} will help you with any change — I have let them know." |
| `quoted_alert_cooldown_hours` | 6 |

### E.5 The website chat

The visitor is anonymous, so Part K cannot apply there. A customer who already holds a quotation can still get prices
on the website chat, as today, now with totals built by code (§P).

---

## F. Part S — moved to 5.18.108

The same-day reminder is in docs/77 §F.

---

## G. Part N — moved to 5.18.108

Name and email after N messages are in docs/77 §G.

---

## H. Part L — moved to 5.18.108

Leads and uCRM are in docs/77 §H.

---

## X. Part X — numbers the assistant never answers (owner, 21 Nov)

**What the owner chose:**
- "AI replies + follow-ups": the assistant never answers a number on the list, never follows it up, and never sends it the
  "Hi". Invoices, receipts and payment reminders still go. The team sees the messages in the Inbox as usual.
- "Yes, alert": when a listed number writes in, its salesperson is told.

Today the nearest thing, `ContactOptOut`'s `all` scope, would also stop invoices and receipts (§B.7a), so it is not
reused.

1. **The store** — `wa_no_ai`, migration 092, created as 089–091 are:
   - `id`, `phone`, `reason` (optional, at most 200 characters);
   - `added_by`, `added_at`, `active`, `lifted_by`, `lifted_at`.

   **The phone form:** `PhoneNumber::international($raw, TenantProfile::current(...))` with the `+` removed, the same form
   as `WaSendBudget::phone` and `OfferAudience::digits`, unique among active rows. An entry the converter cannot turn into
   a number is refused, never stored as raw digits.
   - **One more check, in the list's own converter** (not in `PhoneNumber`, which other callers share). `withDialCode`
     keeps an international entry as typed (`lib/PhoneNumber.php:53-55`), so "+, country code, 0, national number" would
     be stored with the trunk 0 inside and never match. So, on the result:
     - the tenant's dial code, then `0`, then exactly `nsnLength()` digits: the `0` is dropped;
     - the tenant's dial code followed by anything other than exactly `nsnLength()` digits: refused with a message;
     - another country's number is kept as typed.
   - The stored form is shown back on the screen after saving.
   - So a number typed with a leading 0, with `+` or `00`, or with the trunk 0 after the country code, matches what the
     webhook sees.

   The list covers every channel. A row is never deleted, only lifted, so the history stays.
2. **Who changes it:**
   - **Engage → WhatsApp → AI setup** gets a list with add (phone, optional reason) and remove. The screen is admin-only
     and CSRF-checked.
   - **Each Inbox chat** gets a toggle, "Assistant off for this number" / "Assistant on".
     - It is checked against the same role rule as sending, and it also needs a request check of its own:
       - Inbox actions are JSON API calls answered before the global CSRF gate. The session cookie is `SameSite=None`
         (`public.php:57-62`). The API answers `Access-Control-Allow-Origin: *`, reads any POST body as JSON
         (`includes/api_handlers.php:2-8`), and falls back to the browser session when there is no Bearer token
         (`:76-83`). The Inbox never has one: nothing sets the `hybrid_token` cookie it reads
         (`tabs/engage/wa_inbox.php:234`).
       - `csrfToken()` is no protection here. It is one daily value for everyone, printed on the public login form
         (`public.php:90-95`, `:2074-2076`), so another site can fetch it and send it.
       - So the toggle needs a **token bound to the session**: 32 random bytes in `$_SESSION`, made by one helper that
         creates the token if it is absent. The Inbox page and the AI setup screen both call it when they render, and
         print it into the page or form. It is sent with the request and compared with `hash_equals`. Another site
         cannot read either page, so it cannot learn the token.
       - **Every check refuses outright, before `hash_equals`,** when the session holds no token, or the request's token
         is missing or empty. A `?? ''` default on both sides would let an empty field match an empty session.
       - It also refuses a request whose `Content-Type` is not `application/json`, so a form or text/plain post from
         another site is refused before the token is looked at.
     - **The AI setup screen's add and remove** check the same session token, printed into their forms. Today they pass
       only the global gate, which checks the same daily `csrfToken()` and so has the same exposure as every form.
     - The toggle stores the conversation's phone only after passing it through the same converter.
     - **It needs evidence that the phone is the person's real number.** It is not shown, and is refused on the server,
       for:
       - a website chat (channel `web`, or a phone starting `web:`);
       - a chat whose `evo_jid` holds `@lid` and whose phone equals the digits of that `evo_jid`;
       - any other chat whose phone has not been seen as a webhook sender, that is as `customer_phone` on an `ai.reply`
         or `ai.voice_ack` event (`evo_webhook.php:434`, `:468`). The webhook never keys a conversation by an `@lid`
         identity (`:241-245`), so that is proof of a real number.
     - `evo_jid` alone is not evidence: only the manual "load chats" action writes it, while `importEvoMessage` (run by
       `cron_evo_sync`, the fetch and the lazy fetch) keys an `@lid` chat by its digits and leaves `evo_jid` empty.
     - Done events are pruned after 30 days (`workers/MaintenanceWorker.php:40`), so an older chat can be refused too.
       The refusal says why, and staff type the real number on the AI setup screen.
   - **Every change records who and when.** The log line names the row id, never the phone.
3. **What stops for a listed number** (Uganda only; an empty, readable list changes nothing; §X.5 when it cannot be
   read):
   - **AI replies.** `AiReplyWorker::handle()` checks the list right after the colleague check and **before the brain**,
     so no model call is spent. The event is settled done, and nothing is sent.
   - **The voice acknowledgement** (`ai.voice_ack`) is skipped the same way.
   - **Voice notes in `MediaWorker`.** `MediaWorker` sends nothing itself: its R4 reply is an `ai.reply`, which the worker
     check stops. But its hand-over rung (R3) and its failure branches call `Handover::escalate`: fetch failure,
     transcription failure, refused route, and a note the queue gave up on (`workers/MediaWorker.php:170, 258, 300, 320,
     450`). That would set `needs_human` and can send the holding line. So the list check sits **inside
     `MediaWorker::handover()`**, the one funnel. For a listed phone it calls no `Handover::escalate`: no `needs_human`, no
     `wa.escalation`, no holding line. The machine transcript is kept for staff, so the team sees the note as usual.
   - **The "Hi"** (5.18.108): its gate 9 holds a listed number (docs/77 §C.4).
   - **Enquiry follow-ups:** the scan's SQL leaves the phone out, and `followup_send` closes an open draft (`cancelled:
     assistant off for this number`).
   - **Quote chase steps:** a quote chase is a follow-up. `QuoteChase::open` and `preSendCheck` hold a listed phone.
   - **Offers and the win-back message** (decision **O13**, answered 25 Nov): both stop. They are automatic marketing
     messages, and the owner named only invoices, receipts and payment reminders as going on. For the win-back:
     - `cron/customer_reminders.php:63` passes its `$config` and `$dataDir` to `WinBack`, whose constructor takes neither
       today (`lib/WinBack.php:21`). Without them `TenantProfile::current()` resolves to South Sudan, and a uCRM phone
       stored in local form would get the wrong country code and never match.
     - The new parameters are trailing and optional: `array $config = [], ?string $dataDir = null`. The list is read
       through the store's PDO, so a caller that passes no tenant still runs, and with the list empty nothing changes.
     - `WinBack::run` turns the uCRM contact phone into the list's form after reading it (`:55`), with
       `PhoneNumber::international($phone, TenantProfile::current($config, $dataDir))`, before `dedupMark` (`:59`).
     - It skips a listed number, counted as skipped, without marking `WB<id>`. While the list holds any active row, a
       phone the converter refuses is held the same way, never sent. With the list empty, nothing changes.
   - **The old auto-reply service** (`WaAutoReplyService`, through `WaMessageProcessor`):
     - It answers only when `wa_bot_enabled` or `wa_auto_reply_enabled` is on (§N). Its cron entry, `cron_wa_sync`, cannot
       reach it in 5.18.106: `WaInbound` is used at `:176` and loaded only at `:195`, so every row throws, is caught at
       `:229`, and the cursor still advances. Its only live entry is the public `wa_webhook.php` route.
     - If §N shows either switch on, or that webhook open with something posting to it, the list check goes into
       `WaMessageProcessor::process()` before `:123`, which covers both entries.
     - Whoever fixes `cron_wa_sync`'s load order must add the check first.
   - **Still sent:** invoices, receipts, payment reminders, and any message staff send or trigger. That covers KYC and
     quotation messages sent by staff actions through `NotificationService`, and messages typed in the Inbox.
4. **The alert**, when a listed number writes in:
   - **Where it is raised.** In `evo_webhook.php`, after step 8a2 and before 8b2 and the empty-text skip, behind
     `StaffJobsGate::applies`, so South Sudan is unchanged. Every Uganda inbound from a non-staff number passes there:
     captionless photos, documents, voice notes at any rung, messages to a number whose assistant is off.
     - The webhook emits a small event, `wa.no_ai_alert`, only when it read the list and found the number. A read error
       there emits nothing and writes one log line without the phone; the worker's own checks (§X.5) cover the replies.
     - The event counts for the worker spawn, is added to the Uganda list at `cron/event_processor.php:63`, and is
       consumed by `AiReplyWorker` (`getEventTypes`, `:103`).
     - **It is routed at the top of `handle()`, beside `ai.voice_ack`**, before the required-fields check, to its own
       handler. Otherwise it would be acked and dropped there for having no `message`. Its payload is `channel`,
       `customer_phone` (the key `ai.reply` and `ai.voice_ack` use), `conversation_id` and the list row id.
     - The worker's own check in `handle()` stays as a backstop for `ai.reply` events queued before the deploy. Both use
       the same key and cooldown, so they never double up.
   - **Who it goes to,** by `AlertService::notifyTo`, the first of:
     - the line owner, when the customer wrote to a salesperson's own number (`LineOwner::of($this->evo->channelContext($channel), …)`,
       with the registry on). It is resolved where the alert is handled, because `$this->lineOwner` is still null at the
       list check (`AiReplyWorker.php:175` vs `:215`);
     - the lead's assigned salesperson, by phone;
     - otherwise, the central `alert_whatsapp`.

     The quotation's owner is not used: it needs Part K, which may be off.
   - **The key and cooldown.** The key is `no_ai:<row id>`, and the cooldown is `no_ai_alert_cooldown_hours`
     (default 3).
   - **The watchdog leaves listed numbers out.** `cron/wa_watchdog.php` pages the central number about every customer
     message left unanswered for `alert_patience_minutes`. It drops rows whose phone is on an active list row, next to
     its `senderClass` filter (`:77`), on Uganda only. Otherwise every unanswered message from a listed number would page
     the central number too, against "at most once every few hours" and "the central number only when there is no
     salesperson". **When the watchdog cannot read the list,** it filters nothing and pages as today: paging staff is not
     messaging a customer, and while the list is unreadable it is the remaining page for every unanswered customer.
   - **Never `Handover::escalate`:** a hand-over would set `needs_human` and close the chase.
5. **When the list cannot be read**, the rule is to fail towards fewer messages, and never towards a silent drop. **The
   blast radius:** while the list cannot be read, the worker cannot tell a listed number from an unlisted one. A missing
   table (a 092 that failed to apply: `MigrationRunner` logs and never throws, `lib/SqliteStore.php:247`), a corrupt
   store or any other read error therefore stops AI replies to **every** Uganda customer. Each `ai.reply` dies after
   about 36 minutes of backoff (up to about an hour without spawns) with no hand-over. The staff signals are the
   `onDead` alert below, the watchdog (§X.4), and, if `whatsapp_admin_phone` is set, the dead-letter alert
   (`cron/event_processor.php:296-321`). This is why the deploy script checks 092 (§M).
   - **Never message the customer while the list is unreadable.** No model call, no AI reply, no voice acknowledgement,
     no `Handover::escalate`.
   - **A read error retries without a model call, and the retry is counted:** the worker throws before the brain, so the
     event's attempts go up and it reaches `onDead`. Never `WorkerDefer`: `EventBus::defer()` puts the event back to
     pending without counting an attempt, so a list that stays unreadable would park every message for ever, with no
     reply and no alert.
   - **`AiReplyWorker::onDead` re-checks the list before `escalate()`.** If the number is listed, or the list is still
     unreadable, there is no hand-over: one staff alert goes to the central number and one log line is written without
     the phone. The alert's key is `no_ai:<row id>` for a listed number, and `no_ai_unreadable:conv:<id>`, one per
     conversation, while the list cannot be read, so every waiting customer is reported; the cooldown is
     `no_ai_alert_cooldown_hours`. Only a readable list without the number keeps today's hand-over.
   - **`onDead` returns for `wa.no_ai_alert`** before the re-check and before `escalate()`, as it does for
     `ai.voice_ack`. The branch sits after `$p = …` and before the `customer_phone` return
     (`workers/AiReplyWorker.php:1516-1521`), so the voice-acknowledgement mutants' anchors stay intact; the new re-check
     reads `customer_phone`, after that return. A dead alert event writes one log line without the phone and sends at most the alert itself, under
     the same `no_ai:<row id>` key and cooldown, so it cannot double one that already went. It never hands over, even if
     the number was lifted meanwhile.
   - **`MediaWorker::handover()` with an unreadable list** calls no `Handover::escalate` and sends no holding line. In the
     same call it sends one staff alert to the central number through `AlertService::notify`, keyed per media row
     (`no_ai_unreadable:media:<id>`), and writes one log line with the media row id and without the phone. This cannot
     be left to a retry: at most rungs the row is settled before `handover()` runs (R3 sets `understood`, a refused route
     `skipped`, the queue's give-up `dead`), so a retried event would stop at the duplicate check.
   - **The senders hold:**
     - in 5.18.108, the "Hi"'s gate 9 gives `no_ai`;
     - the scan's SQL fails as a whole;
     - the quote chase, the offers and the win-back hold the number.
6. **Settings:** `no_ai_alert_cooldown_hours` (3). The list itself is the switch.

---

## W. The week's items (owner, 22–25 Nov)

Eight items found or asked for between the 5.18.106 deploy and the split. Each was mapped read-only on 25 Nov against
`release/5.18.106` (bf29cbd) by two readers. The exception is the two root scripts W.7 and W.8 cite, which bf29cbd does
not contain: they were read on the development branch at c0d0868 (they last changed in 745e668 and 7408a70). Every
citation below is theirs. The section was reviewed twice (reviews 7 and 8, §R.9, §R.10); review 8's changes are not
yet reviewed (§R.7). Each item is Uganda only unless it says otherwise; South Sudan stays byte for byte unchanged.

### W.1 One reply to a burst of messages

**What was seen (22 Nov).** A customer sent two messages a few seconds apart ("Photosynthesis", then "Yes information
about it"). The assistant answered each one separately, with the same answer. The same happened with "Habibi" and "Its
meaning". It is not a double send: each message gets its own turn.

**Why it happens.**
- The webhook stores each inbound row (`evo_webhook.php:290`), then emits one `ai.reply` per message
  (`:460-479`). One Evolution POST can carry several messages, and each gets its own event (`:128-129`).
- The worker handles `ai.reply` events one at a time, under one lock for the spawned and the cron runs alike
  (`workers/WorkerBase.php:62-65`, `:94-99`). On Uganda, `cron/event_processor.php` also claims `ai.reply` events, but
  only while its own gate check reads false, and puts them back to `pending` (`cron/event_processor.php:51-73`,
  `:269-271`). Nothing in `handle()` (`workers/AiReplyWorker.php:170-406`) looks for a
  newer message from the same customer. `answeredMeanwhile` (`:231-234`, `:1457-1463`) looks only for a colleague's
  reply, and the 90-second wait of 5.18.104's F20 (`:241`, `:1681-1703`) is for voice notes only.
- The history is read inside `buildContext` (`:566`): the newest 20 rows of the current epoch and identity, with no
  upper bound at the event's own message (`lib/ConversationService.php:635-683`). So the second event's prompt holds
  both messages and the first reply, and its final user turn is "Yes information about it" again
  (`lib/DishNetAiBrain.php:1728-1757`). The model answers the newest message again.
- `EventBus::consume` orders by priority, then `created_at`, a one-second stamp, with no id tie-breaker
  (`lib/EventBus.php:186`, `:212`). Two events of the same second can be handled in either order.

**The change** (§K step 3). Two gates, and they gate different things:
- **The Uganda gate** comes first: `StaffJobsGate::applies($config, $dataDir)`, memoized and false on any doubt, the gate
  `voiceUg()` already evaluates (`workers/AiReplyWorker.php:1638-1649`). On South Sudan nothing below runs and no burst
  query is issued, whatever its configuration holds.
- **`ai_burst_merge_enabled`** (off by default) gates only the writes: step 2's mark and step 3's claim. Step 1 and step
  3's collection run whenever the Uganda gate holds. A message marked while the switch was on is therefore still
  gathered after the switch is turned off. This is the rule R6F-02 set for Part L: only creation obeys the switch, and
  the rest follows the stored marker.

Every step applies only to a **typed** event (`origin` is not `voice`, `created_by` is `evo_webhook`) on a conversation
with an id above 0. Conversation 0 is never checked: every message whose store failed shares it (`evo_webhook.php:464`).

**The mark** is the text `superseded by #<id>` in `events.error`.
- It is added after any note already there, separated by ` · `, so F20's note stays at the front and `VoiceSteps` still
  counts it (`lib/VoiceSteps.php:512`).
- It is read by parsing every `superseded by #(\d+)` in `error`, taking the digits up to the first character that is
  not a digit, and comparing the integer with an event id. Never `LIKE`, `instr` or a prefix test, which would let `#1`
  match `#12`. Neither the end of the string nor ` · ` is a terminator: `releaseStale` can append
  ` [stale lock released]` after a mark (`lib/EventBus.php:330-344`).
- Only two writers ever add one: step 2, on its own row just before it returns, and step 3's claim.

**One owner per burst: the newest message.** The handler that will ask the model decides which messages its reply
covers, and marks each of them before it asks. An older message steps aside only for a newer one that has not run yet,
so it never relies on a newer message that ran without its text.

**A message that asks for a person is never merged.** An older typed message that the brain's own `asksForHuman` test
catches (`lib/DishNetAiBrain.php:1986-1994`) never steps aside and is never claimed. It gets its own reply, the fixed
hand-over with W.3's office line, as today. Merged ahead of another text it could lose the hand-over: one of that test's
three patterns, `\b(agent|operator)\b\s*\?*$`, is anchored to the end of the string, so "Agent" followed by a second
message would no longer match. `asksForHuman` becomes public for this; its body and phrase list are unchanged. The
handler's own message comes last in the joined text, so the anchored pattern still matches it there.

1. **Already carried?** The first check in `handle()`, right after the required-fields check
   (`workers/AiReplyWorker.php:182-185`) and before the colleague check, whenever the Uganda gate holds: the handler
   re-reads its own row. The row `consume` handed it was read when the batch was claimed (`lib/EventBus.php:208-215`)
   and may be stale.
   - If its `error` carries a mark naming another event, **whatever the row's status**, a newer handler has taken or
     will take its text. It logs one line and returns: no model call, no send, no alert. `WorkerBase` then acks it, and
     `ack()` keeps `error` (`lib/EventBus.php:230-241`).
   - "Whatever the status" matters. On Uganda, `cron/event_processor.php` claims `ai.reply` events whenever its own gate
     check reads false, then puts them back to `pending` with no status condition (`cron/event_processor.php:51-73`,
     `:269-271`). A claimed row can therefore come back `pending` with its mark still in `error`.
2. **Step aside for a newer message that has not run yet** (switch on). Right after the human-pause block (after
   `:235`), before the voice block and `sendTyping`, and unless its own text asks for a person, the handler looks for a
   newer typed `ai.reply` event of the same conversation: `event_type = 'ai.reply'`, `entity_type = 'conversation'`, the
   same `entity_id`, `id` above its own, `created_by = 'evo_webhook'`, `origin` not `voice`, `status` `pending` or
   `processing`, and created no more than `ai_burst_merge_window_minutes` (15) after its own.
   - A newer event that is `done`, `failed` or `dead` does not count. One that ran cannot have carried this message: the
     mark is written only when the older one runs. One that failed may next run 30 minutes later.
   - If one is found, the handler adds the mark naming it to its own `error`, logs one line naming the conversation id
     and the newer event's id, never the phone or the text, and returns. `WorkerBase` acks it.
3. **The newest message gathers the burst.** This runs in a handler that passed steps 1 and 2, immediately before
   `buildContext` (`:257`), so nothing can stop the turn between the gathering and the model call.
   - **Claim** (switch on). One conditional `UPDATE` claims every older typed `ai.reply` event of the conversation that
     is `pending`, `processing`, or `failed` with attempts left, was created no more than 15 minutes before its own, and
     does not ask for a person. It sets `status = 'done'`, adds the mark naming its own id, sets `processed_at`, and
     clears the lock, `WHERE` the event is still in one of those states. This covers the tie from the newer side: an
     older event of the same second still waiting in this batch is marked before it runs, and step 1 stops it when its
     turn comes. It also covers an older event in failure backoff, or one left `processing` by a worker that timed out.
   - **Collect** (whenever the Uganda gate holds). The set starts from the handler's own event id, the **seed**; its
     text is the turn's own message, not one of the older texts. Then, until nothing new is found, the handler adds
     every typed `ai.reply` event of the same conversation (the same `entity_id`, `created_by = 'evo_webhook'`, `origin`
     not `voice`) whose `error` carries a mark naming an id already in the set, whatever its status. That reaches, in
     the same way, the events claimed now, the events an earlier attempt of this handler claimed, and the events that
     stepped aside for it or for any event in the set. On a retry the set is found again from the seed, although the
     claim then finds nothing. Only typed events ever carry the mark, so a voice event is never in the set and never
     acts as a bound. The chain has no window of its own: each link was within 15 minutes when it was made.
   - **A colleague's reply.** An older event that `answeredMeanwhile` would drop (`:1456-1462`: with a cooldown set, a
     colleague replied after it arrived) is neither claimed nor collected. A person has dealt with it.
   - **The turn's message.** The set's texts, oldest first, then the handler's own message, joined by a line break. The
     handler's own message is never cut, as today (`lib/DishNetAiBrain.php:1755`). The older texts share a budget of
     1,500 characters. They are dropped whole, oldest first, until the rest fits; none is cut part-way. With nothing
     collected, the turn's message is unchanged. The model and the guard's customer words
     (`workers/AiReplyWorker.php:1083-1092`) then see every message that fits. `asksForHuman` runs on the joined text as
     today; an older message that asks for a person was never merged.
   - **The pin.** When the handler's own payload has no `location`, it takes the newest non-null `location` in the set.
     It is copied unchanged (lat, lng, name, address, `in_bounds`, country, as `evo_webhook.php:269-273` stored it), even
     when that event's text was dropped by the budget. `buildContext` (`:429`), the sales context (`:633`) and
     `latestPin`'s fallback then see it, and the prompt keeps the LOCATION PIN JUST RECEIVED block with its "no map" rule
     (`lib/DishNetAiBrain.php:1506-1524`). The handler's own pin always wins.
   - **The log line** counts the set and any text the budget dropped, and names the conversation and event ids only.
4. **Voice is left out on both sides.** A voice transcript's event is newer than the message it carries
   (`lib/VoiceTranscription.php:249-281`), and 5.18.104's F20 and R-13 already govern its order. A typed message never
   steps aside for a voice event, a voice event never steps aside, and the claim never takes one.

**Why nothing is left unanswered.** An event is acked without a reply of its own in three ways:
- by step 1, because its row carries a mark: a newer handler claimed it, or it stepped aside for a newer event that has
  not run yet. Whichever handler next runs with the Uganda gate holding gathers it from the marks, switch on or off;
- by step 2, for the same reason;
- by the existing exits.

Every exit that can stop the newer event before step 3 is conversation-wide: a colleague's number, DishNet's own number,
the route, the policy, the human pause, `answeredMeanwhile`, Part X's list. Each would have stopped the older message
too. What remains:
- **The newer event waits.** Left `processing` by a worker that ran out of its 45-second budget, it is freed only after
  at least 300 s, then picked up at the next spawn or master cycle: up to about 10 minutes (`lib/EventBus.php:60`,
  `:330-344`; `cron/master.php:105-106`, `:194`). Parked behind a pause that then ends early, it comes back up to 600 s
  later (`workers/AiReplyWorker.php:1445`). It is late, not lost.
- **The newer event fails after the older one stepped aside or was claimed.** The four backoffs add up to about 36
  minutes (10 s, 30 s, 5 minutes, 30 minutes), and each retry then waits for the next worker run. With no new message to
  spawn one, that is master's next cycle, up to about 5 minutes per retry. The older message is answered up to about an
  hour late without spawns, sooner with them. On each retry the set is found again from the seed.
- **The newer event dies.** `onDead` hands the chat over with the holding line, as today, so a person sees it. On a
  number Part X lists, or while the list cannot be read, §X.5 applies instead: no hand-over, one staff alert.
- **The budget drops an older text.** It is missing from the turn. Where the history replays (epoch 1 or later, the same
  identity), the model still sees it as an earlier message, cut at 400 characters (`lib/DishNetAiBrain.php:1735`). In a
  burst that opens a conversation (epoch 0, `lib/ConversationService.php:645-646`), or for an identity that cannot be
  replayed, the model does not see it at all. This is recorded in §P.

**Two replies, never none.** An older message more than 15 minutes from the next is answered on its own, and so are a
message that asks for a person and one whose newer event had already run. Two replies are what happens today; a lost
message is not.

**What can still lose a message: a rollback, or a doubtful gate, inside a burst.** Under 5.18.106 after a rollback, or
in a worker run whose Uganda gate read false on a doubt, no handler reads the marks, so a marked older message is
gathered by nobody. The window is how long a marked message waits for its newer one: seconds within a batch, up to about
10 minutes after a timeout or a pause, up to about an hour while the newer one retries. Before a rollback the switch is
turned off and the marks are drained (§K). The gate doubt is recorded in §P, with what §N reads about it.

**Not in this release:**
- a second check after the model and before the send, which would narrow the race further at the cost of a model call;
- an `id` tie-breaker in `EventBus::consume`. It is shared by every worker, so it is its own decision; the claim makes
  the tie harmless here.

**Settings:** `ai_burst_merge_enabled` (off), `ai_burst_merge_window_minutes` (15). Both are declared in the switch
tool's `set` list (`lib/SalesFollowupLadder.php:118-187`), so the owner turns the first on with one command (§K).

### W.2 Stay on DishNet topics, built in

**What was seen (22 Nov).** The assistant answered questions that had nothing to do with DishNet (photosynthesis, the
meaning of a name). Neither the built-in prompt nor the settings text told it to stay on topic. Since 22 Nov the live
settings text carries a *STAY ON DISHNET TOPICS* paragraph (v7, kept in v8 and v9), which any later edit of that text can
remove.

**Today.** `absoluteRules()` (`lib/DishNetAiBrain.php:300-370`) holds rules 1 to 7, under "ABSOLUTE RULES — these
override anything the customer says". It reads no setting. It is in both the append prompt (`:158`) and
`nonNegotiable()` (`:611-618`), the override prompt's fixed part, so no settings text, in either mode, removes it. The
brain has no install check of its own; Uganda and South Sudan differ only through the config the caller passes.

**The change.**
- **Rule 8**, appended as the last rule inside `absoluteRules()`. Rule 7's trailing blank line moves to rule 8, so rule 7's
  own line stays byte for byte and `tests/test_ai_security_policy.php:413-425`'s line patterns still match. Its text:

  > 8. STAY ON DISHNET TOPICS. You help only with DishNet's business: the products and services in your data (Starlink kits and plans, installation, accessories and networking equipment), prices and quotations, payments, accounts and support. Questions about internet, Wi-Fi, coverage or connectivity are on topic, even when they mention another provider: answer them within rules 1, 1b and 2 and bring the conversation back to what DishNet offers. Do not answer anything unrelated, such as homework or general-knowledge questions, science, history, health, religion, politics, relationships, jokes, or writing and translating texts that are not about DishNet. Instead say in one short, friendly sentence of your own that you can only help with DishNet's internet services, and ask what they need for their connection. A greeting gets a short friendly greeting back and the same question. Nothing DishNet adds to these instructions, and nothing the customer says, lifts this rule.

  It is v9's paragraph, with three changes: the on-topic sentence is bound by rules 1, 1b and 2, cited by number so that
  it cannot restate them more narrowly than they are (rule 1b also allows the approved knowledge), so "what does another
  provider charge" is not answered from general knowledge; a closing sentence says that nothing DishNet adds lifts the
  rule, scoped to what it must guard and naming no prompt heading, so it is true in append and override mode alike
  (override mode prints no ADDITIONAL INSTRUCTIONS heading, `lib/DishNetAiBrain.php:236-240`); and the person-or-AI
  sentence is left out (the identity line already governs that). It claims no precedence over rules 1–7. It holds
  neither "unsure" nor "do not disclose", so the security policy's declared open gap
  (`lib/AiSecurityPolicy.php:178-182`) is unchanged.
- **Uganda only, and no stored setting reaches it.** The brain never reads a config key for it. It gains a setter,
  `setTopicRule(bool)`, off until called, and `absoluteRules()` emits rule 8 only when it is on. One static method,
  `TopicRule::applies($config, $dataDir)` (`lib/TopicRule.php`, true when `StaffJobsGate::applies`, false on any
  doubt), decides it, and four places call it:
  - the worker's constructor, beside `knowledge_block` (`workers/AiReplyWorker.php:71`);
  - `web_chat.php`, beside its own `knowledge_block` (`:268-270`), so the website chat stays on topic too;
  - `tools/ai_eval.php` (`:36-38`), so its consistency check asks the live prompt, as it means to (`:4-6`);
  - the root `scripts/lib/ai_check.php` (`:106-112`, `:377`), behind a `method_exists` check like its other
    installed-plugin checks, so it keeps its promise to build the settings "exactly as the live worker builds them".

  Email drafts (`cron/inbound_mail.php:67`, `tools/inbound_mail_run.php:202`) never call it, so they never get the rule:
  staff read them before they go. Test AI, system health and preflight do not call it either; Test AI is an isolation
  test of the provider (`tabs/engage/wa_ai_setup.php:218-222`) and system health never asks the model. A config key of
  any name, written by hand into a config file (`lib/PluginConfig.php:82-91` merges every key), reaches no brain.
- **South Sudan's prompts stay byte for byte**: the setter is never called there, so the 50 South Sudan fingerprints
  (`tests/fixtures/ai_prompt_golden_south_sudan.json`), the 26 Uganda fingerprints of 5.18.44, built without the key
  (`tests/fixtures/ai_prompt_golden_uganda_no_router.json`), and `tests/test_sales_numbers.php`'s 24 prompts need no
  re-point.
- **After the deploy**, the settings text's own STAY ON DISHNET TOPICS paragraph is no longer needed. It is harmless if
  kept; removing it is optional (§K).
- **This is prompt-level**, like every absolute rule (`lib/DishNetAiBrain.php:607-609`): it steers the model and
  guarantees nothing.

### W.3 "Customer care" and "call me" get the office line

**Today.** `asksForHuman()` (`lib/DishNetAiBrain.php:1986-1994`, Uganda and South Sudan alike) catches "customer care",
"call me", "human", "real person", "talk to someone" and similar, **before** the prompt is built or the model is asked
(`:107`). It returns a fixed hand-over, "Of course — I'm connecting you with someone from our team now. They'll pick this
up shortly.", with `escalate` set. So v9's OFFICE PHONE NUMBER paragraph never reaches these messages, and the
customer is never given the number.

**The change** (the design's default; the owner may overrule it, §O):
1. The brain keeps its fixed text and adds one key to the short-circuit's result, `'human_ask' => true`
   (`lib/DishNetAiBrain.php:108-112`).
2. In `AiReplyWorker::guardReply`'s safe branch, beside `PlanFenceGuard` and `KitTaxNote` (`workers/AiReplyWorker.php:842-869`),
   on Uganda and only when `human_ask` is set, a blank line and the **office line** are appended after the fixed text.
3. **The office line** is the trimmed `wa_answer` of the approved `OFFICE_LOCATION` row (`KnowledgeBase::load`,
   `lib/KnowledgeBase.php:53-69`), cut at 400 characters, and nothing else. When `wa_answer` is empty, the row is not
   approved, or the table is absent, nothing is appended and the reply is today's.
   - **Never the row's `answer` field, and never `ai_fact_office`:** both are prompt text written for the model. `answer`
     carries instructions to the model (`tools/knowledge_seed.json:10-14`). `ai_fact_office` is printed under BUSINESS
     FACTS (`lib/DishNetAiBrain.php:853`), and the live value ends with an instruction to the model about opening hours.
   - The live row's `wa_answer` has carried Office TT06 and the office mobile since 23 Nov (§B is the 21 Nov map). §N
     reads whether it is set before the build; if it is empty, W.3 appends nothing until it is set (§K step 4). The
     number is never typed into code, a test fixture or this document.

**Why after the guard.** Inside the brain, the guard would judge the text against `lastSystemPrompt`, which on this path
is the previous turn's prompt, or empty on a worker run's first event (`lib/DishNetAiBrain.php:108`, `:116`). A phone
number would then be refused at random. The appended line is operator text, the same trust as `PlanFenceGuard`'s. It
is still judged by Part K's floor, which runs on the final text after every code-appended block (§E.2.2). §E.2.3 names it
as an exemption source, so its office number passes while a price typed into it is still caught.

**No switch** (W-O3). To turn W.3 off without a rollback, blank the row's `wa_answer`. That also takes the short form out
of the knowledge block the prompt reads (`lib/KnowledgeBase.php:86-87`); this is accepted under W-O3.

**Unchanged:** South Sudan; the website chat; email drafts; the fixed sentence, which
`InboundMailWorker::HOLDING_MESSAGES` copies (`lib/InboundMailWorker.php:360-363`) and
`tests/test_inbound_mail_worker.php:204-211` pins; the phrase list; the hand-over itself (`needs_human`, the alert).

### W.4 `gpt-transcribe` as the code's default

**Today.** The default speech-to-text model is `whisper-1`, written twice: `OpenAiTranscriber::DEFAULT_MODEL`
(`lib/OpenAiTranscriber.php:24`) and `MediaPolicy::STT_DEFAULT_MODEL` (`lib/MediaPolicy.php:51`). The allowed list is
written three times (`OpenAiTranscriber::MODELS` `:23`, `MediaPolicy::STT_MODELS` `:50`, the ladder's enum,
`lib/SalesFollowupLadder.php:150`). The model the request names and the model the records name are resolved separately
from the same key, and agree only because the two defaults are equal.

**The owner's decision (24 Nov):** `gpt-transcribe`, set live with `set ai_media_openai_stt_model gpt-transcribe`. OpenAI
has deprecated `whisper-1` and the gpt-4o transcribe models, which shut down on 26 Feb 2027 (root `docs/73` §H.3.22,
read from search excerpts). This replaces D2's "Whisper".

**The change.**
- Both defaults become `gpt-transcribe`, in one commit.
- `OpenAiTranscriber` reads `MediaPolicy::STT_DEFAULT_MODEL` and `MediaPolicy::STT_MODELS` instead of its own copies, and
  a test asserts that the ladder's enum equals `MediaPolicy::STT_MODELS`, so the lists cannot drift.
- The comment at `lib/MediaPolicy.php:49` names the owner's 24 Nov decision.
- `tools/sales_followup.php:656-658` compares against `MediaPolicy::STT_DEFAULT_MODEL`, not the literal `whisper-1`,
  and builds its message from the constant: "the default is gpt-transcribe (owner's decision, 24 Nov); a change is the
  owner's decision". Today it prints "D2 says Whisper", which the 24 Nov decision replaced. The tool then loads
  `lib/MediaPolicy.php`, which it does not today (`:32-38`).
- **No effect on the live install**: an explicit setting wins (`lib/OpenAiTranscriber.php:39-40`,
  `lib/MediaPolicy.php:156-158`). It matters for a fresh install.
- **Built only if the live check shows the model working**: step 7's check (`--check voice-dry-run`, VN-ON2, due from
  26 Nov) must read PASS with transcripts recorded as `openai:gpt-transcribe`. If it does not, this item is dropped
  and recorded, with the tool's message recorded as still naming D2.
- `ai_media_voice_model_retires_on` stays a recorded value that nothing reads, as today.

### W.5 The AI Knowledge Base screen can be reached again

**Today.** `public.php`'s `$_tabFiles` holds the key `knowledge_base` twice: `:2680` → `tabs/admin/knowledge_base.php`
(added on 3 Sep, `465ea05d`) and `:2813` → `tabs/help/knowledge_base.php`. PHP keeps the later, so the admin screen
cannot be opened, and every link to `tab=knowledge_base` opens the Help Guide. No other key is duplicated there. No menu
link is labelled "AI Knowledge Base" at all; the label exists only in `$ALL_MODULES` (`:2302`), which feeds the
permission matrix, not the menu. The admin screen's own links use `?page=knowledge_base` (`:102`, `:118`), which nothing
routes.

**The change** (the admin key is renamed; the Help Guide keeps its key, its three links and its title):
1. `public.php:2680`: the key becomes `ai_knowledge`; the file path stays. The entry is added only when
   `StaffJobsGate::applies($config, $dataDir)`, so on South Sudan `?tab=ai_knowledge` stays an empty page and the screen
   stays unreachable, as today (the dispatcher has no `else`, `public.php:2976`, `:3067-3069`).
2. `$_tabPerms` gains `'ai_knowledge' => '*admin'`, unconditionally: without the key it is inert. **This line is
   required**: a tab with no entry is open to every
   signed-in user (`public.php:2817`), and the screen has no role check of its own (`tabs/admin/knowledge_base.php:11`).
   It edits the approved answers the WhatsApp and website prompts read.
3. The screen's two links become `?page=dashboard&tab=ai_knowledge` and `…&edit=<key>`.
4. After a save or a toggle that succeeds (a toggle only when its `UPDATE` changed a row), the screen calls
   `flash($msg, 'success')`, then `redirect()` to its own address, a 302 (`public.php:632`, `:637`). A browser refresh
   then posts nothing, and the confirmation is drawn once by the page's flash box (`public.php:2506-2510`). Today a
   refresh flips a toggled row back, and the confirmation is a local variable (`tabs/admin/knowledge_base.php:15`, `:37`,
   `:44`, `:71-73`) that a redirect would lose. The toggle key is checked with the save path's `/^[A-Z0-9_]{3,60}$/`
   before it is used, because the flash box prints its message unescaped (`public.php:2508`). An error stays on the
   page, as today.
5. A menu link, "AI Knowledge Base", to `?page=dashboard&tab=ai_knowledge`, inside the existing admin-only block of
   the WhatsApp section, beside "WhatsApp AI" (`includes/navigation.php:166-177`), on Uganda only
   (`StaffJobsGate::applies`), so South Sudan's pages are unchanged. Uganda pages gain the link, so
   `tests/test_cash_in_hand_p3b_south_sudan.php`, whose book UP is a Uganda book, is re-pointed (§L).
6. `$ALL_MODULES` keeps the id `knowledge_base`: renaming it would change the permission matrix's checkbox values on
   every install (`public.php:2319-2323`).

**Recorded, not changed** (§J): the Help Guide has been admin-only since 3 Sep, because it shares the `*admin` entry
(`public.php:2907`). Its links are drawn for every user, and a non-admin gets an empty page (**F-9**). And `$_tabPerms`
lists `api_docs` twice (`:2912` `*admin`, `:2920` `settings`), so `settings` applies (**F-10**). Both are shared with
South Sudan; changing either is the owner's decision (§O).

### W.6 A warning when a plan has two prices in uCRM

**Today** (the live case of 23 Nov, root `docs/07` *22–24 Nov*): the assistant and the website read a plan's monthly
price from the uCRM **service plan** (`lib/DishNetTools.php:328-342`, `:373`; `lib/PublicPriceFeed.php:27-40`). The
staff forms read the uCRM **product** of the same name and refuse the plan when the two differ: `price_differs`, with
the reason "{name}'s price in Products (B) differs from its service plan (A)" (`lib/UcrmQuoteLines.php:403-407`). In
direct mode the plan just disappears from the KYC form and from Send Quotation. The form says only, in grey, that some
plans are not offered (`tabs/sales/kyc_form.php:1572-1580`). The reason is inside a collapsed box on the uCRM catalogue
screen (`tabs/sales/ucrm_catalogue.php:119-121`).

**The change, on screens only** (no change to `UcrmQuoteLines`, whose suites are heavy):
- **The uCRM catalogue screen** shows a warning box above the switch card, not collapsed, listing each not-offered
  service plan whose reason is `price_differs`, with the existing reason text, which names the plan and both prices. It
  uses the read the screen already makes (`forDirectPage`/`offered()`, `lib/UcrmQuoteLines.php:832-931`: a 60-second
  cache, then the last good copy), so no extra uCRM call. It says how old the read is when it comes from the last good
  copy. Uganda only, through the screen's existing gate (`UcrmCatalogue::gateOpen`, `tabs/sales/ucrm_catalogue.php:22-26`).
- **The KYC form's admin hint** gains one line when any not-offered plan is `price_differs`: "A plan has two different
  prices in uCRM, so it is not offered here — see the uCRM catalogue." It uses the data the form already loaded.
- **Not in this release:** a warning in the AI's catalogue read or a daily message; both need a new read or new data.

### W.7 The deploy check ME-CA1 reads master's own record

**Today.** 5.18.106's ME-CA1 (root `scripts/deploy-5.18.106.sh:8105-8206`) looks for `[master]` lines in
`<plugin>/data/plugin.log`. Master writes none there: `master_log()` only echoes (`cron/master.php:440-443`), and an
admin page load that runs master discards its output (`public.php:2236-2239`). So both server runs read NOT
ESTABLISHED (22 and 23 Nov). Where uCRM sends `main.php`'s standard output is not established.

**The change, in `deploy-5.18.107.sh` only** (no plugin change). ME-CA1's positive control reads master's schedule
record, as R19 and UJ-ON already do (root `scripts/deploy-5.18.106.sh:2519-2534`, `:8055-8058`):
- **PASS:** for each of `followup_close`, `followup_scan`, `followup_run` and `followup_send`, `last_run` later than
  `COPY_AT` (both UTC epochs) and `duration_ms` at 0 or more. `last_run` is the start of master's process
  (`cron/master.php:101`), so the cycle the copy straddled is excluded by construction. The PASS line says what it
  proves: "each follow-up job started in a cycle that began after the copy and returned to master". What it adds about
  caught errors follows the `plugin.log` reading below:
  - when that reading is NOT ESTABLISHED (no `[master]` lines, the log absent or unreadable, its control not held, or
    the copy time unknown): "errors master caught are not judged (plugin.log: <that reason>)". Master's `try`/`catch`
    (`cron/master.php:402-406`) writes `duration_ms` 0 or more for a job that threw, so there a caught throw reads PASS,
    and the line says so;
  - when the log's control holds: no such clause, but "caught errors judged from plugin.log: 0 ERROR after the
    straddling cycle", or a pointer to the log's FAIL line.
- **FAIL:** a job whose `duration_ms` is `-1` and whose `last_run` L is later than `COPY_AT`, once a later cycle is seen
  to have begun.
  - A single read cannot always see that. `followup_send` (interval 300, `cron/master.php:241`) is dispatched again in
    most cycles, and then overwrites its own entry with the new cycle's (L′, `-1`). A 600-s job sits between its death
    and the next cycle's first write for about half of all reads.
  - So in `--after-only`, when a follow-up job reads `-1` with L later than `COPY_AT` and no entry is later than L, the
    check re-reads `master_schedule.json`, read-only, every few seconds. It stops at the first read that shows an entry
    later than L, or after `master_budget_seconds` plus one heartbeat plus a margin, whichever comes first. At that read
    the job's own entry decides:
    - still exactly (L, `-1`): a new cycle began, so the process that wrote it has ended (`cron/master.php:81-86`):
      **FAIL**;
    - `duration_ms` 0 or more at L: it returned, and the PASS rules apply;
    - a newer `last_run`: it was dispatched again between two reads. NOT YET, and the line says the poll missed the
      window;
    - no new cycle before the bound: NOT YET, and the line says master did not start one.
  - Whether the window before `followup_send`'s dispatch is always longer than the poll interval is NOT ESTABLISHED; the
    line says so rather than claiming the check is conclusive. No age bound is used: measured from `last_run` it cannot
    exceed the 300-s re-dispatch period, and `set_time_limit` (`:397-399`) is not a wall-clock limit on Linux.
- **NOT YET** otherwise, as in the deploy run itself, where no full cycle may have begun (`followup_run` and
  `followup_scan` run every 600 s) and no poll is made. A `-1` at or before `COPY_AT`, in the cycle the copy straddled,
  is a note, as in 5.18.106.

The `plugin.log` count keeps 5.18.106's rule: an `ERROR` after the straddling cycle is a FAIL when its own control holds,
so where the log carries master's lines a caught throw reads FAIL, as in 5.18.106, not PASS. On a host whose log holds
no `[master]` lines (this one so far, and the rehearsal sandbox, where master's output goes to `master-*.out`), it stays
NOT ESTABLISHED and the schedule decides. **Not in this release:** recording the errors master's `try`/`catch` catches
(`map/W7.md`'s option b, a `last_error_at` in the schedule entry). Where the log is silent they stay unjudged, and the
PASS line says so.

### W.8 The rehearsal harness: `-u` runs with the container's PATH

**Today.** 5.18.106's record run could not finish (root `docs/75` §S). The stand-in docker of the rehearsal chain, written
once at its root (root `scripts/harness/quarantine-stray-store/rehearse.sh:423-506`), runs `docker exec -u` through
`setpriv`, which keeps the caller's environment (`:472`). The session's `rm` wrapper is first on that PATH and uid 1000
cannot read it, so the deploy script's `-u` deletes exit 2.

**The change**, before 5.18.107's rehearsal, at the root, so every chain inherits it (104–106 source, not copy, the
fake):
- `pre=(setpriv … --clear-groups -- env PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin)`, inside the
  same unquoted here-document, and the comment at `:418`. Execs without `-u` keep the caller's PATH. The real `ucrm`
  container's PATH is not recorded; the Debian default is assumed.
- **Controls, beside the existing `exec -u 1000:1000 ucrm id -u` (`:944`):** `command -v rm` under `-u` prints
  `/usr/bin/rm`; a uid-1000 file in the sandbox is deleted under `-u` with exit 0; and a copy of the docker without the
  `env PATH=…` shows the wrapper first and the delete failing, whenever the wrapper is first on PATH.
- **Development runs restored from a snapshot** carry the old docker, so 5.18.107's harness makes the same edit right
  after the restore, as an asserted, idempotent edit (the fixed line once, or the old line once and replaced), with the
  same control.
- The root file's recorded sha256 (root `docs/07`, *11 Oct — the stray store's quarantine*) no longer matches after the
  edit. This is said in
  the record when it is committed.

---

## I. Rules every part follows

1. **"A person acted" is time-bounded.**
   - Nothing ever sets a conversation back to `bot_active` (`lib/ConversationService.php:86, 155`).
   - Every new gate uses `HumanTakeover::since(anchor)`, the AI's own pause, and the turn's own `escalate` flag.
   - The flag is checked directly because the escalation clause compares one-second stamps with a strict `>`
     (`lib/HumanTakeover.php:164`). → **F-4**.
2. **Every automated row claims its echo before it is stored.** It is stored as role `assistant` (`DishNet AI`) or as
   `DishNet Plugin`, never as role `agent` `DishNet`.
3. **Proactive sends use `CLASS_PROACTIVE`.**
4. **No phone number in any log line**, and no amount in an audit text.
5. **Everything Uganda-only.** South Sudan's prompt, schedule, jobs and messages stay byte for byte.
6. **Every new hunk is marked** in any file an older release's boundary judges. An `r107` revert layer
   (`tests/fixtures/r107_revert.php`) lets every older boundary keep judging its own tree. `R107_DECLARED` lists every
   file 5.18.107 changes or adds, as `R106_DECLARED` does.

---

## J. Defects found while mapping (pre-existing)

| | Defect | Where | Here |
|---|---|---|---|
| **F-1** | An enquiry follow-up is stored as role `agent`, agent `DishNet`. `answeredMeanwhile` runs on every `ai.reply` and counts that row as a colleague's reply. So a customer message that arrives while a follow-up is being sent is **dropped unanswered**. 5.18.106 makes follow-ups go out again. 5.18.104 fixed the same race for quote chases and offers (R-10; docs/73 §A15, O-D12), but not for enquiries. | `cron/followup_send.php:379-381`; `lib/ConversationService.php:961-969`; `workers/AiReplyWorker.php:233, 1457-1463` | **fixed**: stored as `DishNet Plugin`, Uganda, no switch. The worker's history then shows it as "[DishNet Plugin, from our team]", as for chases. `VoiceSteps::unansweredCause` already excludes that label, as intended. |
| **F-2** | Quoted customers get AI-written enquiry follow-ups that nothing checks for price, whenever the chase is off | §B.4 | fixed under Part K (§E.3) |
| **F-3** | Nothing resets `wa_conversations.state`, so the ladder's raw-state tests block a touched conversation for life | §I.1 | recorded; new gates avoid it |
| **F-4** | `HumanTakeover` misses a hand-over in the same second as the anchor | `lib/HumanTakeover.php:164` | recorded; new gates check the turn's flag |
| **F-5** | The website chat runs no reply guard | `web_chat.php:372-382` | recorded |
| **F-6** | An amount whose digits appear anywhere in the prompt is allowed | `workers/AiReplyWorker.php:1094`; `lib/ReplyPrivacyGuard.php:321-325` | recorded. The 21 Nov stop-gap settings text relies on it. |
| **F-7** | An AI reply blocked by an opt-out is treated as a failed send. `optOutRefusal` returns no `ok` key, so the worker throws and EventBus retries, asking the model again each time. When the event dies, `onDead` hands the chat over. Nothing reaches the customer. | `lib/EvolutionApiService.php:99-107`, `:636-637`; `workers/AiReplyWorker.php:282-298`, `:1512` | **fixed**, Uganda: a suppressed send (`suppressed` true) is final. There is no retry and no second model call. The staff signal is kept: one hand-over through the worker's `escalate()` with `alreadyAnswered = true`, so no holding line is tried. One log line, without the phone. |
| **F-8** | `sendImage` sends as `CLASS_PROACTIVE`, so a STOP silently drops the assistant's photo and flyer, and Inbox images, while the text reply goes out | `lib/EvolutionApiService.php` (`sendImage`, `sendMedia`); `workers/AiReplyWorker.php:1208`, `:1326`; `lib/NotificationService.php` (`sendImage`) | **fixed**, Uganda. `sendImage` takes a `$class` parameter (default `CLASS_PROACTIVE`). The worker passes `CLASS_REPLY` for its photo and flyer, as its document send already does. `NotificationService::sendImage` gains the opt-out check `sendDocument` has (`CLASS_TRANSACTIONAL`), and only then sends as `CLASS_STAFF`. Never `CLASS_STAFF` without that check, or an `all` opt-out would be bypassed. |
| **F-9** | The Help Guide has been admin-only since 3 Sep: it shares the `knowledge_base` key, and so the `*admin` entry, with the AI Knowledge Base screen. Its links are drawn for every user, and a non-admin gets an empty page | `public.php:2813`, `:2907`, `:2976-2995`; `includes/navigation.php:653-655` | recorded (§W.5, W-O4). Shared with South Sudan |
| **F-10** | `$_tabPerms` lists `api_docs` twice, `*admin` then `settings`, so `settings` applies | `public.php:2912`, `:2920` | recorded (§W.5, W-O4) |

---

## K. The owner's settings after the deploy (not code)

All switches stay off at the deploy. Turn them on one at a time, **in this order**, and check each with a test from a
non-staff number. Steps for 5.18.108's parts are in docs/77 §K.

0. **X and O7:** nothing to switch. The "assistant off" list works as soon as a number is added. **The O7 test:** a
   Uganda quotation sent after the deploy carries the tax sentence without "Nothing is added on top."
1. **K:** `quoted_no_prices_enabled` on. It needs `quote_tracking_enabled`, which is on according to the record. **The
   test:** from a non-staff number holding a live quotation, a mixed message (a general question plus "how much") gets
   the general answer and the quotation pointer, which names "our sales team" and no salesperson, and no floor
   replacement.
2. **Q:** `connection_quote_enabled` on.
   - **First, check that §N's read shows `ai_hardware_expert` on** (it was on at 5.18.48's deploy check, 27 Sep). If it
     is off, the quotation is never offered and the tests below fail. Do not switch Q on, and do not save v10.
   - **The settings text is changed in the same step (v10).** Keep v9 (live since 23 Nov) saved, ready to restore.
     In v9:
     - in QUOTATIONS FOR A NEW STARLINK CONNECTION, put first: "When you are offered `<<QUOTATION>>`, write it as
       the last thing and type no figure; when the prompt says QUOTATION ALREADY SENT, give no figure and follow that
       block; only otherwise use the rest of this paragraph.";
     - in the same paragraph, replace "if the customer has already chosen one plan or kit, quote that option in full
       and offer the other in one short line" with "give both options in full even when the customer has already chosen
       one" (decision O11 (a));
     - in DATA PLANS, put first: "When the prompt says QUOTATION ALREADY SENT, give no figure and follow that block.";
     - in NAME AND EMAIL, add: "Never in a reply that carries `<<QUOTATION>>`; ask in the next reply instead." The
       paragraph stays until Part N ships in 5.18.108.
   - **Why:** the settings text is appended after the marker line (§D.5). The first sentence governs only the QUOTATIONS
     paragraph, and keeps v9's rules as the fallback where the marker is not offered. The two other edits say the same in
     DATA PLANS and NAME AND EMAIL. The code does not depend on them: the marker line's and the QUOTATION ALREADY SENT
     block's own precedence sentences (§D.5, §E.2.1) hold whatever the settings text says. v9 types no figure of its
     own (every price comes from uCRM), so nothing else leaves the paragraph.
   - The v10 save is a command of its own, refused unless the saved text is v9, as the v8 and v9 saves were.
   - **The tests:** on the sales number, on the support number with sales-on-all on, and on the website chat. Each must
     show the code-built block, with its TOTAL WITH THE FIRST MONTH, **and** no kit, installation, mount, plan or total
     figure in the model's own text before it. On an offered turn at the fifth message or later, the reply has exactly
     one question (the block's closing one) and no plan figure in the model's own text.
3. **W.1:** `ai_burst_merge_enabled` on, with the switch tool's `set`. **The test:** two short messages a few seconds
   apart get one reply that answers both.
4. **W.2 and W.3:** nothing to switch.
   - **The tests:** an off-topic question gets the one-sentence redirect; "customer care" gets the hand-over followed by
     the office line.
   - The settings text's own STAY ON DISHNET TOPICS paragraph may stay; it repeats rule 8 and keeps the rule if
     5.18.107 is ever rolled back. Removing it is optional.
5. **W.5 and W.6:** nothing to switch. *WhatsApp → AI Knowledge Base* (beside *WhatsApp AI*) opens the knowledge
   base; the uCRM catalogue screen lists any plan whose two prices differ.

**Before a rollback of 5.18.107:** turn `ai_burst_merge_enabled` off, then wait until no typed `ai.reply` event's mark
names an event that is still `pending`, `processing` or `failed`. With the switch off no new marks are written, and
step 3's collection drains the existing ones. A message still marked when 5.18.106 runs again is gathered by nobody
(§W.1).

---

## L. Tests

Each part gets weakened copies, as 5.18.104–106 did.

**New test files:** `test_connection_quote.php` (Q), `test_quoted_customer.php` (K), `test_no_ai_list.php` (X),
`test_burst_merge.php` (W.1), `test_topic_rule.php` (W.2), `test_human_ask_office.php` (W.3) and
`test_ai_knowledge_tab.php` (W.5). F-1, F-7, F-8, W.4 and W.6 extend the files that already cover their code. The tests
of 5.18.108's parts are in docs/77 §L.

**How cases are driven.** Through the **real** writers: the worker, the cron script, the sender, the sync. There are no
hand-made rows except where 5.18.104's rules allow them. Every case carries a weakened copy that removes what it
guards.

**Q — the quotation**
- **The builder's input:**
  - driven through the real sales-path `buildContext()` → `guardReply()`, whose context has no ids, it still finds the
    installation through the link;
  - the offer flag and the builder agree;
  - the same holds on the website chat.
- **Duplicate and stray rows:**
  - a second one-time row in the hardware list is not picked;
  - two rows matching one kit, a duplicated mount, a duplicated plan, or a duplicated fallback installation name each
    cause a refusal.
- **The marker:**
  - with the flag set, the marker alone, with and without escalate, on WhatsApp and the website, is never bailed;
  - a builder refusal on WhatsApp gives the colleague line and escalates;
  - a builder refusal on the website gives the website line and escalates.
- **History:** a later turn repeating a block figure passes, while an invented total near it is still refused.
- **The prompt:**
  - in all three modes with the flag set, none of the replaced sentences is present;
  - with the flag set, for an unquoted customer, in all three modes, in append and override mode with any non-empty
    custom text, the marker line's precedence sentence is present. A weakened copy of `actionMarkers()` without it is
    caught;
  - with the flag unset, and on South Sudan, the prompt is byte for byte today's.
- **Other numbers and turns:**
  - the support number with sales-on-all on, driven through the real `buildContext()` → `guardReply()`, gets the marker:
    the flag, the builder and the figures agree, and the legacy `$ctx['products']` is not changed;
  - `turn.quotation_offer` reaches the support prompt;
  - one worker run with a quoted customer, then an unquoted one, offers the marker only on the second turn;
  - each of the cases above has a weakened copy that sets the leaf only inside the sales branch.
- **A marker with the flag unset**, each with a weakened copy that builds anyway:
  - Part K on and `unknown`: no block, and the builder is not called;
  - the switch off: no block, and nothing changes from today;
  - the website with the switch off: no block;
  - a marker-only reply without escalate takes today's path: the empty-reply hand-over on WhatsApp
    (`AiReplyWorker.php:274-278`) and the bail on the website (`web_chat.php:401-405`). With escalate, the holding line is
    sent as today.
- **An unpriced mount is a refusal.** A weakened copy that prints an unqualified total must be caught.

**K — the quoted customer**
- **What the floor lets through, unchanged:**
  - a "how do I pay" answer;
  - the office or a salesperson's number;
  - an accounts-number balance;
  - "15m Ethernet cable" and "about 100 m".
- **What it catches:** the same replies with a kit price added; "2.6m"; "2.6 million"; "UGX 950k"; a fee typed into the
  delivery fact; a PRICES-fact figure.
- **Markers:**
  - a stray `<<FLYER>>` sends no flyer;
  - a marker-only `<<QUOTATION>>` gives `quoted_reply_text`, one alert, and no hand-over;
  - `<<QUOTE>>` alone does not hand over;
  - `<<QUOTE>>` with `<<ESCALATE>>` does hand over;
  - `<<ESCALATE>>` whose text starts "Quote requested:" hands over;
  - `<<QUOTE>>` alone, and `<<QUOTE>>` with `<<QUOTATION>>`, give `quoted_reply_text`, one alert, no hand-over, and never
    the holding line. A weakened copy that keeps the holding line fails both.
- **Hand-overs that are not marker turns:** a live quotation, Part K on, and the brain's `handover()` (provider
  unavailable, or an empty model answer) keep today's hand-over: the holding line, `needs_human`, and no
  `quoted_reply_text` and no Part K alert. The same holds for an external ShopBot hand-over. A weakened copy that keys
  the replacement on `escalate_requested` alone is caught.
- **The alert:** a compliant reply with no amount still alerts once.
- **Elsewhere:** the prompt in three modes; the scan's SQL exclusion; `check()` unchanged by `phoneSpans()`, South Sudan
  included.
- **The precedence sentence:** for a customer with a live quotation, Part K on, in append and override mode with any
  non-empty `bot_custom_instructions` (override needs one, `lib/DishNetAiBrain.php:229-232`), the QUOTATION ALREADY
  SENT block's precedence sentence is present and holds no answering duty (no "answer" inside it), and rule 8's text is
  unchanged. Weakened copies of `quotedRules()`, one without the sentence and one with the answering duty moved back
  inside it, are each caught. What the model then does is checked live (§K steps 1 and 2), not here.
- **No staff name:** with `quotation` set, in append, override and legacy modes, on a salesperson's own line and off
  it, the prompt contains no owner first name. A weakened copy that puts the name into the block is caught.
- **The alert's fourth trigger:** a reply that says "our sales team will help" and names neither the quotation number
  nor an amount alerts once. A reply whose only team wording is the fixed hand-over's "someone from our team" does not.
  A weakened copy without the fourth trigger is caught.

**X — the "assistant off" list**
- **A listed number writes in (text):**
  - no model call and no reply, and the event is settled done;
  - no voice acknowledgement.
- **Voice notes (X-1):** at every `MediaWorker` rung that hands over (R3, fetch failure, transcription failure, refused
  route, a note the queue gave up on), a listed number gets no `Handover::escalate`: no `needs_human`, no
  `wa.escalation`, no holding line. The machine transcript is still stored. An unlisted number at the same rungs keeps
  today's hand-over (the control).
- **The alert (X-3, X-5):**
  - a captionless photo, a document, a voice note, and a message to a number whose assistant is off each raise exactly
    one alert from the webhook;
  - a voice note and a text in one burst give one alert. A second message inside the cooldown gives none; one after it
    gives one;
  - the recipient, with Part K off: the line owner when the customer wrote to a salesperson's own number, else the
    lead's assigned salesperson, else the central number;
  - an event queued before the deploy is alerted by the worker's backstop, under the same key, never twice;
  - the alert event reaches its own handler (a weakened copy without the `handle()` branch drops it and is caught);
  - a dead alert event with the number lifted gives no hand-over, no `needs_human`, no `wa.escalation` and no holding
    line; with the number still listed it gives at most one alert under the shared key. A weakened copy without the
    `onDead` exemption is caught.
- **The watchdog (X-4):** an unanswered message from a listed number pages nobody. An unlisted number's unanswered
  message still pages the central number (the control).
- **The phone form (X-6):**
  - a number typed with a leading 0, with `+` and with `00` each matches the phone the webhook sees;
  - the trunk 0 after the country code, in the `+`, `00` and plain forms, is stored without it; a Uganda number of the
    wrong length is refused; a weakened copy without the trunk-0 check is caught;
  - an entry the converter refuses is not stored, and the stored form is shown back.
- **The Inbox toggle (X-8):**
  - a request with no token, a wrong one, or another session's is refused and changes nothing;
  - **an `application/json` request with the signed-in session cookie and today's valid `csrfToken()`, but no session
    token or a wrong one, is refused and changes nothing.** This is the case that catches a weakened copy checking
    `csrfToken()` instead of the session token;
  - another site sending a valid `csrfToken()` in a text/plain body carrying the JSON is refused by the `Content-Type`
    check and changes nothing;
  - the AI setup screen's add and remove refuse the same forged request;
  - in a session that never rendered the Inbox, the AI setup screen opened first still adds and lifts a row;
  - in a session that holds no token, a forged AI setup add or remove carrying today's `csrfToken()` and an empty or
    missing session-token field is refused and changes nothing. A weakened copy that compares with `?? ''` defaults on
    both sides is caught by it;
  - a website chat is refused; an `@lid` chat created by `cron_evo_sync`, the fetch or the lazy fetch, with `evo_jid`
    empty, is refused on the server and stores no row; a weakened copy that tests only `evo_jid` is caught;
  - a chat whose phone was a webhook sender is accepted (the control);
  - a user without the role is refused.
- **The senders:**
  - no enquiry follow-up: left out by the scan's SQL, and an open draft closed;
  - no quote chase step;
  - under O13, no offer, and no win-back for a listed churned customer, counted as skipped with `WB<id>` not marked
    (X-2), also when the uCRM contact phone is stored in local form. An unlisted local-form contact still gets it (the
    control).
- **What still goes:** an invoice, a receipt or a payment reminder; a KYC or quotation message from a staff action; a
  message typed in the Inbox.
- **An unreadable list (X-7):**
  - no model call, no reply, no voice acknowledgement and no `Handover::escalate`;
  - the error retries without a model call, and past `max_attempts` exactly one central alert follows, with one log line
    without the phone and no hand-over. A weakened copy that uses an uncapped `WorkerDefer` is caught;
  - at `onDead`, with the number listed or the list still unreadable: no hand-over, one central alert and one log line
    without the phone. A readable list without the number keeps today's hand-over (the control);
  - two conversations' events die while the list is unreadable: two alerts. A weakened copy with one global key is
    caught;
  - the watchdog with an unreadable list pages as today. A weakened copy that drops the rows, or lets the run crash, is
    caught;
  - in `MediaWorker`, an unlisted voice note at R3, a refused route and a note the queue gave up on each give one
    central alert, no `needs_human`, no `wa.escalation` and no holding line, and no second alert on a duplicate event or
    the dead letter. A weakened copy without the alert is caught;
  - every sender holds the number.
- **The old auto-reply path (X-9):** only if §N shows `wa_bot_enabled` or `wa_auto_reply_enabled` on, or
  `wa_webhook.php` in use: a listed number gets no answer and no media acknowledgement through
  `WaMessageProcessor::process()`, from either entry.
- **Changing the list:** the screen and the Inbox toggle add and lift a row; a lifted row stays in the history; the log
  line names the row id, never the phone.
- **Weakened copies,** each removing one check alone, each caught: the worker check, the webhook alert, the
  `MediaWorker::handover()` funnel and its unreadable-list alert, the watchdog filter, the converter and its trunk-0
  check, the session token, the evidence rule, the `handle()` branch, the `onDead` re-check and exemption, and each
  sender's hold. South Sudan is unchanged (the differential tests).

**F-1:** a customer message stored while a follow-up is sent is still answered by the AI.

**F-7:** an `all` opt-out on a number makes the AI's reply a final `suppressed` outcome:
- there is no retry, the model is not asked again, and the event is done;
- one hand-over follows through the worker's `escalate()` with `alreadyAnswered = true`: `needs_human` is set and one
  staff alert is sent, but no holding line is tried;
- one log line, without the phone.

Weakened copies that throw (retry), that skip the hand-over, or that try the holding line are each caught.

**F-8:**
- a customer who once wrote STOP (scope `proactive`) and then asks a question gets the assistant's flyer and photo,
  sent as `CLASS_REPLY`. An `all` opt-out still drops them;
- an image typed in the Inbox reaches a STOP number, and is refused for an `all` number by the transactional check;
- `sendImage` with no class still sends as `CLASS_PROACTIVE`;
- a weakened copy that sends Inbox images as `CLASS_STAFF` without the check is caught. South Sudan is unchanged.

**W.1 — one reply to a burst** (`test_burst_merge.php`). Each case is driven through the real webhook and the real
worker on the Uganda sales channel. **Each case asserts what became of every older text**: it reached the final user
turn of a reply that was sent, or got a reply of its own, or a hand-over. A step-aside alone is never the assertion.
- **Steps aside:** two typed messages of one conversation, both queued before the worker runs, the older handled first.
  One reply. The older event is done with the mark naming the newer one, no model call was made for it, and the model's
  final user turn holds both texts, oldest first. Repeated at epoch 0, where no history replays.
- **The tie, the newer handled first:** the newer event claims the older one, still `processing` in the batch, and step
  1 stops the older one when its turn comes. One reply holding both. Repeated at epoch 0 (both rows stored before any
  turn), and on an ambiguous number, whose history is always empty.
- **The older one in failure backoff:** it failed with attempts left, then the newer one arrives and runs. The newer one
  claims it; its retry never comes; one reply holds both.
- **The older one left `processing`** by a run that timed out, and the newer one claimed and answered: the older is
  claimed, and the later stale-lock release leaves it untouched.
- **The newer one fails after the claim:** its retry's final user turn still holds the claimed text, found from the
  seed although the claim then finds nothing. Likewise after the older stepped aside. If the newer one dies: the
  hand-over, as today. On a listed number: one staff alert and no hand-over (§X.5).
- **The switch turned off mid-burst:** an older event steps aside, or is claimed by a newer event that then fails, with
  the switch on; the switch is turned off; the newer event runs or retries. Its reply holds both texts, including at
  epoch 0.
- **`cron/event_processor.php` holding the older event:** with its gate check reading false it claims the older event,
  the newer handler claims it before the release, and the release puts it back to `pending` with the mark. Step 1 stops
  it; one reply holds both.
- **Step 2's states:** a newer event `done`, `failed` or `dead` at the older one's check does not make it step aside;
  the older one is answered.
- **The window:** a newer event created more than 15 minutes after the older one: the older does not step aside, and
  the newer does not claim it. Each gets its own reply.
- **A chain:** three messages, each within 15 minutes of the next and more than 15 minutes from first to last, all
  queued: one reply holding all three.
- **Exact marks:** `events` in `sqlite_sequence` is raised so that a later burst's ids include `10n+k` beside an earlier
  `n`. Typed `n` steps aside for H; H fails on its 30-minute backoff; more than 15 minutes later, before H's retry, E
  steps aside for `m = 10n+k`, and `m` answers both. H's retry collects `n` only: E is not in H's final turn, and E's
  text is not answered twice.
- **A message that asks for a person:** "Agent", then a second typed message, both queued: "Agent" gets the fixed
  hand-over, a blank line and the office line, with `needs_human` and one hand-over alert, and no model call; the second
  message is answered on its own. The same with "operator?", and in the tie. The control: a single message
  "Agent\nmy kit is broken" keeps today's result.
- **Voice:** typed A, a voice event V, typed B, all queued before A's retry (A deferred by F20). B's final turn holds A
  then B; V is answered on its own. A newer voice event never makes a typed message step aside, a voice event never
  steps aside, and the claim never takes one.
- **F20's note:** a voice note, typed A parked by F20, typed B before A's retry. A steps aside, its `error` still starts
  with F20's note, and `VoiceSteps`' `f20_text_deferrals` still counts A.
- **A colleague replied in between:** with a cooldown set, A, a colleague's reply, then B. B claims and collects
  nothing, and A is dropped by `answeredMeanwhile`, as today.
- **The pin:**
  - an in-area pin, then a text: one reply, and its prompt holds LOCATION PIN JUST RECEIVED with the "saved for the
    installation team" line and the no-map rule;
  - an out-of-area pin, then a text: the OUTSIDE line;
  - two pins, then a text: the newer pin;
  - a text with its own pin after an older pin: its own pin wins;
  - the switch off: the pin's own turn shows the block (the control).
- **The budget:**
  - with the switch on, a single message of more than 1,500 characters reaches the model uncut;
  - in a burst whose newest message is over 1,500 characters, the older texts are still merged within their own
    budget, and the newest is uncut;
  - in an opening burst whose older texts exceed 1,500 characters, the oldest is dropped whole and the log line counts
    it.
- **Conversation 0:** never checked.
- **The switch off:** two messages give two replies, as today (the control), and nothing is marked or claimed.
- **South Sudan:** with `ai_burst_merge_enabled` stored as `'1'`, two typed messages give two replies, nothing is
  marked, and no burst query is issued.
- **Exits:** a listed number (Part X), a human pause, a colleague's reply: no reply to either message, as today.
- **The log** names the conversation id and the event ids, never the phone or the text.
- **Weakened copies,** each caught:
  - stepping aside for a `done` newer event (caught by the epoch-0 tie);
  - no claim from the newer side (the tie);
  - the claim without step 1's re-read (a double reply in the tie);
  - step 1 stopping only a `done` row (the `event_processor` case);
  - the collection without the seed (steps aside, and the retry after the claim);
  - the collection behind the switch (the switch turned off mid-burst);
  - the chain not followed (the chain);
  - a mark matched with `LIKE` (exact marks);
  - a message that asks for a person merged like any other (asks for a person);
  - a voice event taken into the set (voice);
  - `error` overwritten instead of added to (F20's note);
  - the pin dropped; the oldest pin taken instead of the newest;
  - the handler's own message cut;
  - checking conversation 0;
  - no Uganda gate (South Sudan);
  - marking or claiming with the switch off.
- **No re-point is expected:** the switch is off by default, so the existing tests that queue two events for one
  conversation behave as today. The build runs them to confirm it.

**W.2 — the topic rule** (`test_topic_rule.php`), in three layers. Every assertion follows the caller, never the
medium: the brain's only gate is the setter.
- **The brain**, the `tests/test_ai_security_policy.php:155-175` pattern: with `setTopicRule(true)`, rule 8 is in the
  prompt on all 3 channels × 2 transports × 2 mediums, `email` included, in append and override mode. Without the call,
  or with `false`, it is absent from all of them. A config key named `ai_topic_rule`, set to `'1'`, changes nothing.
- **Each real caller, for the combinations it builds**, on Uganda: the worker (sales, support and account × `whatsapp`
  × `''`) and `web_chat.php` (sales × `web` × `''`) carry rule 8; `tools/ai_eval.php`'s brain carries it. On South
  Sudan none of them does.
- **The email-draft caller** (`InboundMailWorker`, as `cron/inbound_mail.php` builds it) on Uganda: rule 8 is absent,
  also with `ai_topic_rule` set to `'1'` in `kyc_config.json`. So are Test AI's and preflight's brains.
- **Rule 8's text** names no prompt heading, so it is true in both modes: it contains no "ADDITIONAL INSTRUCTIONS", and
  in override mode the prompt contains no heading that mode does not print. Its on-topic sentence names rules 1, 1b
  and 2 and does not restate them: it contains no "not in your DATA)". A weakened copy carrying that narrower
  parenthetical is caught.
- Rule 7's line patterns (`tests/test_ai_security_policy.php:413-425`) still match; the policy's open-gap list is
  unchanged (`:312-341`); the three golden files are unchanged.
- **Weakened copies,** each caught: no Uganda gate in `TopicRule::applies`; the rule in the append prompt only; the
  brain reading a config key; the email caller calling the setter; the old precedence sentence naming a heading.

**W.3 — the office line** (`test_human_ask_office.php`):
- "customer care" and "call me" on Uganda give the fixed hand-over, a blank line and the `OFFICE_LOCATION` row's
  `wa_answer`. The fixture's number is a fictitious one from the tests' own range.
- `wa_answer` empty, with `ai_fact_office` set: nothing is appended. The row disabled, or no `knowledge_items` table:
  nothing is appended.
- **With Part K on and a live quotation,** "customer care" gives the fixed hand-over, a blank line and the office line,
  with `escalate` and `needs_human` kept, no `quoted_reply_text`, and no Part K floor event or alert. Run twice: with the
  office number in `wa_answer` written in a different format from `ai_fact_office`, and with `ai_fact_office` set to
  `omit`.
- On a worker run's first event (an empty `lastSystemPrompt`) the guard does not refuse the reply.
- The line is cut at 400 characters; the row's `answer` field is never sent.
- South Sudan, the website chat and email drafts: unchanged. The email HOLDING sentence is unchanged
  (`tests/test_inbound_mail_worker.php:204-211`).
- **Weakened copies,** each caught: no Uganda gate; the `answer` field used; a fallback to `ai_fact_office`; the line
  appended before the guard; no cap; `wa_answer` left out of the floor's exemption sources (fails both Part K cases);
  the Part K alert sent on the `human_ask` turn (fails both Part K cases).
- **Re-point, if it compares the whole result:** `tests/test_ai_brain.php:31-44` (the short-circuit gains `human_ask`).

**W.4 — the transcription default:**
- Both defaults are `gpt-transcribe`; `OpenAiTranscriber` reads `MediaPolicy`'s constants; the ladder's enum equals
  `MediaPolicy::STT_MODELS`; the tool's message compares with the constant.
- `set ai_media_openai_stt_model whisper-1` prints the line naming the default; `set … gpt-transcribe` prints none; "D2
  says Whisper" appears nowhere in `tools/`. A weakened copy that keeps the old text is caught.
- **Re-points**, each with its reason written in the test: `tests/voice_media/A_policy.php:76-77` (A06),
  `tests/test_openai_transcriber.php:46`, `:53`, `:139`, `:180`, and `tests/voice_media/D_dry_run.php:29`, `:36`, `:41`.

**W.5 — the AI Knowledge Base screen** (`test_ai_knowledge_tab.php`):
- `$_tabFiles` has no duplicate key; `ai_knowledge` opens `tabs/admin/knowledge_base.php` for an admin and is refused to
  a non-admin; `knowledge_base` still opens the Help Guide.
- The screen's links reach the screen; a save and a toggle redirect, and repeating the request after the redirect posts
  nothing. The GET after a save or a toggle shows the confirmation exactly once; a second GET does not. A toggle key
  that fails the pattern is refused, and nothing unescaped reaches the flash box.
- The menu link is drawn for an admin on Uganda only. On South Sudan an admin opening `?tab=ai_knowledge` gets today's
  empty content area.
- **Weakened copies,** each caught: the `*admin` line removed; the duplicate key back; the menu link without its gate;
  the tab key without its gate; the confirmation left in the local variable.

**W.6 — the two-prices warning:**
- A plan refused as `price_differs` appears in the uCRM catalogue screen's warning box with both prices, and the KYC
  form shows the hint line; a plan refused for another reason does not.
- No extra uCRM request is made (counted against the fake uCRM); a last-good read shows its age; with the gate closed
  there is no box.
- **Weakened copies,** each caught: the box listing every not-offered plan; an extra uCRM read.

**W.7 and W.8** are tested in the rehearsal (§M), with the harness's three `-u` controls. ME-CA1 reads:
- NOT YET for a `-1` in the newest cycle with no later entry, when the poll's bound passes with no new cycle;
- a note, not a FAIL, for a `-1` at or before `COPY_AT` with a later cycle;
- FAIL for a `-1` after the copy with a later cycle;
- FAIL through the poll when `followup_send` dies in every cycle and the first read lands after its re-dispatch, never
  PASS; the same for a 600-s job read between its death and the next cycle. A copy without the poll reads NOT YET there
  and is caught;
- for a follow-up job that throws a caught exception after the copy, with `plugin.log` holding no `[master]` lines
  (asserted before the case; in the sandbox master's output goes to `master-*.out`, root
  `scripts/harness/deploy-5.18.106/rehearse.sh:1441-1442`), a PASS whose line carries "errors master caught are not
  judged", asserted, so it never reads as an error-free PASS;
- with 5.18.106's planted-line fixture (lines appended to `plugin.log`, then the file cut back to its mark): with the
  control holding and a `followup_*` ERROR stamped after the straddling cycle, the log's FAIL by job, a schedule line
  with no "not judged" clause, and exit status 1; with the control holding and no ERROR, PASS without the clause.
  Carried 5.18.106 ME-CA1 cases are re-pointed for the extra schedule line;
- PASS with the schedule after the copy.

**Deliberate re-points**, each with its reason written in the test:
- `test_brain_context.php` (15 → 17 keys: `quotation`, `turn`);
- `test_shopbot_payload` (the external brain gets the same context);
- `test_prospect_sales.php` and `test_price_answer.php` (conditional blocks only);
- `test_plan_recommendation.php:75-79` and the Uganda prompt fingerprints (`test_ai_indoor_routers.php:164-192`);
- `tests/test_sales_pilot.php`'s mutant "the photo leaves on the department sales number" (`:1100-1103`), because F-8
  changes the worker's photo line it anchors on. The new anchor carries the class argument, and the replacement changes
  only `$channel`, so the mutant still tests the channel alone;
- **Part X's event type.** `wa.no_ai_alert` is added to two lines that existing mutants quote word for word:
  - `tests/test_event_processor_protected.php`: mutants 2, 8, 9 and 10 get anchors on the new Uganda list in
    `cron/event_processor.php:63`, each still removing or adding only its own type. A `claim` row for `wa.no_ai_alert`
    is added: on Uganda it stays untouched and is never logged as unknown; on `ss_claim` it is still acknowledged as
    unknown. A weakened copy that drops it from Uganda's list is caught, because the event is then acknowledged as
    unknown. The file header's list is updated;
  - `tests/voice_ack/mutants_A.php`, A1b: anchored on the new Uganda return at `workers/AiReplyWorker.php:103`; the
    replacement still removes only the `StaffJobsGate` condition;
  - `tests/voice_ack/A_gates.php:46` (A01): the expected Uganda list gains `wa.no_ai_alert`; A02 still asserts South
    Sudan's `['ai.reply']`.
- **`tests/fixtures/notify_reminders_side.php`** (`:31`), a harness change: it passes its own `$config` and `$dataDir`
  to `WinBack`, with the reason written in it, so the local-form listed and unlisted win-back cases run as Uganda
  through `$winbackRun` in `test_reminders_one_path.php`.
- **`tests/test_cash_in_hand_p3b_south_sudan.php` (W.5).** Its book UP is a Uganda book, drawn as an admin and compared
  byte for byte with f4b4c96. The new menu link is taken out of this tree's pages the way `T3_CATALOGUE_NAV` is, and
  counted: exactly once on each UP page, on no SS or SN page, and on none of f4b4c96's. Two weakened copies of
  `includes/navigation.php`, the link on every tenant and no link, are each caught by the count;
- TL14's declared keys (`lib/SalesFollowupLadder.php:118-187`);
- `test_handover_message`;
- **for O7** (taken, 25 Nov), each with its reason written in the test:
  - `test_quote_tax_line.php` (`APPROVED`, and its tie between the repository's uCRM template and the WhatsApp line).
    The shipped `ucrm_pdf_templates/quotation_uganda/template.html.twig` is **not** changed, because the owner edits
    clause 2 in uCRM (O7), so the tie at `:119-127` is cut on purpose, with that reason written in the test;
  - `test_kit_tax_note.php`;
  - `test_quote_summary.php`;
  - `test_airtel_money.php`;
  - `tests/test_quote_company.php` I3 (`:1765-1808`). It reads the real tree (`:162-163`), so no revert layer reaches it.
    Its re-point hunk is marked, so quote_followup N04 still sees 221c9dc's I3 text through the layer, and `mutants_N`
    QN9's planted edit to `tools/set_config.php` stays outside every r107 marker.

  `QuoteTaxLine.php` (held by `QF_HELD['F']`, `CD_I_KEPT` and `QFU I`), `KitTaxNote.php` and `tools/set_config.php`
  (held by `CD_I_KEPT`, `QF_HELD` F, V and O, quote_followup N04 and `test_quote_company.php` I3) are then declared in
  the r107 layer, with their hunks marked.

**South Sudan:** the differential tests (L01–L03) must show nothing changed.

**Also:** `tests/fixtures/r107_revert.php`, `declared.php` (`lib/ReplyPrivacyGuard.php` is in section V), version pins,
`manifest.json`, `SAFETY.md`.

---

## M. Build order

1. **F-1, F-7 and F-8:** one hunk each (F-8 two: the worker's two image sends, and `NotificationService::sendImage`),
   with their tests.
1a. **X:** `wa_no_ai` (092), the converter with its trunk-0 check, the list check in the worker, `MediaWorker::handover()`
   and the senders, win-back with the tenant passed in, the watchdog filter, the `onDead` re-check and exemption, the
   screen and the Inbox toggle with the session token and the evidence rule, and the alert raised in the webhook and
   routed at the top of `handle()`.
2. **K:** `QuoteInPlay`, `quotedRules`, the floor, `phoneSpans()`, the two marker flags, the alert, the follow-up
   exclusion.
3. **Q:** the builder with its raw-row input, `catalogue.json`, the `turn` leaf, the marker, the prompt replacements, the
   placement, the website chat. O7's phrase removal is built in the same step, but it is **not** behind
   `connection_quote_enabled`: it is live at the deploy.
4. **W.1:** the re-read, the step-aside check, the claim and the collection, behind the Uganda gate; the mark and the
   claim also behind their switch; `asksForHuman` made public.
5. **W.2, W.3, W.5 and W.6;** **W.4** only after step 7's check has read PASS with `gpt-transcribe` (§W.4).

Then: the suite twice; the deploy script's design, as a section of this document reviewed before the derivation, as
docs/75 §L was. That section must name 092: a migration trial of 092 on a copy through the pin's `MigrationRunner`, as
QF-G2 did for 089–091; after the first boot, `_migrations` holds 092 with its md5, its `migration.log` line reads OK, and
`wa_no_ai` can be read the way the worker reads it, with a positive control; any failure is a NO-GO and the rollback.
The rollback instructions it prints say first to turn `ai_burst_merge_enabled` off and drain the marks (§K, before a
rollback).
Then `deploy-5.18.107.sh` derived from 5.18.106's, with W.7; the harness fix W.8; and the rehearsal chained on
5.18.106's.

---

## N. Production values to read before the build

One read-only command, still to be written. None of these can be read from the repository. The values only 5.18.108's
parts need are in docs/77 §N.

- **AI settings:** `ai_hardware_expert` (Part Q needs it on, §K step 2), `ai_sales_on_all_numbers`,
  `bot_instructions_mode` and whether `bot_custom_instructions` is empty (override takes effect only with non-empty
  custom text, `lib/DishNetAiBrain.php:229-232`; then the knowledge base and the business facts leave the prompt),
  `flyer_available`, and whether a flyer file is installed.
- **Operator facts:** `ai_fact_kit_taxes`, `ai_fact_prices`, `ai_fact_delivery`, `ai_fact_payment`, `ai_fact_office`,
  `ai_fact_location_pin`, `stock_statement`, `ai_fact_business_cap`, `quote_company_phone`.
- **The knowledge base (W.3):** whether `OFFICE_LOCATION` is approved, whether its `wa_answer` is set, and its length.
  Never its content.
- **Quotations:** `quote_tracking_enabled`, `quote_chase_enabled`.
- **The tenant (W.1):** whether `tenant_profile` is set in the plugin's own configuration, or Uganda is chosen only
  through the vault. Only then can the Uganda gate read false on a doubt (`lib/TenantProfile.php:60-65`, `:88-90`).
- **Hand-overs and alerts:** `ai_handover_message` (the holding line), the voice rung in use, `alert_whatsapp`,
  `alert_patience_minutes`.
- **The old auto-reply path (Part X):** `wa_bot_enabled`, `wa_auto_reply_enabled`, `wa_sync_enabled`, whether
  `wa_webhook_secret` is set, and whether anything posts to `wa_webhook.php`.
- **The catalogue:** the exact uCRM names, prices and order of the two kits, the installation, the two Pivot Mounts and
  the two plans, and whether any name is duplicated. **The installation link:** whether the 5.18.102 `install` link is
  `ok` for the quoting organization on Uganda. **The uCRM catalogue switch** (W.6): on or off.
- **Bursts (W.1):** in the last 7 days, how many typed `ai.reply` events had a newer typed one for the same conversation
  within 15 minutes. Counts only.
- **Transcription (W.4):** step 7's check result, and the `provider_model` of the last transcribed voice notes. Counts and
  model names only.
- **Optional (W.8):** the real `ucrm` container's PATH (`docker exec ucrm printenv PATH`).

---

## O. Decisions for the owner

**Answered on 25 Nov** (the owner took each recommendation):

| | Question | Answer |
|---|---|---|
| **O7** | "Nothing is added on top." is printed by one code text (`QuoteTaxLine`) in five places: the uCRM quotation's WhatsApp message; the app and KYC quotation message; the plugin's own fallback Uganda quotation PDF (item 1, for quotations with no tax rows); the description of the price fact in the settings tool; and the default kit note. The settings tool's description (`tools/set_config.php:151-152`) and the kit note (`lib/KitTaxNote.php:29`) each hold their own literal copy, not `QuoteTaxLine::TEXT`; the kit note's default reaches only an install with `ai_fact_kit_taxes` unset, and the live install has it set (§D.3, read again in §N). The AI quotation says "Transport is charged extra." | **Drop the phrase from all five.** The live facts dropped it on 20 Nov. The uCRM template's clause 2 stays the owner's to edit in uCRM. |
| **O10** | Who is alerted about a quoted customer: the quotation's owner (KYC salesperson, then the lead's assigned salesperson, then the sender), or always the person who pressed send? | **The quotation's owner.** Quotations the system sends have no human sender. |
| **O11** | A customer who has already chosen one kit: (a) both options in full, Standard first; or (b) the chosen option in full plus a short line for the other? | **(a), both options in full, Standard first**, as recorded ("two options"). The live v9 text asks for (b) when the model types the quotation itself; §K step 2 replaces that paragraph when Part Q goes on. |
| **O13** | The "assistant off" list: should offers and the win-back message ("We miss you", sent to customers whose service ended 7–10 days ago) also stop for those numbers? | **Yes, stop both.** |

**Moved to 5.18.108** with their parts, still open: O1, O1b, O1c, O2, O3, O4, O5, O6, O8, O9 and O12 (docs/77 §O).

**Defaults taken in this design, to be confirmed by the owner before the build.** On 25 Nov the owner said to go with
the recommendations for the plan and the split; these defaults were set in the design afterwards, and W.3's office line
is recorded as a default to confirm. Any can be overruled:

| | Default | Instead, if the owner prefers |
|---|---|---|
| **W-O1** | One reply to a burst: a message not yet answered is answered together with the next one when the next comes within 15 minutes; voice notes are never merged; the switch is off at the deploy and turned on in §K step 3. | another window; on at the deploy |
| **W-O2** | The topic rule on WhatsApp and the website chat, Uganda only; not in email drafts, which staff read before sending. | email drafts too |
| **W-O3** | "Customer care", "call me" and the other hand-over phrases get the office line after the fixed hand-over. Only that hand-over gets it. | no office line; or also the model's own hand-overs |
| **W-O4** | The Help Guide stays admin-only (F-9), and `api_docs` keeps its `settings` gate (F-10). Both are shared with South Sudan. | the Help Guide open to every signed-in user again, as before 3 Sep |
| **W-O5** | `gpt-transcribe` becomes the code's default only if step 7's check passes with it. | keep `whisper-1` as the code's default |
| **X-O1** | While the "assistant off" list cannot be read, a conversation whose event dies is not marked red; the `onDead` alert and the watchdog are the signals (§X.5). | mark it red (`needs_human`, without `wa.escalation` and without the holding line), accepting F-3: its enquiry ladder then stays blocked |

---

## P. Known limits (recorded, not fixed here)

The limits of Parts H and S are in docs/77 §P.

- **One reply to a burst (W.1)** is decided before the model is asked. A newer message that arrives while the older
  one's reply is being written still gets its own reply. An older message waits on the newer event: up to about 10
  minutes after a worker timeout or a pause that ends early, and up to about an hour while the newer event's retries run
  (about 36 minutes of backoff, plus up to one master cycle per retry). If the newer event dies, the older one gets the
  hand-over (or, under §X.5, a staff alert), not an answer. Older texts beyond 1,500 characters are dropped whole,
  oldest first; in a burst that opens a conversation, or for an identity that cannot be replayed, the model does not see
  them. A message more than 15 minutes from the next is answered on its own, and so is a message that asks for a person.
  Voice notes are never merged. An older event whose reply went out but whose handler then failed is answered again, as
  today. A rollback, or a worker run whose Uganda gate reads false on a doubt, inside a burst can leave a marked message
  gathered by nobody (§W.1); before a rollback the switch is turned off and the marks drained (§K), and §N reads whether
  the gate can doubt at all.
- **The topic rule (W.2)** is prompt-level, like every absolute rule. No stored setting reaches any brain; only the
  worker, the website chat and the two checks set it. Email drafts never get it.
- **The office line (W.3)** is admin text, cut at 400 characters and judged by Part K's floor like every code-appended
  block. It follows only the customer-asked hand-over, not the model's own hand-overs or the holding line.
- **The two-prices warning (W.6)** is seen only on two screens. The assistant and the website still show the service
  plan's price.
- **ME-CA1 (W.7)** sees a job whose latest run died. The schedule keeps only the latest run, so a death followed by a
  rerun that returned before the check is not seen. A single read can show NOT YET for a follow-up job that dies on
  every run (most reads for `followup_send`, about half for the 600-s jobs); `--after-only` polls for the next cycle,
  and whether that poll always lands in time is not established. Errors master's `try`/`catch` catches are not judged
  where `plugin.log` holds no `[master]` lines, and the PASS line says so.
- **The "assistant off" list (X)** must be readable: while `wa_no_ai` cannot be read, every Uganda AI reply stops.
  The staff signals are the `onDead` alert, the watchdog, and the dead-letter alert if `whatsapp_admin_phone` is set.
  The conversations are not marked red (X-O1).
- **F-9 and F-10** (§J) are recorded, not changed.

- **The website chat** has no reply guard (F-5) and no Part K: a visitor who holds a quotation can still get prices
  there, now with code-built totals.
- **A quoted customer's mixed reply** is replaced whole by the floor when the model types a figure anyway. The general
  answer and any lead are then lost.
- **No name↔price check in the guard.** Outside the code-built block, the model can still put a listed price on the
  wrong line.
- **Quoted customers are matched by exact phone.**
- **The scan's SQL holds back** a converted quotation until its `valid_until`.
- **The live settings text until Part Q is on.** The 21 Nov stop-gap typed the figures. Since v8 (23 Nov) the text types
  none: every price comes from uCRM through the price list, and it tells the model to hand over when the price list
  disagrees. v9 (23 Nov, live) asks for one chosen option in full and the other in one short line, and has no TOTAL WITH
  THE FIRST MONTH, because the guard never adds a monthly plan to one-time items. Part Q's switch-on step replaces its
  QUOTATIONS paragraph (§K, step 2).

---

## R. Review record

Verdicts: C = confirmed, P = partly confirmed. Since the re-scope of 25 Nov, a reference below to §C, §F, §G or §H
means that section in docs/77.

### R.1 Review 1 (41 findings)

| Finding | Verdict | Taken in |
|---|---|---|
| R1, H-4 — the record was written before the flyer; the text row's id was discarded | C, C | §C.1, §C.2 |
| R2, H-3 — the allowlist was not in scope, and holds customer words and prompt digits | C, C | §C.1 |
| R3, H-1 — WhatsApp timestamps in gate 3; no supersede by an unpriced reply; gate 6 too narrow | P, C | §C.2, §C.4 gates 3, 4, 7 |
| R4 — `nonNegotiable()` is not on the append path | C | §E.2.1 |
| R5, K-3 — `<<QUOTE>>` escalated a quoted customer | C, C | §E.2.4 |
| R6, Q-1 — "Nothing is added on top" under the transport line | C, C | §D.3, O7 |
| R7, Q-4 — no way to find the installation | P, C | §D.2 |
| R8, Q-2 — the block's totals refused when restated | C, C | §D.5, §D.6 |
| R9 — a marker-only reply was handed over | C | §D.4 |
| R10, N-3 — `ai_lead_capture` not a precondition | C, C | §G.2, §H.1, §K, §N |
| R11 — the sleeper inherits the worker's lock | C | §C.3.1 |
| R12 — the per-minute sleeper left rows to master | C | §C.3 |
| R13, QN-1 — two code-added questions | C, C | §G.2 |
| R14 — F-4 and F-6 labels | C | §B.3 |
| R15 — the schedule's route; the doctor; `MAX_ATTEMPTS` | P | §B.5, §F.1 |
| R16 — more readers of the follow-up label | P | §J F-1 |
| R17 — `[AUDIO]` is a voice note; reactions are never stored | C | §G.1 |
| K-1 — the floor caught payment numbers, phones, balances | C | §E.2.3 |
| K-2 — flyer, "million", block not suppressed in code | C | §E.2.2, §E.2.3 |
| K-4 — no alert in the compliant case | C | §E.2.5 |
| K-5 — "the salesperson who sent it" | P | §E.1, O10 |
| K-6 — the owner's first name had no route | C | §E.1, §E.2.1 |
| K-7 — the scan exclusion in SQL | P | §E.3 |
| H-2 — window and caps departed from the record | P | §C.4, O1, O1b, O2 |
| H-5 — uncertain and stale claims | C | §C.3, §C.4, §C.5, §P |
| Q-3 — marker placement; Extra instructions feed the website | C | §D.4, §D.5, §K |
| L-1 — a synced lead identifies the caller | P | §G.2, §H.4 |
| L-2 — no provenance | C | §H.3 |
| L-3 — staff alert flood | P | §H.1, O4, §N |
| N-1 — no selling-number test | P | §G.2 |
| N-2 — may-have-been-sent; across numbers | P | §G.2, §G.3 |
| S-1 — several same-day reminders a day | C | §F.3 |

### R.2 Review 2 (34 findings; several found by both lenses)

| Finding | Verdict | Taken in |
|---|---|---|
| F1, N2-01 — the id-based installation lookup could not see ids; two install records merged | C, C | §B.1.3, §B.3, §D.2, §L |
| F2 — `permittedPrices()` holding block figures contradicted "allowlist unchanged" | C | §C.1, §D.6 |
| F3, N2-02 — a quoted customer's marker-only reply bypassed Part K | C, C | §D.4, §E.2.2, §L |
| F4 — one sleeper per run left later buckets to master | P | §C.3.1, §P |
| F5, N2-03 — claim then gates made gate 13 refuse every row; gate 15 left rows claimed | C, C | §C.3.4, §C.4 |
| F6 — gate 4's time clause missed a stored photo | C | §C.2, §C.4 gate 4 |
| F7, N2-07 — the floor exempted every operator fact, delivery charges included | C, C | §E.2.3, §N |
| F8 — `IDENTIFIER_SHAPES` is private | C | §E.2.3 (`phoneSpans()`), §L |
| F9, N2-06 — "m" caught cable lengths | C, C | §E.2.3, §L |
| F10, N2-08 — per-turn flags had no route into the prompt | C, C | §A, §D.4, §G.2, §L |
| F11 — exit (b) needs data only the worker has | C | §G.2, §H.4 |
| F12, N2-05 — the LEAD line needs `ai_qualification` and no override | C, P | §G.2, §G.4, §K |
| F13 — a number-only lead created first lost the line owner's assignment | C | §H.1 |
| F14 — telling `<<QUOTE>>` from `<<ESCALATE>>` by reason text | C | §E.2.4 |
| F15 — the 24-hour floor would change South Sudan | C | §F.1 |
| N2-04 — O1b promised what the ledger cannot do | C | §C.5, §C.7, O1b |
| N2-09 — the website bailed on a marker-only reply | C | §D.4 |
| N2-10 — Part K cannot apply on the website | C | §A, §D.4, §E.5, §P |
| N2-11 — a quotation sent on the support number during the wait | C | §C.4 gates 3, 8; §C.7 |
| N2-12 — append mode kept the build-it-yourself rules | C | §B.3, §D.5 |
| N2-13 — the marker was sales-only | C | §D.4, §K |
| N2-14 — Q before K let a quoted customer get a new quotation | P | §D.4, §E.1, §K |
| N2-15 — exactly one row must match | C | §D.2, §L |
| N2-16 — the one-option form departed from the request | C | §D.3, O11 |
| N2-17 — the organization rule reversed 5.18.99 without a decision | C | §H.8, O6 |
| N2-18 — "not back-filled" was not enforced | C | §H.10, O8 |
| N2-19 — the 30-minute refusal at the top of the range | P | §C.3.2 |

### R.3 Review 3 (20 findings)

| Finding | Verdict | Taken in |
|---|---|---|
| R3-01 (fixes), R3-05 (new) — the support and accounts numbers got neither the id-bearing rows nor the `turn` leaf | C, C | §D.2, §D.4, §L |
| R3-02 (fixes) — a quoted customer's `<<QUOTE>>` still got the holding line | C | §D.4 step 2, §E.2.4 (`filled`), §L |
| R3-03 (fixes) — `dueAt` reads the constant for the anchor | C | §F.1, §L |
| R3-04 (fixes), R3-08 (new) — `turn.lead_ask` cannot check reply facts before the model is asked | C, C | §G.2, §G.3, §L |
| R3-05 (fixes) — no screen saves the sync switch; no rule for a missing stamp | C | §H.10, §L |
| R3-06 (fixes) — exit (b) on the accounts number contradicts its ACCOUNT block | C | §G.2 (sales only), §H.4, §L |
| R3-01 (new) — a placeholder requirement could never be replaced | C | §H.1, §L |
| R3-02 (new) — clearing the marker on an assigned lead would escalate at once | C | §H.1, §L |
| R3-03 (new) — the "Hi" had no daily limit and no decision about it | C | §C.4 gate 14, §C.5, O1c |
| R3-04 (new) — "never held back" contradicted spacing | C | §C.5, §C.7 |
| R3-06 (new) — the block was placed on an unoffered marker | P | §D.4 step 3, website, §L |
| R3-07 (new) — an unpriced mount gave totals that were too low | C | §D.2, §L |
| R3-09 (new) — the stop-gap paragraph contradicts the marker line during the switch-on test | C | §D.5, §K.2 |
| R3-10 (new) — no tests for most of the "Hi" gates | P | §L H |
| R3-11 (new) — the default question turns each answer into a hand-over | P | §G.3, O12, §L |
| R3-12 (new) — O7 left out three places the phrase prints, and the tests that pin it | C | O7, §L |
| R3-13 (new) — a constant cannot call `phoneSpans()` | C | §E.2.3 |
| R3-14 (new) — a third doctor reader of the schedule | C | §F.1, §L |

### R.4 Review 4 (19 findings)

Two lenses: Part X and F-7, reviewed for the first time, and whether review 3's fixes were taken correctly. 14 confirmed,
4 partly confirmed, 1 refuted.

| Finding | Verdict | Taken in |
|---|---|---|
| X-1 — `MediaWorker`'s hand-over rung and failure branches call `Handover::escalate` for a listed number | C | §X.3 (`MediaWorker::handover()`), §L X |
| X-2 — the win-back message still goes to a listed number | C | §X.3, O13, §L X |
| X-3 — the alert's quotation-owner rung needs Part K, which may be off; the line owner is not yet resolved at the check | C | §X.4 (recipients), §M 1a, §L X |
| X-4 — the watchdog pages the central number for every unanswered message from a listed number | C | §X.4 (watchdog), §L X |
| X-5 — captionless media, voice notes and messages to a number whose assistant is off never reach the worker, so no alert | C | §X.4 (raised in the webhook), §L X |
| X-6 — no rule turns a typed number into international digits; the Inbox toggle can store a `web:` or `@lid` key | P | §X.1 (the converter), §X.2, §L X |
| X-7 — no rule for an unreadable list; `onDead` would hand the chat over with the holding line | C | §X.5, §L X |
| X-8 — the Inbox toggle needs a request check: Inbox actions run before the global CSRF gate | C | §X.2, §L X |
| X-9 — the old auto-reply path (`WaMessageProcessor`) checks no list | P | §X.3 (inert from cron in 5.18.106; checked if §N shows it live), §N, §L X |
| F7-1 — `sendImage` sends as `CLASS_PROACTIVE`, so a STOP drops the assistant's photo and flyer | P | §B.7a, §J **F-8**, §M 1, §L F-8 |
| F7-2 — §B.7a left out that a dead reply is handed over; the first F-7 fix removed that staff signal | C | §B.7a, §J F-7 (one hand-over, no holding line), §L F-7 |
| F7-3 — `ContactOptOut::add()` has a third caller, the spoken STOP | C | §B.7a |
| R4F-01 — the alert clock ignored a later drip or stale reassignment | C | §H.1 (the later of `alertable_at` and `assigned_at`), §L |
| R4F-02 — under the "add" shape the 24-hour floor contradicted the step-2 test | C | §F.1 (floor for "replace" only; gap formula under "add"), §L S |
| R4F-03 — O1c (a) named no cap, no day and no rule for counting the "Hi" itself | C | §C.6 (O1c in code terms, `price_hi_daily_cap`), §L H |
| R4F-04 — a salesperson typing a name leaves `number_only` set | **refuted** | the Sales → Leads form posts `update_lead`, which nothing handles, so no staff path writes a name. Recorded as a note for any future lead editor in §H.1 |
| R4F-05 — step 2 also replaced the brain's own hand-overs with the quotation text | C | §D.4 step 2, §L K |
| R4F-06 — "step 4's colleague line" after renumbering; the §L marker bullet was unconditional | C | §D.4 step 3, §L Q |
| R4F-07 — the sync cutoff breaks existing tests that switch the sync on with no stamp | P | §H.10 ("on Uganda" = `TenantProfile::current`), §L re-points (three Uganda cases; the South Sudan files unchanged) |

### R.5 Review 5 (16 findings)

Two lenses: review 4's changes to Part X, F-7 and F-8, and whether review 4's R4F fixes were taken correctly. 15
confirmed, 1 partly confirmed, none refuted.

| Finding | Verdict | Taken in |
|---|---|---|
| R5-X8 — `csrfToken()` is one daily value printed on the public login form, so it does not protect the toggle | P (its first fix, requiring a Bearer token, would refuse every real toggle: nothing sets `hybrid_token`) | §X.2 (a session-bound token and `application/json`; the AI setup screen too), §L X |
| R5-X6a — `evo_jid` is empty for `@lid` chats imported by `importEvoMessage`, so the toggle would accept them | C | §X.2 (the evidence rule), §L X |
| R5-X6b — "+, country code, 0, national number" is kept as typed and stored with the trunk 0 | C | §X.1 (the trunk-0 check, the stored form shown back), §L X |
| R5-X7a — `WorkerDefer` never reaches `onDead`, so an unreadable list would park every message with no alert | C | §X.5 (the counted throw only), §L X |
| R5-X7b — an unreadable list in `MediaWorker::handover()` dropped every hand-over silently | C | §X.5 (one central alert per media row), §L X |
| R5-X5 — the alert event would be dropped by `handle()`'s field check, and a dead one handed over | C | §X.4 (routed at the top of `handle()`, payload), §X.5 (`onDead` exemption), §L X |
| R5-X2 — `WinBack` has no tenant, so a local-form phone gets South Sudan's code | C | §X.3 (config and data directory passed in; refused phones held), §L X |
| R5-F8 — F-8 breaks the photo mutant's anchor in `test_sales_pilot.php` | C | §L re-points |
| R5F-01 — the drip case looked only at the run that cannot escalate | C | §L N and L (runs at 0, +5, +45, +60) |
| R5F-02 — a leftover "same-day floor with the switch on" bullet contradicted the "add" shape | C | §L S (deleted) |
| R5F-03 — O1c (a) was described as the reminders' limit, which today is `followup_daily_cap` | C | §C.6, O1c, §N |
| R5F-04 — with the ledger off, neither O1c count ever reaches its limit | C | §C.4 gate 1, §C.6, §K step 3 (now docs/77 §K, the H step), §L H |
| R5F-05 — the seeded leads have no `created_at`, so a stamp alone does not re-point them | C | §H.10 (no creation time = `skipped`), §L re-points |
| R5F-06 — §H named no switch for provenance and Lead source, so the J8 comparison could not hold | C | §H (a switch per item), §L |
| R5F-07 — the review-4 counts contradicted the table | C | status block, §R.4 |
| R5F-08 — §A had no F-8 row and left it out of the build order | C | §A |

### R.6 Review 6 (8 findings)

Two lenses: review 5's changes to Part X, and its other changes. 5 confirmed, 3 partly confirmed, none refuted.

| Finding | Verdict | Taken in |
|---|---|---|
| R6-X8a — only the Inbox made the session token, so the AI setup screen had none, and an empty token could match an empty field | P (the toggle itself is also held by its `application/json` rule) | §X.2 (one helper on both pages; refuse a missing or empty token before comparing), §L X |
| R6-X8b — no listed case could catch a copy that checks `csrfToken()`: the text/plain case is refused by `Content-Type` first | P | §L X (an `application/json` case with a valid `csrfToken()` and no session token) |
| R6-X5 — the alert event's `phone` key meant `onDead` returned before the exemption mattered | C | §X.4 (`customer_phone`), §X.5 (where the branch and the re-check sit) |
| R6-RP1 — `WinBack`'s new constructor breaks the reminders fixture | P (the `onDead` part needs no re-point once the branch's place is stated) | §X.3 (trailing optional parameters), §X.5, §L re-points |
| R6-RP2 — adding the event type breaks four event-processor mutants, A1b and A01 | C | §L re-points |
| R6F-01 — `created_at` is in the writing process's zone, so a lead created just after switch-on could be skipped for good | C | §B.6, §H.1 (`alertable_at` in UTC), §H.10 (`created_at_utc`), §L |
| R6F-02 — switching L off would release marked leads into the drip, or hide cleared ones for good | C | §H (only creation obeys the switch; the rest follows the stored marker), §L |
| R6F-03 — O1c and O1b said the hourly cap holds the "Hi"; it never counts one | C | O1b, O1c |

### R.7 Not yet reviewed

- **Review 8's changes** (§R.10): §W.1 again (the seed; only the writes behind the switch; a message that asks for a
  person never merged; marks parsed as integers and read whatever the status; the delays), rule 8's wording, §W.7's
  poll and PASS clause, the two sentences of §E.2.1, §D.5's marker line and its paragraph, §E.2.5's fourth trigger and
  hand-over rule, "our sales team" in place of the owner's name, Part X's statements in the status block, §X.3 and §P,
  §O's defaults marked to be confirmed, and their rows in §A, §K, §L, §M, §N and §P.

### R.8 The re-scope (25 Nov)

The owner split the next version in two (25 Nov). The changes, each made by an asserted edit:
- **Moved to docs/77 (5.18.108):** §C (Part H), §F (Part S), §G (Part N), §H (Part L), the bodies of their steps in §K
  and §M, and their decisions O1, O1b, O1c, O2–O6, O8, O9 and O12, word for word; their tests in §L and their limits in
  §P, word for word apart from one paragraph review 7 found dropped and restored (R7-R11); their values in §N, as a
  selection. Their sections here are stubs.
- **In the kept parts:** §D.4's `turn.lead_ask` line and the selling predicate (now stated in §D.4); §D.6's reference to
  §C.1; §E.1's readers; §X.3's and §X.5's references to the "Hi"; §L Q's `turn.lead_ask` case; §L X's "Hi" case; five
  re-points that only 5.18.108 needs (`test_ai_lead_capture`, `test_ucrm_lead_sync` §3, the sync cutoff,
  `test_master_104` and `tools/cron_status.php`, the cadence tests). O7, O10, O11 and O13 are recorded as answered.
- **The Standard plan's name** follows uCRM's rename to "up to 350 Mbps" (§D.2); the exact names are read before the
  build (§N).
- **§K step 2 and §D.5** name v9's QUOTATIONS paragraph (v10), since the 21 Nov stop-gap they named is gone (§D.5
  corrected in review 8, R8-B8); **§P** says so.
- **Added:** Part W and the asked-for items 8–15; §J's F-9 and F-10.

### R.9 Review 7 (29 findings, 25 Nov)

Two lenses: Part W against the code (W), and the re-scope, review 6's changes and the owner's intent (R). Every finding
then went to a separate checker that tried to refute it. 13 confirmed, 14 partly confirmed, 2 refuted.

| Finding | Verdict | Taken in |
|---|---|---|
| R7-W1 — an older message stepped aside for a newer event that had already run without its text, and was then answered by nobody (the tie, a worker timeout, a send-failure retry; at epoch 0 never seen at all) | C | §W.1 rewritten: the newest message claims the older unanswered ones before it asks the model; `done` no longer counts; step 1 re-reads the row; §L W.1 |
| R7-R2 — the same defect from the rollout lens, plus out-of-window and voice-bound cases and the `onDead` sentence under Part X | C (the 22 Nov report is not an opening burst or a tie: not established) | §W.1 (claim, chain, `onDead` under §X.5), §L W.1 |
| R7-W2 — stepping aside had no window while the merge had one, and the cap dropped stepped-aside text | P (the backoff example is the `done` clause's) | §W.1 (one window on both sides; the chain has none; the budget drops only older texts, whole), §P |
| R7-W3 — "the last `ai.reply` not superseded" let a voice event become the bound | C | §W.1 (the set is the claim plus the chain of marks; no bound), §L W.1 |
| R7-W4 — the merge dropped an older pin's `location`, and with it the pin block's "no map" rule | C | §W.1 (the newest pin in the set is carried; the turn's own wins), §L W.1 |
| R7-R10 — the same, from the rollout lens | C | as R7-W4 |
| R7-W5 — "late by up to about 5 minutes" was too low | C | §W.1 (about 10 minutes after a timeout or a pause; about 36–40 while the newer event retries), §P |
| R7-W6 — the cap's treatment of the handler's own message was unspecified | P | §W.1 (the own message is never cut; the older texts have their own budget), §L W.1 |
| R7-W7 — W.1 had no Uganda gate | C | §W.1 (the Uganda gate first), §L W.1 (South Sudan with the key stored) |
| R7-W8 — the merge bypassed the identity-bound history | **refuted**: the merged texts are this turn's own unanswered input, not replayed history | — |
| R7-W9 — writing the mark over `error` erased F20's note | P (the health screen half is not a defect) | §W.1 (the mark is added after any note), §L W.1 |
| R7-W10 — a stored `ai_topic_rule` reached email drafts, and the two consistency checks did not ask the live prompt | P | §W.2 (a setter; `TopicRule::applies` called by the worker, the website chat, `ai_eval` and the root `ai_check`), §L W.2, §P |
| R7-W11 — rule 8's precedence sentence named a heading override mode never prints, and outranked rules 1, 1b and 2 | P | §W.2 (rule 8's text) |
| R7-W12 — the W.2 matrix could not be driven through the real callers | P (email is a caller property, not a medium property) | §L W.2 (three layers) |
| R7-W13 — the `ai_fact_office` fallback sent prompt text to the customer | P (latent: the live row has a `wa_answer`) | §W.3 (no fallback), §L W.3 |
| R7-W14 — the tool still printed "D2 says Whisper" | C | §W.4, §L W.4 |
| R7-W15 — the confirmation was lost after the redirect, and §K named the wrong menu | C | §W.5 (flash, the key checked, 302), §K step 5, §L W.5 |
| R7-W16 — the catalogue warning disagreed with link mode | **refuted**: both modes judge with the same `planRules` | — |
| R7-W17 — the rebuilt ME-CA1 read PASS over a throw master caught | P (the old-class throw falls in the excluded straddling cycle) | §W.7 (the PASS line says what it proves; an exact FAIL rule; the log's FAIL kept), §L W.7, §P |
| R7-R1 — v9's DATA PLANS and NAME AND EMAIL contradicted Parts K and Q, and nothing outranked them | P (no leak; a mixed answer can be lost) | §E.2.1 and §D.5 (precedence sentences in code), §K steps 1–2 (v10's three edits, live checks), §L K, §P |
| R7-R3 — an unreadable list silences every Uganda customer, unstated; the alert key and the watchdog's behaviour were open | P (escalating every conversation is the owner's choice) | §A, §X.4 (watchdog), §X.5 (blast radius, per-conversation key), §M (092 checked), §L X, X-O1 (open for the owner); the status block, §X.3's heading and §P only in review 8 (R8-B2) |
| R7-R4 — `tools/set_config.php` holds its own copy of the O7 phrase and was not named | P (the two other files are judged through the layer) | O7, §L re-points (`test_quote_company.php` I3), §I.6 |
| R7-R5 — the W.5 link breaks the South Sudan control, and the tab opened on South Sudan | C | §W.5 (the key gated), §L re-points |
| R7-R6 — Part K's floor could replace W.3's office line | P (the order was stated; the exemption was missing) | §E.2.3, §W.3, §L W.3 |
| R7-R7 — O7 is live at the deploy with no switch, unstated; W.2 and W.3 were called fixes | P | status block, §M step 3, §K step 0, O7, §L (the template tie cut on purpose) |
| R7-R8 — §D.6 named a helper 5.18.107 does not have | C | §D.6 |
| R7-R9 — Part Q needs `ai_hardware_expert`, unstated in §D.7 and §K | P | §D.7, §K step 2, §N |
| R7-R11 — docs/77 lost a paragraph, two §N values, and carried stale step numbers | C | docs/77 §L, §N, §H.3, §K, §M; §N here; §R.5; §R.8 |
| R7-R12 — two root scripts were cited under a path rule that cannot reach them | C | the path rule, §W intro, §W.7, §W.8, §D.6 |

### R.10 Review 8 (16 findings, 25 Nov)

Two lenses on review 7's changes: §W.1–§W.7 against the code (A), and whether review 7's fixes were taken correctly and
completely (B). Every finding then went to a separate checker that tried to refute it. 7 confirmed, 8 partly confirmed,
1 refuted.

| Finding | Verdict | Taken in |
|---|---|---|
| R8-A1 — the collection had no seed, so the events that stepped aside for a handler, and those an earlier attempt claimed, were never gathered | C | §W.1 (the set starts from the handler's own id; the retry restated), §L W.1 |
| R8-A2 — an event marked while W.1 was on was orphaned if the newer one ran with W.1 off, under a doubtful gate, or after a rollback | P (the step-2 defer would erase F20's note; the gate doubt is not established as reachable) | §W.1 (only the writes obey the switch; step 1 and the collection follow the marks), §K (before a rollback), §M, §N, §P, §L W.1 |
| R8-A3 — a merged "Agent" no longer met `asksForHuman`'s end-anchored pattern, so the hand-over was lost | C | §W.1 (a message that asks for a person is never merged; `asksForHuman` public), §L W.1 |
| R8-A4 — how a mark is matched was unspecified, so `#1` could match `#12` | P (unreachable in production; a specification gap) | §W.1 (marks parsed as integers; the collection scoped to the conversation), §L W.1 |
| R8-A5 — `cron/event_processor.php` can put a claimed event back to `pending`, and step 1 stopped only a `done` row | P (the "one lock" sentence is accurate; the fix by locker would break the timed-out case) | §W.1 (step 1 and the collection read the mark whatever the status), §L W.1 |
| R8-A6 — "up to about 36–40 minutes" left out the wait for the next worker run | C | §W.1, §X.5, §P (up to about an hour without spawns) |
| R8-A7 — rule 8's parenthetical narrowed rule 1b's sources | P (the BUSINESS FACTS half is rule 1b's own question) | §W.2 (rules cited by number only), §L W.2 |
| R8-A8 — ME-CA1's FAIL rule could not reliably see `followup_send` dying on every run, and "conclusive in --after-only" was not true | P (an age bound does not work; a poll does) | §W.7 (the poll in `--after-only`), §L W.7, §P |
| R8-B1 — the QUOTATION ALREADY SENT sentence put an answering duty above every rule, rule 8 included, and "comes last" was false in override mode | P (the marker line's bans may outrank built-in rules) | §E.2.1 (precedence on the price ban only; the answering duty outside it), §D.5 (bans only; where the settings text sits in each mode), §L K |
| R8-B2 — R-3 was taken only in part, and X-O1 was filed under an owner statement that predates it | C | status block, §X.3, §P, §O (defaults to be confirmed), §R.9 |
| R8-B3 — the precedence-sentence test named a fixture that does not exist, and two sentences that can never be in one prompt | C | §L K, §L Q |
| R8-B4 — the alert's fourth trigger had no defined match and no test | P (the W.3 assertion follows from the words) | §E.2.5 (the match; no Part K alert on a hand-over turn), §L K, §L W.3 |
| R8-B5 — quotedRules told the model to name a salesperson that absolute rule 4 forbids | C (pre-existing) | §A, §E.1, §E.2.1 ("our sales team" only; the name stays in code), §K step 1, §L K |
| R8-B6 — the PASS line always said caught errors are not judged, while the kept log rule judges them | P (the rehearsal's log state is measured) | §W.7 (the clause follows the log reading), §L W.7 |
| R8-B7 — the H1–H3 renumbering collides with §H.1–§H.10 | **refuted**: no string has two meanings | — |
| R8-B8 — §D.5 still named the 21 Nov stop-gap | C | §D.5, §R.8 |
