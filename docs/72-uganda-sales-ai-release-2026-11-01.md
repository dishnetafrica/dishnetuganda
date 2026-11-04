# 72 — Uganda: one release for sales — the catalogue straight from uCRM, an AI that hears, sees and reads, quotes chased, a daily list and a read-only report (design, 1 Nov 2026)

Status: **decisions recorded; 5.18.103 built and tested (§G.16), pushed and DEPLOYED 3 Nov (copy 09:01:02 UTC; root
`docs/07`), its switch off until an admin turns it on.** It was cut on the then-live release, `release/5.18.102`
(`1a0ea5f`), never the development branch's tip: that tip lacks 5.18.99–5.18.102 (§C.4).

## A. The request (the owner, 1 Nov 2026)

- *"remove all the hardware and internet plans for plugin we have and use what is in the ucrm make it simple"*, and
  *"show all the things of hardware … in plugin as well same like website and give standard one click predefined
  quote"*. Linking each plugin row to a uCRM item (5.18.102, D14) proved hard to do by hand.
- *"make our ai smart enough that it can handle voice message send by the customer and location they are sending and
  documents if they sent"*, with South Sudan's n8n bot as a reference only (*"ugadna one is best"*).
- *"auto follow … otherwise our all advert money is in lost … how many people we send quote but we never followed
  up"*: measure, across every number connected to the sales and accounts AI, the quotes that were never followed up,
  and plan the follow-up as a sales expert would.
- *"send marketing message to all the customer in ucrm … follow up on email as well … increase our follow up and after
  sales relation with customers"*, as Starlink does for its customers.

## B. Decisions (the owner, 1 Nov 2026, each chosen from the options put to them)

| # | Decision |
|---|---|
| D1 | **Plans and hardware come from uCRM**, not from the plugin's own lists, for the KYC form and its quotation; a standard quotation can be made in one click (its contents: §E, open) |
| D2 | **The AI service for voice notes, photos and documents is OpenAI**, as in South Sudan: Whisper for voice, GPT-4o for images and PDFs. The customer's voice notes, photos and documents are sent to OpenAI to be read |
| D3 | **A quote with no reply is chased on day 1, day 3 and day 7**, from the number the customer spoke to, 08:00–20:00 Kampala, never on Sunday, stopping on any reply, a payment, STOP or a person taking over; each salesperson also gets a daily list of open quotes |
| D4 | **One release, switched on step by step**: every new part ships OFF and is turned on one at a time, each with its own check |
| D5 | ~~Until then, 5.18.102 stays live and the admin links its 5 plans~~ — replaced by D6 the same day |
| D6 | **5.18.102 stays live, its plans not linked; until the next build no quotation is made from the plugin** — the owner, 1 Nov: *"i dont want to link plan lets focus on next build where it will show all the plans from ucrm. remove or hide plugin plans"*, then *"i will not create quotation from plugin until next build"*. Quotations are made in uCRM by hand meanwhile; a KYC Cash sale is refused and a Credit one gets no automatic quotation, as 5.18.102 does with an item not linked |
| D7 | **The catalogue goes first, as its own release, 5.18.103**: plans and hardware straight from uCRM, the plugin's own plan and hardware lists hidden on Uganda, and the one-click standard quotation. The AI's senses, the quote follow-up, the daily list and after-sales follow as the release after it, each part switched on step by step (D4 now applies to that release). The read-only report (§D item 1) may come at any time, being a tool and not a release |
| D8 | **5.18.104, the release after 5.18.103, is the sales follow-up** (the owner, 4 Nov, taking the recommendation): the read-only report, the quote chase on day 1, 3 and 7, the daily list per salesperson, voice notes heard through OpenAI with the AI answering in text, and until then an instant text acknowledgement of a voice note — each OFF until switched on (D4). Photos, documents, batching, location, after-sales and the e-mail copy wait |
| D9 | **The chase and acknowledgement wording is approved** (the owner, 4 Nov) as put to them: two wordings per step, no price, plan or customer name, STOP on each; on a salesperson's number the first words are "Hello, this is {first name}'s assistant at DishNet." (§H) |
| D10 | **WhatsApp stays on the Evolution numbers** until an official WhatsApp Business Platform number exists (the owner, 4 Nov: *"keep working Evolution until we get official meta whatsapp"*); the owner starts Meta's business verification. The official number is then added beside them for offers only |
| D11 | **Offers on Evolution meanwhile, the careful way** (the owner, 4 Nov, taking the recommendation): a daily batch only to customers who chatted in the last 30 days, from the number they chatted on, varied wording, STOP on each, an image allowed; the admin chooses how many — 100, 200 or all — and "all" is sent over days within each number's daily limit, never at once. Designed with the chase so the two share one daily limit and spacing per number; ships OFF |
| D12 | **Each WhatsApp number sends at most 60 automatic messages a day** — quote chases, offers, reminders and staff lists together; replies to customers are not counted (the owner, 4 Nov, of 30, 60 or 100). Chases come first; offers use what is left |
| D13 | **The morning list on WhatsApp carries quote numbers and counts only**; customer names appear on a staff page in the plugin, own leads only (the owner, 4 Nov) |
| D14 | **The Privacy Policy names OpenAI** as the provider that transcribes voice notes (the owner, 4 Nov) |
| D15 | **The instant reply to a voice note goes out every day, 07:00–21:00 Kampala**, Sundays included: it answers the customer's own message (the owner, 4 Nov) |
| D16 | **The agent who made a quote gets a visible copy (Cc) of the quotation email** (the owner, 4 Nov: "if agent creating quote and he has his email in system … he also get cc"; of Cc or a hidden Bcc, and of inside 5.18.104 or a release of its own). Part P7 of 5.18.104, its own switch, OFF until switched on, Uganda only. Only when the plugin sends the quotation email — never when uCRM's own mailer does — and only where the agent is known and has a valid email different from the customer's. The copy travels in the same email, so it can never make a second one |

Advice given on 1 Nov (§E; D10 and D11 since): e-mail broadcasts through uCRM's own Tools → Messaging, which sends e-mail (and
SMS only where a gateway is set), never WhatsApp; WhatsApp marketing to all customers only through the official
WhatsApp Business Platform, with approved templates and opt-out — never mass-sent from the Evolution numbers, which
WhatsApp may ban.

## C. What exists today (read-only discovery, 1 Nov 2026)

### C.1 South Sudan's n8n bot (`DishNet_AI_Bot_v3.9`, 140 nodes; read redacted, never copied into this repository)

- **Answers live, and well:** voice notes transcribed (OpenAI, default model), images and documents read (GPT-4o),
  location pins taken, several quick messages queued and answered as one (Redis and a short wait), human takeover
  pauses it, HOT-lead and hand-off alerts to staff, a staff watchdog every 15 minutes, an 18:00 digest, a QA judge.
- **It sends no follow-up to a customer.** Its "follow-up" is a staff alert about unanswered chats; a lead's
  `next_followup_at` is written and never read.
- **Not to be copied:** an unauthenticated webhook that takes the instance, server and key from the request body
  (forged sends); a key inline in a tool URL and an Evolution key copied in plaintext; customers matched by the last
  nine digits of a phone number; no opt-out; and wiring that leaves its digest, QA judge and alert dedupe probably
  inert.

### C.2 Uganda, live (5.18.102)

- **Location pins** are taken and given to the AI (country bounds only; no town or area).
- **The AI worker and brain** answer text, with prices read live from uCRM.
- **A follow-up engine** (migration 070; scan, run, send, close; FollowUpPolicy 08:00–20:00, 24 h and 72 h after the
  customer's last message, opt-out, AutomationPolicy per number). Its switches on the server are not established
  here. **It never chases a quote:** nothing links the quote records to it, and a quote sent from the notification
  number, or to a KYC customer who never wrote on WhatsApp, is never followed up.
- **Quotes** are sent by `cron_quote_wa.php` and the webhook, claimed once (`wa_sent_quotes`) and logged
  (`quotes_log.json`), with no field for a reply, an acceptance or a chase.
- **A voice note, a photo without a caption and a document get no answer at all**: stored as `[AUDIO]`, `[IMAGE]`,
  `[DOCUMENT]`, the AI not queued; staff learn of it from the watchdog after 10 minutes, 07:00–21:00.
- **No batching**: each quick message gets its own AI turn.

### C.3 Uganda, built and dark (the development branch only)

The AI communication layer of 4–5 Oct (`docs/07`; root `docs/55`–`58`): the media foundation (`wa_media`,
`InboundMedia`, `MediaWorker`), voice (`VoiceTranscription`), images (`ImageUnderstanding`, payment screenshots always to
a person), documents and PDF text (`DocumentExtraction`, `PdfReader`, a four-rung activation ladder), each behind
`ai_media_*` switches that default off, **with no provider adapter**: only `none` or `fake`. Never released.

### C.4 The base

The development tip and the live release diverged after 5.18.74. The release carries 5.18.99–5.18.102 (the quoting
organization, the uCRM resolver and links); the tip carries the dark media layer. **This release is cut on
`release/5.18.102` and ports what it takes from the development branch onto it**, file by file, as 5.18.88 did for
Batch 1.

## D. Scope of the release (to be designed in §F; each part OFF until switched on)

1. **A read-only report** (first, and usable before the release): per number — sales, accounts, support, each
   salesperson — over 30 and 90 days, counts only: conversations, quotes sent, quotes the customer answered, quotes
   chased, quotes that became a registration or a payment. No name, phone or figure in its output.
2. **The catalogue from uCRM (D1):** the KYC form offers uCRM's own service plans and products; no plugin row, no link
   to make; a standard quotation in one click.
3. **Voice, photos, documents (D2):** the dark layer ported onto the live release with an OpenAI adapter; payment
   screenshots, identity documents, statements and contracts always to a person; STOP understood in a voice note.
4. **Batching:** a customer's quick messages answered once.
5. **Location:** the pin mapped to a town or area for coverage and the installer, said as a colleague's to confirm.
6. **Quote follow-up (D3):** every quote tracked with its owner and chased day 1, 3 and 7 under the rules of D3; an
   e-mail copy where uCRM holds an address; a daily list per salesperson.
7. **After-sales:** welcome, day-3 check-in, day-30 rating with a review and referral ask, bill reminders, outage
   notices — WhatsApp and e-mail, quiet hours, STOP.

## E. Open — only the operator can answer

- The standard one-click quotation: which kit, plan and installation it holds, and who may press it.
- The wording of each chase and each after-sales message (the release proposes; the operator approves).
- Whether the follow-ups also go by e-mail, from which sender.
- The Privacy Policy's line on processors, now that voice notes and photos go to OpenAI.
- WhatsApp marketing: whether to open an official WhatsApp Business Platform account (Meta's verification is the
  operator's).

## F. Design

Three read-only agents wrote §F.1–§F.3 on 1 Nov, against the live release (`1a0ea5f`) and the development tree.
They are **drafts**: §F.3's findings (2 blockers, 12 major, 14 minor) are folded into a single design before anything
is built, and the operator's open questions (§E, and §F.3's §7) are answered first. Its estimate, about 45–60 working
days for the whole release, is a reviewer's guess, not a plan.

### F.1 Draft 1 — measurement, quote follow-up and after-sales (designer A)

### 72 §F, part: sales measurement and follow-up. Design for docs/72 §D items 1, 6 and 7 (1 Nov 2026)

Status: **design only. Nothing was built, run or changed.** The base is the live release `origin/release/5.18.102` (`1a0ea5f`).

Citation shorthand:
- `L:<path>:<line>` means `origin/release/5.18.102:dishnet-hybrid-sudan/<path>:<line>`.
- `W:<path>:<line>` means the development branch working tree, from the repository root.
- The follow-up engine's files are byte-identical on the release and the development tip: `FollowUpService`, `FollowUpPolicy`, `followup_scan/run/send/close` and `QuoteWaLedger` all have the same sha256. Docs/55's line numbers for them predate 5.18.89. Every citation below is to the release.

---

#### 1. Scope and binding rules

This part covers three things:
- **(1)** a read-only report that can be used before the release;
- **(6)** the quote follow-up of D3;
- **(7)** a minimal after-sales lifecycle.

Every rule in the brief binds. These are the ones that shape this design:
- **Off and Uganda-only.** Every new behaviour ships OFF and Uganda-only.
- **One gate.** The gate is one new `NotifyGate` name, read the way `NotifyGate::applies` already resolves Uganda (`L:lib/NotifyGate.php:54-70`).
- **South Sudan unchanged.** With the gate false, every changed file runs its 5.18.102 code verbatim.
- **Logs carry counts only.** Every new log line carries counts and codes, and never a phone, a name, an amount or a secret.

---

#### 2. What exists today (read from the live release)

##### 2.1 Every place a quote is recorded

| # | Record | What it holds | Where |
|---|---|---|---|
| 1 | `wa_sent_quotes` (`QuoteWaLedger`) | A claim per uCRM quote id, taken **before** the send: `quote_id`, `quote_ref`, `source`, `sent_at` (UTC). There is no phone and no client. | `L:lib/QuoteWaLedger.php:21-42` |
| 1a | Its sources | `flow_a_kyc`, `flow_b_ucrm`, `webhook_kyc`, `webhook`, `plugin_kyc`, `plugin_lead`, `plugin_manual`. The plugin sources claim only where `QUOTE_ONCE` applies, which is Uganda. | `L:cron_quote_wa.php:196,336`; `L:webhook.php:3120,3134`; `L:lib/QuotationService.php:116,207,414,1190-1201` |
| 2 | `quotes_log` (an SQLite table under the `quotes_log.json` name) | Cron flow A writes type `kyc_flow_a` with `sent_by='cron_quote_wa_flow_a'`. Flow B writes `ucrm_manual` with `sent_by='cron_quote_wa'`. Neither names a person. `QuotationService` writes `kyc`/`lead`/`cash`/`manual` with `sent_by` = the staff member's name. The table is **cut to the last 2,000 rows** on every `QuotationService` write. | `L:cron_quote_wa.php:248-259,369-385`; `L:lib/QuotationService.php:120-134,238-250,301-313,418-430,1239-1250` (cap at :1248) |
| 3 | `kyc_applications` | `quote_id`, `quote_ref`, `quote_created`, `wa_quote_pending/phone/deferred_at`, `wa_quote_sent/_at/_by`. The owner fields are `sales_person`, `retailer_id` and `retailer_name`. `submitted_at` is written with `date()`. | `L:lib/KycService.php:361-367,2616-2670`; `L:webhook.php:2918-2925,3061-3072`; `L:cron_quote_wa.php:199-204,239-244` |
| 4 | `quote_wa_state` | `sent_ids`, `pdf_pending` | `L:cron_quote_wa.php:78-79,689-695`; `L:webhook.php:2846-2851` |
| 5 | `quote_wa_log.json` (a file) | The last 200 lines. **Its lines carry phone numbers and names.** | `L:cron_quote_wa.php:212,347,913-927` |
| 6 | `webhook_log.json` | The last 300 entries | `L:webhook.php:139-157` |
| 7 | `wa_messages` | The customer's quote **text**, stored in the `(phone, 'support')` conversation (`'accounts'` for the accounts sender). It is stored **only after a successful send**, under event keys `ops_quote_created`, `ops_quote_wa`, `ops_quote_text`, `quote_kyc`, `quote_lead`, `quote_cash`, `quote_manual`. | `L:lib/NotificationService.php:2412-2444` (channel :2420); `L:lib/NotificationRetry.php:32` |
| 7a | The quote number in that text | Three fixed markers: `📋 *Ref:* <ref>`, `🆔 *Order:* <num>` and `📄 *Quote #<num>*` | `L:lib/QuotationService.php:489`; `L:webhook.php:3002`; `L:includes/api/api_whatsapp.php:665,674` |
| 8 | Message Log | Every send attempt, with its text | `L:lib/NotificationService.php:1626` (`logSend`) |
| 9 | `leads` | `status='quoted'`, `quote_ref`, `quoted_by`, `crm_quote_id` | `L:lib/QuotationService.php:222-231` |
| 10 | uCRM | The quote itself, and the client attribute `salesPerson` | `L:lib/UcrmClientTarget.php:201-227` |

What these records do not prove:
- **Which number.** Every quote leaves from the **notification number**: `NotificationService::SUPPORT`, the `support` department's Evolution channel. `wa_force_accounts` moves it to `account` (`L:lib/NotificationService.php:58-59,1892-1899`; `L:lib/QuotationService.php:1203-1213`; `L:webhook.php:3216-3224`).
- **That the send worked.** A claim does not prove delivery. `QuotationService::sendWA` returns true whatever the transport said (`L:lib/QuotationService.php:1203-1213`). The cron sets `$waSent = true` after any `sendVia` that did not throw (`L:cron_quote_wa.php:208-216`). Only the `wa_messages` row is written on success alone.

##### 2.2 Numbers and conversations

- **The channel registry.** `wa_channels` (migration 087) holds the three department rows `sales`, `support` and `account` under the ids their conversations already carry. Staff-owned channels such as `sales-002` carry `owner_staff_id`, a `retailers.json` id (`L:migrations/087_wa_channels.sql:19-50,92-97`).
- **One conversation per phone and channel.** `wa_conversations` is unique on `(phone, channel)` (`L:migrations/017_wa_conversation_store.sql:40-41`). Phones are stored as digits only (`L:lib/ConversationService.php:1232-1248`). `last_customer_at` and `last_agent_at` are UTC (`L:lib/ConversationService.php:832,835`).
- **What a person's message looks like.** A handset message is `role='agent'`, `agent_name='Team'`, `metadata.source='handset'`, and `markHumanHandling` is called (`L:evo_webhook.php:203-221`). An Inbox reply carries `event_key='wa_staff_reply'`, `'wa_send_document'` or `'wa_image_send'` (`L:includes/api/api_whatsapp.php:250,283,1008`).
- **What an AI reply looks like.** `role='assistant'` (`L:workers/AiReplyWorker.php:290`).

##### 2.3 The follow-up engine (migration 070)

- **The rows.** One open row per conversation is enforced by a partial unique index. Closed rows are immutable and rows are never deleted. Drafts, sends and events are audited (`L:migrations/070_followups.sql:11-65,69-131`).
- **The cadence.** Two attempts, at 24 h and 72 h after the customer's last message (`L:lib/FollowUpPolicy.php:57`; `L:lib/FollowUpService.php:23`). The window is 08:00–20:00 in `dn_tz`, and never on Sunday (`L:lib/FollowUpPolicy.php:182-206`).
- **The gates.** The gate chain is at `L:lib/FollowUpPolicy.php:288-371`. Auto-send works only for enquiry content (`:96-139`).
- **The jobs.** All four are no-ops unless `followup_enabled` (`L:cron/master.php:238-241`; `L:cron/followup_send.php:43`).
- **The sender is hardened.** `followup_send.php` re-checks, in this order:
  - colleague (:95-99);
  - salesperson-number hold (:100-104);
  - opt-out, proactive (:105-110);
  - human takeover (:111-115);
  - a reply since approval (:116-120);
  - the window (:121-122);
  - the plan fence (:130-135);
  - the channel's status (:139-146);
  - `AutomationPolicy` (:151-158).

  After those checks it claims its own echo (:174-180), stores the message (:202-208) and records the send (:210-211).
- **It never chases a quote.** It opens only on conversations where the customer spoke last (`L:cron/followup_scan.php:67-77`). A customer who never wrote has `last_customer_at` NULL and is never opened. A quote lands in the support conversation, which the sales follow-up never sees.

##### 2.4 Opt-out and automation

- **`ContactOptOut`.** Scopes `proactive`/`all`. Classes `reply`/`transactional`/`proactive`/`staff`, and the most restricted is the default (`L:lib/ContactOptOut.php:11-42`). An opt-out is per channel, or `*` by default (`:110-135,158`). Phones are digits only (`:253-256`).
- **`AutomationPolicy`.** It is inert while the registry is off. With the registry on, it refuses on a channel that is not active, an assistant that is off, an unverified salesperson number or an internal recipient (`L:lib/AutomationPolicy.php:7-35,214,230,254,287`).
- **`OwnedNumberHold`.** Automated follow-ups are held on salesperson numbers unless `wa_followups_on_owned_numbers` is set (`L:lib/OwnedNumberHold.php:5-27`). The operator chose on 7 Oct to set that switch when the pilot goes live (docs/65 §AC.1).

##### 2.5 Payments, as recorded locally

These are the payment records the plugin keeps itself:
- **The receipt WhatsApp.** `ops_payment_received` is sent to the client's first contact phone on `payment.add`. It is in `wa_messages` when the send succeeded (`L:lib/NotificationService.php:799`; `L:webhook.php:1162-1345`).
- **Cash payments.** `cb_ledger` rows with `crm_payment_id` and `crm_client_id`, cash only (`L:webhook.php:1186-1196,1275-1276`; `L:lib/CashbookService.php:1227-1228`).
- **Collections.** `payment_collections` rows with `crm_customer_id`, `crm_payment_id` and `created_at` (`L:includes/api/api_retailer.php:174-179`).
- **Cash at registration.** A KYC application with `sales_type` Cash and `amount_charged > 0` (`L:webhook.php:2906-2907`).

`notification_dedup` keys such as `PAY<id>` carry no client, so they cannot be attributed (`L:webhook.php:1324-1341`).

---

#### 3. The read-only report (§D item 1). It can be used before the release.

##### 3.1 What it answers

For each number, and separately for each owner, over the last 30 and 90 days:
- conversations;
- inbound customers;
- quotes sent;
- quotes answered;
- quotes chased;
- quotes that became a registration;
- quotes that became a payment.

The headline is the owner's question: **quotes sent that were neither answered nor chased within 7 days.**

##### 3.2 Exact definitions

`W` is the half-open interval `[now − D×24 h, now)` in UTC, with D = 30 or 90. Dates are printed in `Africa/Kampala`.

**Canonical phone `p`.** `PhoneNumber::international(raw, uganda)`, reduced to digits (`L:lib/PhoneNumber.php:35-59`). Unparseable input keeps its own digits and is counted under diagnostics.

**Excluded everywhere:**
- conversations filed `category='staff'`;
- `web:` sessions, which get their own row;
- DishNet's own numbers: `wa_channels.business_number` and the phones of active `retailers` rows.

| Count | Definition |
|---|---|
| **conversations** (per number N) | Conversation *sessions* on N. A session starts with an inbound message (`direction='in'`) in W from a phone with no inbound on N in the preceding 24 h. One thread per phone per channel (`L:migrations/017_wa_conversation_store.sql:40-41`) would make a thread count equal to the customer count, so sessions are what make this a separate figure. NotificationService's outbound-only threads count for nothing. `accounts` (NotificationService's spelling, `L:lib/NotificationService.php:2420`) is folded into `account`. |
| **inbound customers** | Distinct `p` with ≥1 inbound message in W on N. In the ALL row each `p` counts once across numbers. |
| **new** | Of those, a `p` whose first-ever inbound on N falls in W. |
| **quote sent** | A quote is SENT at time `t` if an outbound `wa_messages` row carries a customer quote event (§2.1 row 7, **without `quote_cash`**, which is a paid-sale receipt) and its body carries one of the three markers (§2.1 row 7a) with the quote's number. `t` is the earliest such row. The recipient is that row's conversation phone. The quote is identified by its uCRM id, looked up from its number through `wa_sent_quotes`, `quotes_log` or `kyc_applications`; a proforma with no uCRM quote keeps its plugin ref. A quote is in W when `t` is in W. Admin copies (`ops_quote_admin_cc`, `ops_quote_pdf_admin_cc`) and colleague phones are excluded. |
| **from a registration** | The quote's id is on a `kyc_applications` row: the registration came first. |
| **answered** | An inbound message from `p`, **on any channel**, in `(t, t + 7 d]` — the quotation's validity, "Valid for 7 days from date of issue" (`L:ucrm_pdf_templates/quotation_uganda/template.html.twig:205`). It must not be a STOP-only message (`ContactOptOut::detect`, `L:lib/ContactOptOut.php:233`). |
| **opted out** | A live `contact_optouts` row for `p` (any channel) created in `(t, t + 7 d]`. |
| **chased** | Before the first answer (or before `t + 7 d` if there is none), an outbound message to `p` on any channel in `(t, …)` that is either **by engine** (a `followup_sends` row) or **by a person** (an Inbox reply event from §2.2, or a non-canned handset message). Quote texts, `ops_*` notifications, AI replies (`role='assistant'`) and canned auto-replies are not chases. |
| **neither** (the headline) | Sent in W, not answered and not chased within 7 days. Quotes sent less than 7 days ago go to "still open" and are left out of the headline. |
| **registered** | Only for quotes not from a registration. A `kyc_applications` row in `(t, t + 30 d]` with the quote's client id (when known) or a canonical `mobile`/`wa_quote_phone` equal to `p`. |
| **paid** | The earliest of these in `(t, t + 30 d]`: an outbound `ops_payment_received` or `ops_invoice_auto_paid` to `p`; a `cb_ledger` row with direction `in`, `crm_payment_id > 0` and the quote's client; a `payment_collections` row for the client; or a Cash KYC application with `amount_charged > 0` for the client. Presence only: **no amount is read into the output.** The client is taken from `quotes_log`/`kyc`, or from the conversation's `crm_client_id` only when its `crm_link_method` is trusted (`L:lib/FollowUpPolicy.php:41`). Otherwise the phone signals alone apply. |

##### 3.3 Attribution

**To a number: the number the customer spoke to.**
- It is the channel of the conversation with phone `p` whose latest inbound message is at or before `t` and within the 30 days before `t`. Ties go to a `sales`-role channel.
- **The quote that went from the notification number.** If the customer never wrote in those 30 days, the quote goes to the row **"(quote's own thread — customer never wrote)"**, not to `support`. This way the support number's row is not swollen by quotes it only carried.
- Each block also prints "sent from: support n / account n".

**To an owner (the salesperson).** The first rule that yields one active staff id wins:
1. the KYC application with this quote id → `retailer_id` (the agent who submitted it, `L:lib/KycService.php:366`);
2. the `quotes_log` row for the quote → `sent_by`, matched exactly and case-insensitively to one `retailers` name, as the cron already does (`L:cron_quote_wa.php:176-183`). Two matches give no owner, and `cron_quote_wa*` names nobody;
3. the conversation the customer spoke to is staff-owned → `wa_channels.owner_staff_id`;
4. that conversation's lead (`wa_conversations.lead_id`) → `leads.assigned_to`;
5. otherwise **unattributed**.

The uCRM attribute `salesPerson` is **not** read: the report touches no network.

##### 3.4 Output: counts only

The report prints plain text by default; `--json` gives the same counts.

```
== sales follow-up report (READ-ONLY) — <UTC stamp> ==
  copy       consistent copy (VACUUM INTO), integrity ok; opened read-only, immutable, query_only
  tenant     uganda            (anything else: "not this install — nothing read", exit 0)
  windows    30 d: <date> → <date> · 90 d: <date> → <date> (Africa/Kampala)
  coverage   wa_messages from <date> · wa_sent_quotes from <date> · quotes_log from <date> (n rows; cut at 2000)
             · kyc_applications from <date> — a source that starts inside a window marks it INCOMPLETE

  BY NUMBER — the number the customer spoke to (last 30 days)
  number      role     owner      line  conv  cust  new  quotes  kyc  answ  chased(eng/per)  neither  reg  paid
  sales       sales    dept       ..nn     n     n    n       n    n     n      n (n/n)          n      n     n
  support     support  dept       ..nn ...
  account     account  dept       ..nn ...
  sales-002   sales    staff #14  ..nn ...
  (quote's own thread — customer never wrote) ...
  web chat ...
  ALL ...      sent from: support n · account n
  [the same block for 90 days]

  BY OWNER — who made the quote (30 d | 90 d)
  owner        quotes  kyc  answ  chased  neither  reg  paid
  staff #14 ...
  unattributed ...

  HEADLINE   quotes neither answered nor chased within 7 days: n of n (30 d) · n of n (90 d)
  STILL OPEN sent less than 7 days ago: n · less than 30 days ago (reg/paid not final): n
  DIAGNOSTICS claimed, no message n · logged, no message n · owner ambiguous n · phone unparseable n
              · registrations with no automatic quote (quote_refused / quote_created false) n
              · quote requests in chat (leads.quote_requested) n · after the release: tracked n, chased n
  READ-ONLY — nothing was changed.
```

What the output never contains:
- **No names.** Owners are printed as staff ids; ids are enough for the operator to map, and a pasted log carries no personal data.
- **No numbers.** Business numbers appear as their last two digits only, as `tools/channels.php` does (`L:tools/channels.php:13-15`).
- **No amounts and no customer data.** A missing source prints `UNKNOWN`, never 0 (the house rule in `L:tools/report.php:13-18`).

##### 3.5 How it reads, and why it cannot write

- **No plugin store code.** It never calls `SqliteStore::create`, because that runs the migrations on every boot (`L:lib/MigrationRunner.php:13-19`).
- **One read-only connection to the copy.** It opens the copy with `PDO::SQLITE_ATTR_OPEN_FLAGS = SQLITE_OPEN_READONLY`, `immutable=1`, `PRAGMA query_only=ON` and `temp_store=MEMORY`. Every query is a single `SELECT`, and the run fails unless `total_changes()` is 0. This is the P0 tool's pattern (`W:scripts/bank-recon-p0.php:12-23,185-198`).
- **Two pure includes, read-only config, no network.** It loads only `lib/PhoneNumber.php` and `lib/ContactOptOut.php` from the installed plugin. Both are pure: no constructor runs.
- **The tenant is read the read-only way.** `PluginConfig::read` plus `TenantProfile::current`, as `tools/channels.php` does (`L:lib/PluginConfig.php:67-75`; `L:tools/channels.php:33-34`).
- **It refuses the live book.** It refuses when `--db` resolves to the live data folder's `plugin.sqlite3`, or when a `-wal` or `-shm` file sits beside it.
- **PHP 7.4.** `SQLITE_ATTR_OPEN_FLAGS` and `VACUUM INTO` (SQLite ≥ 3.27) are already relied on in production (`W:scripts/bank-recon-p0.php:185-188`; `W:scripts/deploy-5.18.102.sh:2146`).

##### 3.6 How the operator runs it on the server

Files:
- `dishnet-hybrid-sudan/tools/sales_followup_report.php` (the tool; it also ships in the release);
- `scripts/sales-followup-report.sh` (the wrapper).

The wrapper follows the deploy scripts' consistent-copy readings:
1. **Find the install.** As root, it locates the container (`UCRM_CONTAINER`, default `ucrm`), the installed plugin, the data folder (`ucrm.json pluginDataDir`, else `.dishnet-hybrid-sudan-data`) and the database owner, refusing a root-owned book (`W:scripts/bank-recon-p0.sh:35-75`; `W:scripts/deploy-5.18.102.sh:573,6139-6172`).
2. **Take the tool from Git.** By `git cat-file` at its reviewed commit, with its sha256 checked (`W:scripts/deploy-5.18.102.sh:919-922,6109-6110`).
3. **Make the copy.** As the owner:
   - check free space in the container's `/tmp`, needing size × 3 + 64 MB;
   - `mkdir -m 700`;
   - **`VACUUM INTO`** the copy and run `PRAGMA integrity_check`;
   - set `journal_mode=DELETE`;
   - cut the settings row to the keys the tenant needs, with `secure_delete=ON`, so the copy holds no credential.

   This is the same procedure as `W:scripts/deploy-5.18.102.sh:2139-2170,2493-2499`.
4. **Run the tool on the copy.** The tool is piped on standard input, so nothing is copied into the plugin, with `-d log_errors=0 -d opcache.enable_cli=0` (`W:scripts/deploy-5.18.102.sh:2524`).
5. **Clean up and say so.** Print the output, remove the folder (with a trap on exit), and end with `READ-ONLY — nothing was changed`.

A copy is used rather than P0's double reading because `wa_messages` is written on every inbound and outbound message. Two readings of the live book would seldom agree.

The operator runs one command block. There is no rollback, because nothing changes:

```
cd /opt/dishnet && git pull origin claude/study-this-jhe2eg && mkdir -p /root/dnb-report && bash scripts/sales-followup-report.sh 2>&1 | ( umask 077; exec tee /root/dnb-report/report-$(date -u +%Y%m%dT%H%M%SZ).log )
```

The operator then sends back **the log file, not the terminal**, as the binding lesson in docs/127 phase 4 requires.

##### 3.7 Tests for the report (`tests/test_sales_followup_report.php`)

**A fixture book covers every definition:**
- a quote confirmed by each of the three markers;
- a claim with no message;
- a reply on another channel;
- a STOP-only reply;
- chases by engine, by person, by an AI reply (not a chase) and by a notification (not a chase);
- registration and payment by each signal;
- each owner rule, and a name matching two staff;
- a colleague's phone;
- a `web:` session;
- `accounts` folding into `account`.

**Further checks:**
- The output contains no run of 9 or more digits, no fixture name and no currency figure.
- A write attempt fails under `query_only`.
- `_migrations` and the copy's sha256 are unchanged after the run.
- A tenant other than Uganda reads nothing.

**Weakened copies, each of which must fail:**
- R1 reply matched only on the quote's conversation;
- R2 an AI reply counted as a chase;
- R3 claims counted as sends;
- R4 last-nine-digit phone matching;
- R5 `SqliteStore::create` used;
- R6 a phone printed;
- R7 `quote_cash` counted;
- R8 the "own thread" row merged into `support`;
- R9 local `date()` stamps used for windows.

---

#### 4. Quote follow-up (D3)

##### 4.1 Decision: extend the existing engine with a second trigger. No second engine.

**Decided:** quote chases are `followups` rows of a new `kind = 'quote'`. They are drafted from a template by a new job, auto-approved, and **sent by the existing `followup_send.php`**.

The reasons:
1. **The sender's checks are reused, not copied.** The hardened sender's eleven re-checks (§2.3) become every chase's checks. A separate engine would copy about 150 lines of them and drift.
2. **No double messaging, by construction.** The unique index (one open follow-up per conversation, `L:migrations/070_followups.sql:44-45`) means an enquiry follow-up and a quote chase cannot both be open on one thread.
3. **One trail.** `followup_events` and `followup_sends` stay the single trail of every proactive message.
4. **It is what docs/55 asked for.** Docs/55 §9 set the invariant "no second … follow-up engine" and proposed new triggers ("quotation sent") on the same rows.

What is new beside the engine is a **quote tracker**: quote-level facts the engine has no place for (owner, expiry, conversion).

##### 4.2 Data model: migration `089_quote_followups.sql`

The release's last migration is 088. Numbers 081–083 and 085 are taken on the development branch only (`085_wa_media.sql` belongs to the media port); 089 avoids them. The migration is additive only (`L:lib/MigrationRunner.php:21-29`). A duplicate `ADD COLUMN` is safely ignored by the runner.

```sql
CREATE TABLE IF NOT EXISTS quote_tracker (          -- one row per quotation that reached a customer on WhatsApp
  quote_key      TEXT PRIMARY KEY,                  -- 'q:<uCRM quote id>' | 'p:<plugin ref>' (a lead proforma with no uCRM quote)
  quote_id       INTEGER, quote_ref TEXT NOT NULL,
  crm_client_id  INTEGER,
  phone          TEXT NOT NULL,                     -- digits of the international number the quote reached
  source         TEXT NOT NULL,                     -- the ledger source or quotes_log type
  sent_at        TEXT NOT NULL,                     -- UTC: the first confirming wa_messages row (§3.2)
  sent_channel   TEXT NOT NULL,                     -- 'support' | 'accounts': the number it left from
  valid_until    TEXT NOT NULL,                     -- Kampala date: issue + 7 days (template :205)
  owner_staff_id INTEGER, owner_source TEXT NOT NULL DEFAULT 'none',  -- kyc|quotes_log|channel|lead|ucrm_attr|none
  spoke_channel  TEXT,                              -- §4.4; NULL = the quote's own thread
  status         TEXT NOT NULL DEFAULT 'open',      -- open|answered|paid|registered|accepted|declined|expired
                                                    -- |opted_out|taken|superseded|colleague
  status_at TEXT, status_reason TEXT,
  chase_hold     TEXT,                              -- why no automatic chase (§4.4): owned_number|ambiguous|not_before|channel_refused
  followup_id    INTEGER,
  created_at     TEXT NOT NULL DEFAULT (datetime('now')));
-- indexes: (status, sent_at) WHERE status='open'; (phone); (owner_staff_id, status)
-- triggers: never deleted; status leaves 'open' once (a closed quote is history, as fu_closed_is_history)

ALTER TABLE followups ADD COLUMN kind TEXT NOT NULL DEFAULT 'enquiry';   -- every existing row reads 'enquiry'
ALTER TABLE followups ADD COLUMN quote_key TEXT;
CREATE UNIQUE INDEX IF NOT EXISTS idx_fu_quote ON followups(quote_key) WHERE quote_key IS NOT NULL;  -- one chase per quote, ever

CREATE TABLE IF NOT EXISTS followup_send_claims (    -- THE LEDGER: claimed before the send, never deleted
  followup_id INTEGER NOT NULL, attempt INTEGER NOT NULL, draft_id INTEGER NOT NULL,
  claimed_at TEXT NOT NULL DEFAULT (datetime('now')), outcome TEXT, settled_at TEXT,
  PRIMARY KEY (followup_id, attempt));

CREATE TABLE IF NOT EXISTS quote_list_sends (        -- the daily list's ledger
  list_date TEXT NOT NULL, owner_key TEXT NOT NULL, medium TEXT NOT NULL,  -- 'staff:<id>'|'central'; 'whatsapp'|'email'
  claimed_at TEXT NOT NULL DEFAULT (datetime('now')), outcome TEXT, open_count INTEGER NOT NULL DEFAULT 0,
  PRIMARY KEY (list_date, owner_key, medium));

CREATE TABLE IF NOT EXISTS email_optouts (address TEXT PRIMARY KEY, created_at TEXT NOT NULL DEFAULT (datetime('now')),
  source TEXT NOT NULL);                             -- read only by the e-mail copy (§4.8)
```

Like 087, the migration runs on both installs and reads nothing on South Sudan. 087's precedent applies: additive and dark (`L:migrations/087_wa_channels.sql:8-9`).

**Why a new ledger and not `notification_dedup`.** Its claim fails **open**: on an error `dedupMark` returns true and the message is sent (`L:lib/NotificationService.php:3237-3248`; `L:lib/CustomerEmailDispatcher.php:79-92`). It is also purged after 45 days (`L:lib/NotificationService.php:3254`). The new ledgers fail **closed**: a claim that cannot be written means no send.

##### 4.3 Tracking: derived from evidence, not hooked into the senders

**Decided:** the tracker is filled by the new job from the same evidence and the same code as the report. This is one pure class, `lib/QuoteSendEvidence.php`, used by both, so the report and the tracker agree by construction. The four live send paths are not edited.

The reasons:
- **The senders' success flags are unreliable.** `QuotationService::sendWA` returns true whatever happened, and the cron sets its flag the same way (§2.1).
- **Only a delivered quote is tracked.** `wa_messages` is written only on success, so an undelivered quote is never chased.
- **A late delivery is still picked up.** A quote that `NotificationRetry` delivers later is caught when its message appears.

The cost is a lag of at most one job run (10 minutes).

**Each new tracker row** (gated by `quote_tracking_enabled`) takes:
- its quote id from its number (§3.2);
- `valid_until` = uCRM's issue date + 7 days, from one `GET quotes/{id}`, else the send date + 7 days;
- its owner by §3.3's rules 1–4, then a fifth: uCRM's `salesPerson` attribute (`L:lib/UcrmClientTarget.php:201-227`), matched to exactly one active staff name;
- its `spoke_channel` by §4.4.

##### 4.4 The chase

**Schedule.** Steps 1, 2 and 3 are due at `sent_at` + 24 h, 72 h and 168 h: days 1, 3 and 7 from the quote's send.
- A step that falls outside 08:00–20:00 Kampala, or on a Sunday, moves to the next opening (`FollowUpPolicy::withinSendingWindow`, `L:lib/FollowUpPolicy.php:182-225`).
- After a late send, the next step is never sooner than the scheduled gap (48 h, then 96 h), measured from the actual send. This is the rule of `FollowUpPolicy::nextDueAfterSend` (`:258-275`), restated for the quote schedule in a new pure `lib/QuoteChasePolicy.php`. `FollowUpPolicy` itself is untouched.
- No step is sent after the end of `valid_until`. Day 7 is the quotation's last valid day.

**Which number sends it.**
- **Rule.** The number the customer spoke to: the conversation with **exactly** the quote's phone (international digits, never the last nine digits) whose latest inbound message is within the 30 days before the quote.
- **Customer never wrote.** The chase goes from **the quote's own thread**: the notification number, in the conversation that holds the quotation. A first message from a number they have never had a thread with would be cold, and it would add to that number's ban risk.
- **Option.** `quote_chase_cold_channel = quote_thread | sales` (default `quote_thread`) leaves the choice open (§7).
- **A salesperson's own number.** It sends only when `wa_followups_on_owned_numbers` is on (docs/65 §AC.1). Otherwise no row is opened: the tracker records `chase_hold='owned_number'` and the quote goes on that salesperson's daily list as *"yours to chase"*. This is the hold's own reasoning (`L:lib/OwnedNumberHold.php:5-20`).

**Opening a chase.** The job opens a chase only for an open tracker row:
- sent after `quote_chase_not_before` (the backlog guard, as `followup_not_before` does at `L:cron/followup_scan.php:82-96`);
- not a colleague, not ambiguous (`FollowUpPolicy::contentLevel` = none), with no live opt-out for the phone on any channel.

It runs in one `BEGIN IMMEDIATE` transaction:
1. close an open enquiry row on that conversation as `superseded`;
2. close an older quote's chase as `superseded`;
3. insert `kind='quote'`, `max_attempts=3`, `topic='quote'`, `product=<ref>`, `sales_stage='quoted'`, `context_summary='quotation <ref>, valid until <date>'`, and `last_customer_at` = max(the thread's, `sent_at`). That column is NOT NULL (`L:migrations/070_followups.sql:30`).

**Stops.** The job checks these every run, locally. The sender re-checks them just before sending (§4.6).

| Stop | Detected by | Tracker status |
|---|---|---|
| Any customer reply, on any channel, after `sent_at` | inbound `wa_messages` for the phone | `answered` |
| STOP | a live `contact_optouts` row for the phone, **any channel**, scope proactive or all | `opted_out` |
| A person took over | the thread's `state` is `human_active`/`needs_human`, or a person's message (§2.2) to the phone after `sent_at` | `taken` |
| A payment | §3.2's paid signals, plus at most one `GET` of the client's payments since `sent_at` before a due step | `paid` |
| A registration (non-KYC quotes) | a new KYC application for the client or phone | `registered` |
| uCRM status: approved / rejected / void | one `GET quotes/{id}` before a due step | `accepted` / `declined` |
| A newer quote to the same phone | the tracker | `superseded` (the newer quote gets the chase) |
| Expiry | `valid_until` has passed | `expired` |

When the tracker leaves `open`, the chase row is closed with the same reason, through `FollowUpService::close`.

**Daily cap.** `quote_chase_daily_cap` per channel, default 20, counted **at send time** over all `followup_sends` on that channel since local midnight in Kampala. Enquiry and quote sends together cannot become a blast.

##### 4.5 The message: templated, not AI-written

**Decided:** a fixed template per step, in a new pure `lib/QuoteChaseText.php`.

The reasons:
1. **D3 needs no judgement.** Its rules are facts: the quote number, the validity, the step.
2. **No price can be wrong.** The text carries **no amount, no plan name and no name**. The PDF holds the prices, the plan fence never has to fire, and no customer name is written into the Message Log (every WhatsApp body goes there, `L:lib/NotificationService.php:1626`).
3. **An AI chase would need a person.** `mayAutoSend` lets only enquiry content send itself (`L:lib/FollowUpPolicy.php:96-139`), so an AI chase would wait for approval, or be a model call per chase that could misstate the document.
4. **Fixed bytes can be tested,** golden byte for byte.
5. **STOP is on every message.** Once the customer replies, the AI takes over as usual.

Proposed wording. The operator approves it (docs/72 §E). There are no relative words such as "yesterday": a draft held over Sunday must stay true.
- **Day 1:** "Hello, this is DishNet. Do you have any questions about quotation {ref}? Reply here and we will help, or book your installation. Reply STOP if you do not want these messages."
- **Day 3:** "Hello, a quick follow-up on quotation {ref} from DishNet. We can answer any question about the kit, the plan or the installation — just reply here. Reply STOP to stop these messages."
- **Day 7:** "Hello, quotation {ref} is valid until {d MMMM}. If you would like to go ahead, or need another option, reply here and we will take care of it. Reply STOP to stop these messages."
- **On a salesperson's number,** the first sentence becomes "This is {first name}'s assistant at DishNet": the persona of docs/65 D3.

**How a step is drafted.** The job writes the step through `FollowUpService::draft`, with verdict `SEND`, `model='template:quote_chase_v1'` and `trigger_note='step N'`. It then calls `approve(…, 'auto:quote_chase')` (`L:lib/FollowUpService.php:225-313`).

##### 4.6 The ledger, and what changes in the live follow-up files

Every change is behind `$quoteOn` = the `quote_followup` gate AND `quote_chase_enabled`. With it false, each file runs its 5.18.102 code.

- **`FollowUpService`:**
  - adds `openQuote()`;
  - in `recordSend`, a `kind='quote'` row arms its next step from `QuoteChasePolicy`, not from the two-step schedule (today `:381-386`);
  - adds `sentOnLocalDay()`;
  - adds `claim()` and `settle()` on `followup_send_claims`.
- **`followup_send.php`:**
  - runs when `followup_enabled` **or** `$quoteOn`. With `followup_enabled` off, it sends **quote drafts only**, so an enquiry draft approved earlier is never sent behind a switch the operator turned off;
  - for a quote draft, it first runs **`QuoteChase::preSendCheck`** (local reads only): the tracker is still open and valid, no inbound from the phone on any channel since `sent_at`, no person's message, and the draft was made on today's Kampala date. A failed check closes the draft as `expired`, and the job redrafts after checking again;
  - it then checks the cap at send time;
  - it then **claims `(followup_id, attempt)`** with `INSERT OR IGNORE`: not first means skip, and a database error means no send. Only then does it run `sendText`.
- **`followup_run.php`:** `kind='quote'` rows are left out of its query, so the AI is never asked about a quote and quote rows take none of its places.
- **`followup_scan.php`:** a conversation is never opened when its phone has a quote chase that is open, or that closed after the customer's last message. Once a chase has ended, the AI follow-up cannot add messages to the same silence.
- **`followup_close.php`:** unchanged. Its CASE C/H/D close quote rows correctly; the job does the cross-channel stops.
- **`cron/master.php`:** two new jobs under the gate `quote_followup` (dispatch: `L:cron/master.php:324`):
  - `quote_chase`, every 600 s;
  - `quote_daily_list`, `run_hour` 8, `run_until` 11 (`JobWindow`, `L:lib/JobWindow.php:11-17`).
- **`NotifyGate`:** two new names, `QUOTE_FOLLOWUP` and `AFTERSALES`, in `ALL`. `EVERYWHERE` stays empty.

##### 4.7 The daily list per salesperson

**When and how.**
- At 08:00 Kampala, Monday to Saturday; the job returns on Sunday, since `JobWindow` has no weekday (`L:lib/JobWindow.php:11-17`).
- On **WhatsApp** to each owner's phone (`StaffDirectory::phoneOf`, `L:lib/StaffDirectory.php:100`), sent from the DishNet sales number and never from a person's line, through `AlertService::notifyTo` (`L:lib/AlertService.php:59-70`). It goes as `CLASS_STAFF`.
- An e-mail copy goes to `StaffDirectory::email` when `quote_daily_list_email` is on.
- The list is claimed once per day, owner and medium in `quote_list_sends`.
- A **central list** of unattributed quotes, plus per-owner totals, goes to `alert_whatsapp` (`L:lib/AlertService.php:39-42`). An owner who is not active is folded into it.

**Content: quote numbers and counts only.** No customer name, phone or amount; the salesperson opens the quote by its number. Sections:
- due today (automatic);
- **yours to chase** (held), with the reason;
- **replied — not yet paid, call them**;
- expiring today;
- may have been sent — check the chat (`uncertain`);
- the week's totals.

At most 15 items per section, then "and n more".

The leads reminder sends names and phones (`L:cron_leads.php:358-362`). This list does not follow that precedent, because of this release's logging rule. `quote_daily_list_names` (default OFF) is left for the operator.

##### 4.8 The e-mail copy

- **Switches.** `quote_chase_email_enabled` AND the master `customer_emails_enabled` (`L:lib/CustomerEmailDispatcher.php:9-15`).
- **Where it lives.** A new `sendQuoteChase()` outside the catalogue, for the reason `sendReminderDue` gives: the preview and South Sudan's golden stay untouched (`:296-311`). It uses the billing-first recipient lookup (`:447`) and `customer_email_log` with the mark `quote_chase:<key>:<step>` (`:485-568`).
- **It is a copy.** It is sent by the job only **after** a WhatsApp step has been recorded in `followup_sends`. A held chase or an opt-out therefore produces no e-mail. It goes inside the same window.
- **Steps.** The e-mail step list `quote_chase_email_steps` defaults to `1` only, with reason: six messages about one quote in a week reads as spam. `1,3,7` is available.
- **Content.** A fresh `serve_quote_pdf` link when the stored PDF exists (`QuotePdfToken::mint`, `L:cron_quote_wa.php:778,791-794`).
- **Opt-out, required before switch-on.** No e-mail goes to a phone with a live WhatsApp opt-out, or to an address in `email_optouts`. Every message carries `List-Unsubscribe` and an HMAC-signed one-click link that writes only that row. Today the plugin has **no e-mail opt-out at all** (docs/55 §6).

##### 4.9 Switches, all OFF and Uganda-only, in switch-on order (D4)

| Step | Switch | Effect | Its check |
|---|---|---|---|
| Q1 | `quote_tracking_enabled` | tracker rows only; no message | `tools/quote_followup_doctor.php` (read-only, as `L:tools/followup_doctor.php:5-14`): tracker counts equal the report's for the same days |
| Q2 | `quote_daily_list_enabled` (+ optional `quote_daily_list_staff` pilot ids) | staff only | the first list arrives; ledger 1 row per owner |
| Q3 | `quote_chase_enabled` + `quote_chase_not_before` = switch-on time; `quote_chase_daily_cap` | chases on department numbers; salesperson numbers follow `wa_followups_on_owned_numbers` | doctor: due / held / sent per channel; no step outside the window |
| Q4 | `quote_chase_email_enabled` (+ `customer_emails_enabled`) | the e-mail copy | the unsubscribe round trip on a test address |

Two more switches each fix one of docs/55's gaps for **enquiry** rows. Both are default OFF and recommended:
- `followup_send_guards` (the claim and the local-day cap for all drafts);
- `followup_reopen_guard` (gap 1).

Turning `quote_chase_enabled` off stops at once: the job drafts nothing and the sender skips quote drafts.

##### 4.10 The engine's known gaps (docs/55:174-176), against this design

| Gap | Live evidence | This design |
|---|---|---|
| 1 Re-opened in the same silence | the scan's join excludes only open rows (`L:cron/followup_scan.php:67-77`) | **Avoided for quotes:** one chase per quote (`idx_fu_quote`), and the scan excludes phones whose chase closed after the last customer message. **Fixed for enquiries** under `followup_reopen_guard`. |
| 2 Cap at draft time, on a UTC day | `L:cron/followup_run.php:80,122-131`; `L:lib/FollowUpService.php:416-423` | **Fixed for quotes** (at send, Kampala day); enquiries under `followup_send_guards` |
| 3 No per-draft claim | `L:cron/followup_send.php:73`; `'sent'` is set only after the send (`L:lib/FollowUpService.php:370-371`) | **Fixed for quotes** (`followup_send_claims`, fail-closed); enquiries under `followup_send_guards` |
| 4 `uncertain` invisible on the screen | `L:lib/FollowUpService.php:408-413` | Quote ones appear on the owner's list; the screen is unchanged |
| 5 `topic` / `context_summary` never filled | `L:lib/FollowUpService.php:171-186` has no caller writing them | **Filled for quote rows** at open |
| 6 The quotation is invisible to a sales follow-up | `L:lib/ConversationService.php:228-255`; `L:lib/NotificationService.php:2420` | **Fixed for quotes:** the tracker joins by phone; the chase uses the spoken-to thread; replies count from any channel |

##### 4.11 Tests (`tests/test_quote_followup.php` and its weakened copies)

**Sections:**
- **A, evidence and tracking.** The three markers; a claim with no message is not tracked; `NotificationRetry`'s late success is tracked; owner rules 1–5.
- **B, policy (pure).** The 24/72/168 h schedule; window shifts; Sunday; the floor after a late send; nothing after `valid_until`.
- **C, number.** Exact phone match; 30-day spoken-to rule; quote's own thread; salesperson number with the hold on and off; ambiguous; colleague.
- **D, stops.** Each row of §4.4, including a reply on another channel arriving between drafting and sending.
- **E, sender.**
  - Two concurrent senders send once.
  - A claim error sends nothing.
  - Cap at send on the local day.
  - Switch off: quote drafts are skipped.
  - `followup_enabled` off: an old enquiry draft is not sent.
  - `AutomationPolicy` refusal; echo claimed; message stored.
- **F, interplay.** A quote supersedes an open enquiry; the scan does not reopen; `followup_run` never asks the AI about a quote.
- **G, daily list.** One per owner per day; none on Sunday; central gets unattributed; no phone, name or amount in the body.
- **H, e-mail.** Both switches; dedupe per step; phone and e-mail opt-out; never on South Sudan.
- **I, South Sudan.** The scan, run, send and close journeys give rows and outputs identical to 5.18.102.
- **J, logging.** Every new log line holds no run of 9+ digits, no fixture name, no amount.

**Weakened copies, each of which must fail:**
- QM1 claim after the send;
- QM2 claim failure read as success;
- QM3 reply checked only on the chase thread;
- QM4 window skipped for quote drafts;
- QM5 Sunday allowed;
- QM6 opt-out class transactional;
- QM7 hold ignored;
- QM8 schedule anchored to the last customer message;
- QM9 expiry ignored;
- QM10 gate dropped;
- QM11 cap on the UTC day;
- QM12 scan exclusion removed;
- QM13 last-nine-digit match;
- QM14 AI asked about quote rows;
- QM15 `needs_human` not stopping;
- QM16 newer quote not superseding;
- QM17 payment ignored;
- QM18 a stale draft sent the next day;
- QM19 a template with an amount;
- QM20 an e-mail sent for a held chase.

---

#### 5. After-sales lifecycle (§D item 7): a minimal first version

##### 5.1 Events the plugin already receives

From uCRM's webhook list (`L:webhook.php:771-780` and the cases below it):
- `job.add` (`:2566`): the installation-scheduled WhatsApp (`:2694`);
- `job.edit`/`job.delete` (`:3805-3840`): `JobNotifier` compares job states and records **`closed` (status 2)** in `job_notify_events` (`L:lib/JobNotifier.php:11-17`; `L:migrations/075_job_notifications.sql`);
- `service.add` (`:1619`), `service.activate` (`:2017`), `service.end` (`:2348`) and `service.outage` (`:3698`, **logged only**);
- `invoice.*`, `payment.add` (`:1162`), `ticket.add` (`:2706`).

##### 5.2 What exists already

- **Installation booked.** WhatsApp behind `customer_wa_install_scheduled` (`L:lib/InstallScheduledWhatsApp.php:4-28`).
- **First activation.**
  - `service.add` with an active service sends the "Service Activated" WhatsApp (`L:webhook.php:1651-1665`) and the welcome e-mail (`:1674`).
  - `service.activate` on a first activation sends the welcome e-mail (`:2161-2171`). Its WhatsApp, however, is **"Service Restored … You're back online!"** (`:2121-2132`), sent to a new customer.
- **Invoices.** The invoice WhatsApp and PDF come from `invoice.add` and the 15-minute scanner (`L:cron_invoice_notify.php:5-19`).
- **Bill reminders.** `InvoiceReminders`, 7/3/1 days before the due date and 1/3/5/7 days after, in the daytime, with prepaid rules and an e-mail arm (`L:lib/InvoiceReminders.php:3-25`; `L:cron/customer_reminders.php:33-61`).
- **Win-back.** `WinBack`, proactive, once per ended service (`L:lib/WinBack.php:3-12`).
- **Outage.** An admin's pasted bulk form (`L:includes/notify_actions.php:50-84`).
- **Not for Uganda.** `LifecycleService`'s message templates carry South Sudan's office and numbers (`L:lib/LifecycleService.php:69-146`) and are not reusable.

##### 5.3 Version 1

Switches: master `aftersales_enabled` plus one switch per step, all OFF and Uganda-only under the gate `aftersales`.

| Step | Trigger | Message and rules |
|---|---|---|
| Welcome | An installation job **closed**: a `job_notify_events` `closed` row read by the job, with one `GET` of the job for its client. A kit-only customer has no installation job, so for them the trigger is the **first activation**, recorded by a gated one-line hook in the two activation branches. | Welcome, how to get help, the app link, a STOP line. Once per client and service. Behind `aftersales_first_activation_text`, the wrong "Service Restored" text on a first activation is replaced by the Uganda activation text. |
| Day 3 check-in | Trigger + 72 h, moved into the window | "How is your connection? Reply here if anything isn't right." Skipped if the customer wrote within 72 h, the thread is `needs_human`/`human_active`, or an opt-out exists. |
| Day 30 rating | Trigger + 30 days | Asks for 1–5 and, **in the same message**, gives the review link (`aftersales_review_url`; the step stays off without one) and the referral ask. That is one message, so the AI's own reply to a "5" never doubles it. A digit reply within 72 h is recorded; 1–3 alerts the support leader (`AlertService::notify`). |
| Bill reminders | unchanged | `InvoiceReminders` stays as it is (§8 records its Sunday runs). |
| Outage notices | **No automatic customer message in v1.** `service.outage` events are recorded and counted on the central daily list. The manual form stays. | Automating outage notices needs its own decision on scope and quiet-hours exceptions. |

How v1 sends:
- **Data.** Migration `090_aftersales.sql`: `aftersales_journeys` (client, service, trigger, times, rating) and `aftersales_sends`, a fail-closed ledger with primary key `(client_id, service_id, step)`.
- **Job.** `cron/aftersales.php`, every 600 s.
- **Transport.** Sent from the support department number as `CLASS_PROACTIVE` through `sendVia`, which applies the opt-out (`L:lib/NotificationService.php:1943-1952,2299`).
- **Gates.** 08:00–20:00, never Sunday; `AutomationPolicy::refusal('support', phone)`; `aftersales_daily_cap`.
- **Not in v1:** WhatsApp only; e-mail arms come later on the `sendReminderDue` pattern.

**Tests.** The trigger read from `job_notify_events`; once per step; window and Sunday; each skip rule; the rating parse and alert; South Sudan untouched. Weakened copies:
- AM1 the ledger fails open;
- AM2 Sunday allowed;
- AM3 transactional class;
- AM4 "Service Restored" still sent behind the switch;
- AM5 the review ask sent without a URL.

---

#### 6. Decisions

| # | Decision | Reason |
|---|---|---|
| S1 | The report reads a `VACUUM INTO` copy, opened immutable with `query_only`, the tool piped from Git with its sha256 checked | the deploy scripts' reading method; a busy `wa_messages` makes double reads unreliable |
| S2 | "Sent" means a stored `wa_messages` quote text with a marker | the only record written on success alone |
| S3 | Quotes are credited to the number the customer spoke to; never-wrote quotes get their own row | the notification number would otherwise collect every quote |
| S4 | Owner: KYC agent > quotes_log name > channel owner > lead > (tracker only) uCRM `salesPerson` | the person who made the quote first; ambiguity names nobody |
| S5 | Extend the follow-up engine (`kind='quote'`); add a tracker | reuses the hardened sender; one open row per thread; docs/55 §9 |
| S6 | The tracker is derived from evidence, not hooked into the send sites | one definition for report and tracker; failed sends are never chased |
| S7 | Templated chase, no amount, no name, a STOP line | D3 is factual; no wrong price; testable; nameless logs |
| S8 | Chase from the spoken-to number, else the quote's own thread; salesperson numbers only with `wa_followups_on_owned_numbers` | D3; cold-number and ban risk; docs/65 §AC.1 |
| S9 | Fail-closed ledgers, not `notification_dedup` | that table fails open and purges at 45 days |
| S10 | Daily list on WhatsApp at 08:00 Mon–Sat, quote numbers only; optional e-mail | staff live on WhatsApp; the logging rule |
| S11 | E-mail copy: step 1 only by default, only after a sent WhatsApp step, with a one-click unsubscribe | avoids spam; STOP must hold everywhere |
| S12 | After-sales v1: welcome / day 3 / day 30 on WhatsApp; outages counted, not sent | minimal and reversible |

#### 7. Could not be established (read-only, no server, no network)

- **Server settings.** The values of `followup_enabled`, `followup_auto_send`, `multi_number_channels_enabled`, `wa_followups_on_owned_numbers`, `kyc_messages_like_crm`, `wa_force_accounts`, `customer_emails_enabled`, `reminder_email_enabled` and `customer_wa_install_scheduled`.
- **Whether the PDF send is stored.** `sendDocument` showed no write to `wa_messages` in what was read, so the report relies on the text message.
- **The stored phone form.** Whether the phone in `wa_messages` is always the international form after `NotifyGate::PHONE_FORM`; the report normalises both sides anyway.
- **History coverage.** Whether `quotes_log` has already been cut at 2,000 rows on the server, and whether `tools/wa_prune_history.php` was ever run. The report prints coverage so that a short history shows.
- **The clock of every `date()` stamp.** Whether every web entry point writes `date()` stamps (`kyc_applications.submitted_at`, `quotes_log.created_at`) in Kampala time. Only UTC `wa_messages` times bound the windows.
- **uCRM API details.** The quote issue-date field name, the payments filter parameters, a tickets-by-client query, and whether `createdBy` exists on quotes.
- **Lead capture.** Whether it writes `quote_requested` on the server (docs/55 §3 defect a).
- **How DishNet titles installation jobs.** `InstallScheduledWhatsApp`'s "title names an installation" rule is to be reused; its exact text was not read.
- **Outage scope.** Whether uCRM raises `service.outage` on this install, and for what.
- **Staff e-mail.** Whether salespeople's staff rows carry an e-mail address.
- **Operator decisions** (docs/72 §E): all wording, the e-mail sender, the review URL, `quote_chase_cold_channel`, and names on the daily list.
- **Migration numbers.** Whether the catalogue or media parts need migration numbers; 089 and 090 are proposed here, 085 belongs to the media port.

#### 8. Observed in passing: recorded, not changed by this part

- On a first activation via `service.activate`, a new customer is told "Service Restored … back online" (`L:webhook.php:2121-2132`). §5.3 proposes the fix behind a switch.
- `quote_wa_log.json` and `webhook_log.json` lines carry customer phones and names (`L:cron_quote_wa.php:212,347,433`; `L:webhook.php:3074,3225`). This is against this release's logging rule.
- `notification_dedup` claims fail open (`L:lib/NotificationService.php:3227-3229,3246-3248`; `L:lib/CustomerEmailDispatcher.php:87-91`).
- Quote senders claim before sending (`L:cron_quote_wa.php:196,336`), so a failed send stays claimed and only `NotificationRetry` can deliver it later (`L:lib/NotificationRetry.php:32`).
- `InvoiceReminders` run on Sundays. `JobWindow` has no weekday, and skipping a day would lose that day's exact-day tier, so it needs its own decision.
- The outage bulk form has no dedupe, no window and no throttle beyond a 0.2 s sleep (`L:includes/notify_actions.php:50-84`).
- `includes/api/api_whatsapp.php` carries a default feed secret in source, near line 768. The value is not reproduced here; it needs its own review.

### F.2 Draft 2 — the catalogue from uCRM, voice, photos, documents, batching, location (designer B)

### docs/72 §F (part) — the catalogue from uCRM, the AI's senses, batching and location: design

Status: **design only. Nothing was changed, run or tested, and nothing reached the network.** Base: `origin/release/5.18.102` = `1a0ea5f`.

#### 0. Conventions used here

- **Citations.**
  - `L:path:line` stands for `origin/release/5.18.102:dishnet-hybrid-sudan/path:line`. For example, `L:lib/UcrmQuoteLines.php:511` is `origin/release/5.18.102:dishnet-hybrid-sudan/lib/UcrmQuoteLines.php:511`.
  - `D:path:line` is the development tip (working tree at `1e50b7c`) under `dishnet-hybrid-sudan/`.
  - Root documents are cited as `docs/NN`.
- **Gates.**
  - Catalogue work sits behind `QuoteOrganization::setting(...)['on']` (`L:lib/QuoteOrganization.php:80`), as 5.18.102 does.
  - The AI parts sit behind the tenant gate `StaffJobsGate::applies($config, $dataDir)` (`L:lib/StaffJobsGate.php`, true only when `TenantProfile::current()->id() === 'uganda'`, and false on any doubt).
  - Every switch below defaults OFF. With a switch off, Uganda runs the 5.18.102 behaviour, and South Sudan stays byte for byte unchanged.
- **Logs.** Every new log line carries counts, ids, fixed reason codes, HTTP statuses and durations. It never carries a name, phone number, JID, price, transcript, description, caption or file name.
- **PHP 7.4.** No `match`, no `?->`, no union types, no named arguments. No `CURLStringFile`, which is 8.1+ (see §2.5).

---

#### 1. The catalogue straight from uCRM (D1)

##### 1.1 What 5.18.102 does today

- **The form draws from the plugin's rows.** The KYC form draws the plugin's `subscription_plans` rows and `kyc_devices` rows (`L:tabs/sales/kyc_form.php:39-50`, `:1329-1366`, `:1611-1659`). Each row is priced only through an admin's link (`L:lib/UcrmQuoteLines.php:289-334`, judge `L:lib/UcrmQuoteLinks.php:172`).
- **Rows are filtered by plugin type.** The form shows rows by their plugin `type` (`L:tabs/sales/kyc_form.php:1698-1703`, `:1740-1761`). It picks defaults by keyword: "mini" kit and "residential" plan (`:1707-1726`, `:1764-1836`). That keyword pick is already an unnamed "standard bundle".
- **What the form posts.** It posts plugin ids (`package_choice`, `device_id`, `hw_cart_json`, `:842-844`), plus `ucrm_total_seen`, `ucrm_links_seen` and the marker `ucrm_quote_form=5.18.102` (`:847-851`).
- **How the sale is resolved.** `saleFromPost` reads the post (`L:lib/KycService.php:1663-1677`). `resolve()` looks up plugin rows by id (`L:lib/UcrmQuoteLines.php:511-592`). `cashRefusal` (`L:lib/KycService.php:1712-1730`) refuses Cash on four grounds: unpriced, zero, missing or different total (`CASH_CHANGED`), and changed links (`CASH_RELINKED`).
- **Rules that compare uCRM with uCRM** (docs/71 §R.3.1 rules 4–8):
  - counted rows (`L:lib/UcrmQuoteLines.php:264-279`);
  - `badProduct` (`:777-795`);
  - plan rules 6a–6c and price equality (`:366-391`, `:436-456`);
  - rule 7, "hardware not keyed like any service plan" (`:742-746`);
  - rule 8, "installation ≠ an offered kit" (`:407-426`).
- **Where these rules come from.** They exist because the WhatsApp quote summary is kept byte for byte, and it classifies a line as monthly by plan key (`L:webhook.php:339-358`, `:2944`).

##### 1.2 Decisions

| # | Decision | Reason |
|---|---|---|
| C-1 | **A new "direct" mode of the one resolver.** `UcrmQuoteLines` gains `resolveDirect()`, `offered()` and `confirmDirect()`. These reuse the private rule functions above, unchanged. Link mode stays for the switch-off state, for 5.18.102 retries and for rollback | One class must keep pricing the form, the cash and every quote (docs/71 §D). Reusing the rule functions means direct mode cannot apply different rules |
| C-2 | **Switch `ucrm_catalogue_direct`** (bool, off), read only where the quoting-organization gate is on | D4. Off keeps 5.18.102's link behaviour exactly, including D5: "the admin links its 5 plans meanwhile" |
| C-3 | **No uCRM id is ever written into `package_choice`, `device_id`, `hw_cart_json` or `quote_sale_cart`.** Direct sales use new fields | Those fields are read as *plugin* row ids by 5.18.102 code: `L:lib/KycService.php:1686-1692` (retry), `:206-207`, `:2829-2830`, `L:lib/KycCrmSync.php:407`, `L:tabs/accounts/accounts_ledger.php:556`. A uCRM id there could price the wrong plugin row after a rollback |
| C-4 | **The installation is one setting, `ucrm_install_product_id`**, validated against uCRM when it is set (in `tools/set_config.php`, as 5.18.99 does for `ucrm_quote_organization_id`, `L:tools/set_config.php`), and on the catalogue screen | No name is matched (docs/71 D14), and there is no link store to hold it. docs/71 D13 was dropped only because "nothing could write it"; set_config now can |
| C-5 | **Hidden products: `ucrm_quote_hidden_product_ids`** (an id list), plus the website's existing `public_price_exclude` (`L:lib/PublicPriceFeed.php:24-25`) | Products that are neither hardware nor installation (consultancy, fees) must not be offered. Honouring the website's own exclusion makes the form and the site list the same items (the owner: *"same like website"*) |

##### 1.3 Which uCRM items are offered

Everything comes from one read: `catalogue()`, `GET products?limit=500` and `service-plans?limit=500`, failing closed (`L:lib/UcrmQuoteLines.php:206-251`).

**Plans.** A service plan is offered when all of these hold:
- it counts (`:270-279`);
- the list holds fewer than 200 rows (6c);
- its key is non-empty and unique across all listed plans (6a);
- it has exactly one enabled 1-month period (`:749-757`), and `planPrice` equals that price (6b);
- **exactly one counted product has the same key.**

The line is that product: its name, its price (which must equal the 1-month price, `:376-380`), and unit `month`. It must pass `badProduct`.

The "exactly one product" rule is docs/71 §D.3's original rule, restored. D14 dropped it only because a link named one id. With no link, two products spelled like one plan are ambiguous. Such a plan is shown "not offered: two products in uCRM are spelled like it". The speed shown comes from the service plan's `downloadSpeed`, as the website shows it (`L:lib/PublicPriceFeed.php:36`).

**Hardware.** A product is offered as hardware when all of these hold:
- it counts;
- it passes `badProduct`;
- it is not keyed like any listed service plan (rule 7);
- it is not `ucrm_install_product_id` (rule 8, now a plain id comparison);
- it is not hidden (C-5).

It is grouped as *kit* or *accessory* with the website's own split (`ShopCatalogue::accessoryNameKeys`, `L:lib/PublicPriceFeed.php:50-77`). That grouping is display only and never prices anything. Quantity is 1–20.

**Installation.** The product named by `ucrm_install_product_id` must count, pass `badProduct` and not be keyed like a plan. It is added once, when "DishNet installs" (docs/71 D2, D8). If the setting is unset, "DishNet installs" is unpriced: *"the installation product is not set — an admin sets it on the uCRM catalogue screen"*.

**Types.** Every offered plan and hardware item is shown under Starlink. Fiber stays always unpriced on Uganda (`L:lib/UcrmQuoteLines.php:536-539`). SIM and LTE show nothing priced, as today. The type filter (`L:tabs/sales/kyc_form.php:1740-1761`) is fed `starlink` for every direct row.

##### 1.4 How the agent chooses (the form)

- The plan and hardware lists are drawn from `offered()`. These reads use the 60-second page cache and the short timeouts (`L:lib/UcrmQuoteLines.php:157-177`). The cache shape version is bumped, so an old cache is a miss.
- Each cell carries the item's uCRM id, its kind, uCRM's name and price, and a **token**: the first 16 hex characters of `sha256(json(line))` over productId, label, price and unit.
- Items that are not offered do not appear on the form. They appear on the catalogue screen (§1.8) with their reasons.
- The posted fields are:
  - `ucrm_plan` = `"<servicePlanId>:<productId>"`;
  - `ucrm_cart_json` = `[{"p":id,"q":n}]`;
  - `install_by`;
  - `ucrm_total_seen`;
  - `ucrm_items_seen`: the selection's tokens, sorted;
  - `ucrm_quote_form` = a **new marker** (e.g. `5.18.103-ucrm`);
  - the legacy fields, posted empty.
- **The applyDefaults keyword pick** (`:1764-1836`) is replaced on Uganda direct by the standard quotation (§1.12). With none defined, nothing is preselected.

##### 1.5 Pricing by uCRM id, with no link store

- `saleFromPost` gains the direct fields. In direct mode `entry` is `web` only for the new marker (C-7).
- `resolveDirect($sale)` runs in this order:
  1. the entry rule;
  2. Fiber;
  3. a readable catalogue;
  4. the plan pair, through the plan rules with `$link = null`;
  5. each hardware id, through the hardware rules plus the install-id and hidden checks;
  6. the installation.

  It returns `no_plan`, `unpriced` (all or nothing) or `priced`, with lines `{productId, label, price, quantity, unit}` and a total, exactly as `resolve()` does (`L:lib/UcrmQuoteLines.php:587-591`).
- It runs **once, before any write**, at the same place as today (`L:lib/KycService.php:549-564`). handleNew's check `package_choice > 0` (`:735-738`) is replaced in direct mode by "a plan pair was posted". `offer_name` is the plan line's label, and the client note's device title is the first hardware label (`:789`, `:1153`).
- handleExisting is reached only through the app APIs (docs/71 §R.4.2), so it stays `app`: unpriced.

##### 1.6 What of 5.18.102's safety carries over

| Safety | In direct mode | Why |
|---|---|---|
| **Entry rule** (`L:lib/UcrmQuoteLines.php:525-534`, `L:lib/KycService.php:1675`) | **Kept, with a new marker (C-7).** With the switch on, only the direct marker is `web`. A form opened before the switch (marker 5.18.102, plugin ids) is `app`: Cash is refused by `CASH_UNPRICED` with the entry reason, and Credit gets P1 | The ids in an old form are plugin ids. Reading them as uCRM ids is exactly C-3's hazard |
| `CASH_UNPRICED`, `CASH_NO_INSTALL`, `CASH_ZERO`, `CASH_NO_TOTAL`, `CASH_CHANGED` (`L:lib/UcrmQuoteLines.php:97-102`) | Kept verbatim. `CASH_CHANGED` still drops the page cache (`L:lib/KycService.php:562`) | Same causes, same remedies |
| **`CASH_RELINKED`** (`:100`, `L:lib/KycService.php:1726-1728`) | **Retired in direct mode, and replaced by the token check**: the new text `CASH_ITEM_CHANGED`, *"A product on this sale changed in uCRM since the form was opened — reload the form. Nothing was saved and no money was taken."*, which also drops the page cache | There is no link left to change, because the posted id *is* the product. The token also closes docs/71 §R.4.7's recorded limit: a rename, or a twin swapped at the same id and price, now refuses Cash |
| **The read-back** (docs/71 §F.6, `L:lib/KycService.php:2248-2289`) | **Unchanged** | It compares the created quote with the lines sent, and the line shape is identical |
| P1, P2, P3 and `quote_refused*` | Kept. `P1_UNLINKED` does not arise in direct mode; it stays for 5.18.102 applications | No "not linked" reason exists in direct mode |
| The rules that compare uCRM with uCRM (rules 4–8) | Kept, through the same functions | The WhatsApp summary is unchanged (`L:webhook.php:339-358`) |

##### 1.7 Stored applications, retries and Make the quotation

- **What is stored** (`linesRecord`, `L:lib/KycService.php:1737-1759`):
  - `quote_lines_source = 'ucrm-direct-1'`, `quote_items`, `quote_items_total`, `quote_sale_entry`;
  - **`quote_item_ids`** `{plan:{sp,p}, hw:[{p,q}], install:p|null}`;
  - `offer_price` and `device_price`.
  - It writes no `quote_link_ids`, and none of the legacy cart keys (C-3).
- **Retries.** `retryLines` (`:1861-1903`) gains a branch for that source: `confirmDirect()` re-judges each stored id under the same rules and requires the stored price, at 2 dp. Its reasons are worded like `confirmLines` (`L:lib/UcrmQuoteLines.php:613-666`), and the Cash/P2 rule is unchanged. Applications saved under 5.18.102 keep the link branch.
- **Make the quotation** shares this implementation (`L:lib/KycService.php:2048`, `L:lib/KycCrmSync.php:366`).

##### 1.8 The link store, and the Plans and Hardware screens

- **The link store** (`ucrm_quote_links` in `plugin.sqlite3`, docs/71 §R.1):
  - frozen, never deleted;
  - still read for retries and Make the quotation of applications saved under 5.18.102;
  - kept whole for a rollback to 5.18.102, which still prices through the links.
  - With direct on, `ucrm_link_save` and `ucrm_link_clear` (`L:includes/post/post_sync.php:715`, `:878`) answer 409: *"On Uganda, plans and hardware come straight from uCRM."*
- **The screens on Uganda with direct on.**
  - The nav entries (`L:includes/navigation.php:503-512`) open a read-only **uCRM catalogue** page instead: offered plans, hardware and accessories with uCRM's price; *not offered*, with each reason; the installation product (set it here); the hidden ids; the standard quotations (§1.12).
  - `save_plan`, `save_hardware` and their deletes (`L:includes/post/post_sync.php:418`, `:549`, `:639`, `:670`) answer 409. The rows are no longer read on Uganda, so editing them would mislead.
  - Sync, Auto-map and Push already answer 409 (`L:includes/api/api_products_admin.php:406`, `:557`, `:616`, `:823`, `:877`).
- **South Sudan.** The screens stay exactly as they are.
- **The plugin rows.** They stay in the store for rollback and for the stock seed (`L:includes/api/api_stock.php:33-36`).

##### 1.9 Send Quotation and lead quotes

- The plan picker (`L:lib/QuotationService.php:869-901`) lists `offered()` plans by service-plan id, with no plugin rows.
- A new hardware and installation picker adds lines carrying `productId` at uCRM's price, which is not editable. This closes docs/71 §C's "Out: typed lines carry no productId" for picked lines.
- Lines staff type themselves stay allowed and are marked "custom (not from uCRM)". The rule "no line at price 0" stays (`:975-985`).
- `buildLeadItems` (`:1109-1122`) takes the one offered plan whose key equals `planKey(interest_plan)`. When none, or more than one, matches, it adds nothing.
- The lead's free text has no id. This matching is a pre-fill a person confirms on screen; it is not the plugin-to-uCRM matching D14 rejected.

##### 1.10 The retailer app and the mobile API

**Decision C-8: they stay unpriced in this release.** The entry rule holds: Credit gets P1, Cash is refused.
- Both APIs gain a read-only `ucrm_catalogue` action, behind the gate, returning `{kind, id, name, price, unit, group}`. It carries no link data and no organization id.
- `devices` and `packages` (`L:includes/api/api_retailer.php:7-8`, `L:api/index.php:197-219`) stay byte for byte.
- The web retailer app (`retailer/index.html`) can move to `ucrm_catalogue` and the direct fields as its own switched step.
- The mobile app's source is not in this repository (§7).
- Reason: a pricing path through an app must carry the same cash checks (posted total and tokens). Changing two clients blind would bypass them.
- **Known risk, recorded:** until then the apps show the plugin's stale rows.

##### 1.11 The website's public price feed

**Switch `ucrm_catalogue_public_feed`** (off). When it is on:
- `prices.php` and `shop.php` (`L:prices.php:61-65`, `L:shop.php:48`) build the feed from `offered()`, keeping the same v1 keys `plans`, `hardware` and `accessories` (`L:lib/PublicPriceFeed.php:83-91`):
  - **plans** are the quotable plans at their 1-month price;
  - **hardware and accessories** are the offered items;
  - **the installation** is its own key.
- The read follows the resolver's fail-closed rule, serving a stale cache rather than a partial list (`L:prices.php:53-63`).

Today's feed reads with no limit and does not filter archived, inactive or other-organization rows (`L:prices.php:61-62`), so it can list items the form cannot quote. **Freshness differs:** the feed caches 10 minutes plus `max-age=300`, against 60 s on the form (`L:prices.php:38`, `:48`), so the site may lag by up to about 15 minutes. That is accepted and stated.

##### 1.12 The standard one-click quotation (the mechanism; what it contains is the operator's question)

- **The record.** It is stored in the plugin store as the table `ucrm_standard_quotes`. The table is created lazily, read only behind the gate (as docs/71 §R.1.5) and written under `withLock`. Each record holds:
  - a title;
  - `{sp, p}` for the plan, `[{p, q}]` for the hardware, and whether the installation is included;
  - the permitted roles;
  - the active marker and provenance (`by_id`, `at_ts`).
- **Ids only, never prices.** The bundle is priced live when it is pressed. If any item no longer resolves, the button is disabled and shows the reason. There is never a partial bundle.
- **Where it can be pressed.**
  - **On the KYC form**, "Standard: <title>" pre-fills the selection. The agent still reviews and submits; it is not a sale.
  - **On Send Quotation and the lead page**, "Send standard quotation" creates the uCRM quote through `createCrmQuote` with `productId` lines (`L:lib/QuotationService.php:712`). The WhatsApp copy goes out through the existing path, claimed once in `wa_sent_quotes` (`:1190-1200`).
- **New rules for this path.**
  - It **fails closed** when the claim cannot be written. Today's path sends anyway (`:1197-1198`).
  - It refuses a number with a SCOPE_ALL opt-out. A quote the customer asked for is not proactive (`L:lib/ContactOptOut.php:36-42`).
- **Who may press:** a new permission, `quote_standard`, checked through `can()`, as the nav does (`L:includes/navigation.php:503`).

##### 1.13 Switches and activation order

1. Set `ucrm_install_product_id`.
2. Open the catalogue screen. Every item meant to be sold must read "offered".
3. Turn `ucrm_catalogue_direct` on (the form, Send Quotation, lead quotes).
4. Define a standard quotation and grant `quote_standard`.
5. Turn `ucrm_catalogue_public_feed` on.
6. Later, and separately, the web retailer app.

A read-only `tools/ucrm_catalogue_check.php` prints counts only: offered plans and hardware, items not offered by reason code, and the installation set yes/no.

##### 1.14 South Sudan, the switch off, and rollback

- **South Sudan:** the gate is off, so nothing is constructed (`L:lib/UcrmQuoteLines.php:52-53`).
- **Uganda with `ucrm_catalogue_direct` off:** 5.18.102 exactly.
- **Rollback to 5.18.102.** The new keys are ignored. Direct applications are unmarked for 5.18.102, which resolves them with an empty `package_choice`: `no_plan` gives P1, or P2 where cash was collected (`L:lib/KycService.php:1880-1895`). No wrong quote can result (C-3). The intact link store keeps 5.18.102 working.

---

#### 2. Voice notes, photos and documents through OpenAI (D2)

##### 2.1 What exists, on the development tip only

- **The foundation.** Migration `D:migrations/085_wa_media.sql`, `InboundMedia`, `MediaFetcher`, `MediaBlob`, `MediaPolicy` (`D:lib/MediaPolicy.php`), and `D:workers/MediaWorker.php` run by `D:run_media_worker.php`.
- **The three kinds.** Voice (`D:lib/VoiceTranscription.php`), images (`D:lib/ImageUnderstanding.php` with `PaymentEvidence`) and documents (`DocumentExtraction`, `DocumentClassifier`, `PdfReader` and readers). Documents have a four-rung ladder (`D:lib/MediaPolicy.php:130-151`).
- **The ports.** `TranscriberPort`, `ImageDescriberPort` and `DocumentOcrPort` exist, with factories that know only `none` and `fake` (`D:lib/Transcriber.php:39-66`, `D:lib/ImageDescriber.php:42-69`, `D:lib/DocumentOcr.php:35-62`).
- **There is no tenant gate** in any of these files.

##### 2.2 The port

**New files, copied from the development tip, then adapted where §2.3–§2.8 say so:**
- `lib/` `MediaBlob`, `MediaFetcher`, `InboundMedia`, `MediaPolicy`, `Transcriber`, `VoiceTranscription`, `ImageDescriber`, `ImageUnderstanding`, `PaymentEvidence`, `DocumentClassifier`, `DocumentDeadline`, `DocumentExtraction`, `DocumentOcr`, `DocumentSniffer`, `DocxReader`, `OoxmlArchive`, `PdfReader`, `SheetReader`, `TextReader`, `Handover`;
- `workers/MediaWorker.php`, `run_media_worker.php`, `tools/media_status.php`;
- `migrations/085_wa_media.sql`, **byte for byte and under its own name**. The runner applies unrecorded files in alphabetical order whatever the highest number applied is (`L:lib/MigrationRunner.php:170-202`). It is additive, and keeping the name means one file for one table if the branches meet.
- tests: `test_media_foundation`, `test_voice_media`, `test_image_media`, `test_document_media`, `test_document_pdf`, `test_document_activation`, and `fixtures/document_fixtures.php`.
- **New files written for this release:** `lib/OpenAiMedia.php`, holding the three adapters and their shared HTTP code, and a migration `0NN_wa_media_provider.sql`, the next free number (§2.7).

**Live files touched.** The "same?" column compares the live file with the development tip.

| Live file | same? | Conflict, and resolution |
|---|---|---|
| `evo_webhook.php` | differs (dev +106/−15 against live) | Additions only on the dev side, applied onto live: two requires, the 8c record-and-queue after 8b2 (`D:evo_webhook.php:379-425`, after `L:evo_webhook.php:366-374`), the media-aware no-text branch (`L:…:382-387`), 9a and 9b (`D:…:444-464`), the two-worker spawn (`D:…:497-540` replacing `L:…:418-445`), the media count in the log line, and the unwrap in `evoExtractText` (`D:…:559`, `L:…:465`). **Changed while porting:** 9a uses the *reply* rung, not `imageEnabled` (§2.4). The unwrap runs only when media is enabled (§2.3) |
| `workers/AiReplyWorker.php` | differs (+45/−89) | The dev side adds the `voice`, `image` and `document` context, the origin in the log line, `commitments` and `modality`, and moves `escalate()` into `lib/Handover.php`. Live's `escalate()` (`L:workers/AiReplyWorker.php:1578-1643`) matches `D:lib/Handover.php:20-80` statement for statement, the line owner included. Port the move, keep live's comments, and pin it with a test |
| `lib/EvolutionApiService.php` | differs (+25/−0) | Pure addition of `getBase64FromMediaMessage`. On Uganda it travels through `requestUg` (`L:…:892-894`, `:813`), which refuses to re-POST after a request has left; see §7 |
| `cron/event_processor.php` | differs (+5/−5) | Add `ai.media` to the **Uganda list only** (`L:cron/event_processor.php` `$_epWorkerOwned`). The dev side also changed the non-Uganda list and the comment's version numbers; South Sudan keeps `['ai.reply']` and live's wording |
| `tools/set_config.php` | differs (+89/−31) | Keep live's `ucrm_quote_organization_id` block (5.18.99) and add the dev side's `ai_media_*` keys, plus this design's new keys |
| `lib/ConversationService.php` | differs (+8) | The wrapped-message unwrap in `importEvoMessage` (`L:…:1124`, around `:1133`) **changes a live path on every tenant**. It runs only when `MediaPolicy::enabled($config, $dataDir)` |
| `cron/master.php`, `tools/cron_status.php`, `lib/BrainContext.php`, `lib/DishNetAiBrain.php`, `lib/ReplyPrivacyGuard.php` | differ (dev additions only) | Pure additions on the dev side: the media job (`D:cron/master.php:199`), the VOICE, IMAGE and DOCUMENT prompt blocks, the BrainContext keys (allowlist `L:lib/BrainContext.php:82`) and the commitments guard |
| `workers/WorkerBase.php`, `lib/EventBus.php`, `lib/AutomationPolicy.php`, `lib/ChannelContext.php` | identical | No conflict. `WorkerBase` gains one hook for batching (§3) |

##### 2.3 The Uganda gate

`MediaPolicy::enabled(array $config, ?string $dataDir)` requires `StaffJobsGate::applies()` as well as `ai_media_enabled`. A test fails if any caller omits `$dataDir`, as with the gate's own test. Every rung function derives from it. South Sudan records nothing, unwraps nothing and spawns nothing.

##### 2.4 The ladder for each kind

The document ladder exists (`D:lib/MediaPolicy.php:113-151`). **Voice and images get the same shape.** `ai_media_voice` alone becomes the dry run, which changes its development meaning; it was never released.

| Rung | Voice: flags (images: `ai_media_image*`) | What happens |
|---|---|---|
| dry run | `ai_media_voice` | The file is sent to OpenAI and the result recorded on `wa_media` and in the message's metadata. The body is untouched, nobody is told and no AI turn is queued. STOP is honoured (§2.9) |
| hand-over | `+ ai_media_voice_handover` | **Every** voice note or photo goes to a person (`Handover::escalate`). The body becomes the labelled transcript or description, so staff read it in the Inbox. The AI says nothing |
| reply | `+ ai_media_voice_reply` | The existing assistant answers, labelled (as `D:lib/VoiceTranscription.php:186-200`). Payment and identity material still goes to a person (§2.8) |

- **Why every item goes to a person at hand-over.** Unlike a document, a voice note has no harmless class to leave alone. A transcript nobody answers is worse than today's watchdog.
- **Why 9a in the webhook uses `imageReplyEnabled`.** With 9a on `imageEnabled`, as on the dev side (`D:evo_webhook.php:444-452`), the dry run would suppress the caption's text answer.

##### 2.5 The OpenAI adapters, behind the existing ports

The factories gain the value `openai`. The key alone selects nothing: the factory must name the provider.

- **Voice (`OpenAiTranscriber`).**
  - Request: `POST https://api.openai.com/v1/audio/transcriptions`, model `ai_media_openai_stt_model`, default `whisper-1` (D2), `response_format=verbose_json`, no forced language.
  - **The multipart body is built in memory** from `MediaBlob`. `CURLStringFile` is PHP 8.1+, and `CURLFile` needs a path, which would break "never on disk" (docs/58 §11).
  - Formats: OGG/Opus is accepted; `audio/amr`, which the policy allows (`D:lib/MediaPolicy.php:72-73`), answers `unsupported_audio` and goes to a person.
  - Quality: segments with average `no_speech_prob > 0.6`, or average `avg_logprob < -1.0`, answer the new reason `unclear_audio`, and the note goes to a person. These thresholds are to be measured in the dry run.
- **Images (`OpenAiImageDescriber`).**
  - Request: `POST /v1/chat/completions`, model `ai_media_openai_vision_model`, default `gpt-4o` (D2), `response_format: json_object`, `temperature 0`, `max_tokens 300`, the image as a base64 data URL with `detail: auto`.
  - The answer must be `{description, classification ∈ CLASSES, signals[]}`.
  - **The caption is not sent:** the brain receives it separately, and sending less means less exposure to prompt injection.
- **Scanned PDFs (`OpenAiDocumentOcr`).**
  - Request: chat completions with a base64 PDF `file` part, model `gpt-4o`.
  - Used only for a PDF with no text layer, with at most `ai_media_document_ocr_max_pages` pages (default 5). A longer one goes to a person.
  - The returned text goes through **`DocumentClassifier`, unchanged**, so a scanned identity document or statement goes to a person (`D:lib/DocumentClassifier.php:24-26`).
  - D2 supersedes docs/58 D-2 ("no OCR").
- **Timeouts.** Connect 5 s. The total is the existing policy value: voice 30 s (5–120), image 30 s (5–120), document 20 s (5–60) (`D:lib/MediaPolicy.php:16-49`).
- **Errors.** HTTP 429 and 5xx answer `provider_error` and are retried. 400 and 413 answer a permanent code. `detail` carries only the HTTP status and the error type.

##### 2.6 Where the key comes from

The existing `openai_api_key`: a secret in `L:lib/PluginConfig.php:29`, kept in the vault (`L:lib/ConfigVault.php:40`), set on the admin screen (`L:includes/post/post_admin.php:731-735`). It is read **whatever `ai_provider` is**, because the brain may run Claude (`L:lib/DishNetAiBrain.php:55-63`) while D2 fixes OpenAI for media. An empty key answers `provider_missing` and hands the item to a person, never an invented answer.

##### 2.7 Cost bounds

- **The migration `0NN_wa_media_provider.sql`** adds `provider`, `provider_ms`, `provider_units` (minutes, images or pages) and `provider_at` to `wa_media`.
- **Daily caps**, counted per Kampala day:
  - `ai_media_daily_voice_minutes` (default 120);
  - `ai_media_daily_images` (default 300);
  - `ai_media_daily_ocr_pages` (default 100);
  - `ai_media_conversation_daily_max` (default 10 items for one conversation).
- **When a cap is reached**, the item goes to a person with `budget_exhausted`. It is never dropped silently.
- **Per-item caps already in place:** voice 120 s, fetch 15 MiB, documents 10 MiB (`D:lib/MediaPolicy.php:13-45`).
- **Estimates at OpenAI's list prices, to be confirmed by the operator:** about $0.006 per minute of `whisper-1`, and under a cent per image or per scanned page with gpt-4o. At the default caps that is a few dollars a day at most.

##### 2.8 Always to a person

- **Images:**
  - `payment_proof`, or `PaymentEvidence::looksLikePayment` (`D:lib/ImageUnderstanding.php:141`, docs/57 §6);
  - **and also `document_photo`**: an ID card, statement or contract photographed. On the development tip that class reaches the AI. This design closes that gap;
  - **and** any description that `DocumentClassifier` rates human-only.
- **Documents:** `HUMAN_ONLY`, which covers credential, identity, payment, statement, invoice, contract and quotation (`D:lib/DocumentClassifier.php:25`).
- **Media is never answered on a number** where the assistant is off, because 8c follows 8b2, or where AutomationPolicy refuses (`L:workers/AiReplyWorker.php:203-211`, and the policy check at the send).

##### 2.9 STOP in a voice note

`ContactOptOut::detect` runs on the raw transcript, conservatively: at most 40 characters, whole words (`L:lib/ContactOptOut.php:233-251`). Scope is `proactive`, source `voice_keyword` (`D:lib/VoiceTranscription.php:166-181`).

**Decision M-STOP: it is honoured from the dry-run rung on.** Once the system knows a STOP was said, ignoring it would break the binding rule. A false positive costs only proactive sends, and staff can lift it, finding it by its source. **The log names the media id and the matched word only. It never copies `L:evo_webhook.php:357`, which logs the phone (§6).**

##### 2.10 Logging and retention

- **Logs:** media id, conversation id, kind, rung, reason code, provider, milliseconds, bytes, seconds, character counts, HTTP status, and a 12-character sha prefix. Never a transcript, description, caption or file name.
- **Bytes** are never kept (`MediaBlob::wipe`).
- **Transcripts and descriptions** are kept as typed text is, in `wa_media.understanding` and the message body, indefinitely. That inherits docs/58 E-4.
- **Human-only material:** a masked 300-character excerpt, or nothing for identity documents and credentials (docs/58 §11).
- **On OpenAI's side**, API data use and retention are as their current API terms say. The operator confirms them, and any zero-retention option, before rung 1 (§7).

##### 2.11 The Privacy Policy line on processors

Today the policy lists voice notes and photos as data collected (`dishnet-web-uganda/policies/bodies/privacy.html:31`), AI service companies among those data is shared with (`:59`) and transfers abroad (`:66`). §7 (`:68-69`) says nothing about media.

Proposed addition to §7:
> *"When you send us a voice note, photo or document on WhatsApp, our assistant may send it to OpenAI, our artificial-intelligence service provider, to transcribe, describe or read it so we can answer you. OpenAI processes it on our instructions, outside Uganda. We keep the text it produces with the conversation, not the file. Payment screenshots, proof of identity, statements and contracts are always checked by a person, and the assistant never confirms a payment."*

**It must be published before any provider is set to `openai`**, because the dry run already sends files. Whether to name OpenAI is the operator's call (docs/72 §E).

##### 2.12 Activation order and checks

1. Run the migrations.
2. Turn `ai_media_enabled` on: files are fetched only. Check fetch counts in `tools/media_status.php`.
3. **Voice:** set the provider to `openai`, then dry run → hand-over → reply. Each step is checked with counts from the status tool.
4. **Images:** the same three rungs.
5. **Documents:** the existing ladder, then OCR.

Before the first live pilot, operator decision D-60-2 (the shared EventBus stale-lock fix, docs/60 §6.3) is to be taken.

---

#### 3. Batching: a customer's quick messages answered once

**Switches.** `ai_reply_batch` (off), `ai_reply_batch_quiet_s` (default 8, range 3–20) and `ai_reply_batch_max_hold_s` (default 25, range quiet to 45). Uganda only, through `StaffJobsGate`.

**Where it goes.** In `AiReplyWorker::handle`, after the cheap exits (missing fields `L:workers/AiReplyWorker.php:174-177`, a colleague `:181-184`, a DishNet number `:190-196`) and **before** `replyRoleOrRefuse` and the policy check (`:203-211`). Placed there, a burst raises at most one refusal alert. It runs in four steps:

1. **Supersede.** If another `ai.reply` for the same conversation (`entity_id`) is not yet done (pending, failed or processing) and has a later `received_at`, ties broken by id, this event is acknowledged with the log "merged into a later message" and nothing is sent.
   - Ordering uses `received_at` because the media worker queues a transcript's event after a later text's event (`D:lib/VoiceTranscription.php:185-198`).
2. **Hold.** The worker holds while either of these is true:
   - the newest inbound message is younger than the quiet window, and the burst is younger than the maximum hold;
   - a `wa_media` row of this conversation is still unsettled (pending, fetching or fetched) and younger than the maximum hold.

   A hold is a `WorkerDefer`: parked, with no attempt counted (`L:workers/WorkerBase.php:126-131`, `L:lib/EventBus.php:304-320`), and the note "held: waiting for the customer to finish".
3. **Then today's code:** `parkBehindHuman` and `answeredMeanwhile` (`L:workers/AiReplyWorker.php:217-227`, `:1399-1426`). Because supersede comes first, only the newest event parks.
4. **Merge.** `message` = the payload messages of this conversation's `ai.reply` events received **after the last outbound message**, the human's or the assistant's. They are taken in order, one per line, capped at 10 messages and 2,000 characters. `location` is the latest one that is not null.
   - Payloads are used because they carry a pin's description and a transcript's label. The bodies stored in `wa_messages` may not.
   - The brain sends `message` as the final user turn (`L:lib/DishNetAiBrain.php:1735`).

**The worker lock and the exec spawn.**
- Each webhook post spawns `run_worker.php` (`L:evo_webhook.php:415-445`). A spawn that finds the lock exits ("another instance running", `L:workers/WorkerBase.php:97-99`, flock `:210-221`).
- Today a held event would wait for the cron run, up to a minute. Instead, `WorkerBase` gains one hook, `idleWaitSeconds(): int`, which returns 0 by default, so every other worker is unchanged. The loop calls it instead of breaking when nothing can be claimed (`:107-109`).
- `AiReplyWorker` returns a value only when batching is on and a held `ai.reply` falls due within its 45-second budget (`L:run_worker.php:43`). The loop then sleeps 1 s and claims again, picking up the newer messages the exited spawns left.
- Near the end of the budget the worker defers, and cron picks the event up.
- Cost: `UcrmLeadWorker`, in the same process (`L:run_worker.php:47-48`), starts later by at most the hold.

**Failure.** Superseded events are acknowledged, never failed. If the newest dies, `onDead` hands the whole burst to a person (`L:workers/AiReplyWorker.php:1475`). Replies are not proactive sends, so quiet hours do not apply, as with today's text replies.

---

#### 4. Location: a pin mapped to a town or district

**Today.** A pin is parsed and its country box checked (`L:lib/WaLocation.php:51-54`, `:78-125`) and stored on the message (`L:migrations/072_wa_message_location.sql`). It reaches the lead as `gpsLat` and `gpsLon` (`L:lib/UcrmLeadSync.php:285-296`, `:362-364`). **The Inbox shows no map and no place** (`L:tabs/engage/wa_inbox.php:382`). `MANIFEST.md:473` lists Nominatim, but no live code calls it.

**Options for the data.**
- **(a)** An offline list of Uganda towns and trading centres, with their districts and coordinates: the nearest point within about 15 km.
- **(b)** Offline, simplified district boundaries, tested point in polygon.
- **(c)** Live reverse geocoding. This sends the customer's coordinates to a third party and needs a decision; it is excluded.

**Decision G-1: (b) for the district and (a) for "near <town>".** Both ship in the plugin as `assets/geo/uganda_places.json`, with the source, version, licence and date in the file header. Reason: districts drive dispatch and the distributor territory map (`dist_territory_map.area_key`, `L:migrations/079_distributor_territory.sql`). Near a border, a nearest-centroid guess gets the district wrong; a polygon does not. New `lib/UgandaPlaces.php` is pure and offline.

**Switch.** `wa_location_area` (off), Uganda only and only where `WaLocation::country() === 'UG'`. An optional `install_districts` list gives staff an "in our install area / outside" badge.

**What the AI may say: unchanged.** The prompt keeps "do NOT say which town, district or road … a colleague confirms coverage" (`L:lib/DishNetAiBrain.php:1507-1525`). **The area is never put into the model's context.** The BrainContext allowlist fixes the location keys as `lat`, `lng`, `name` and `in_bounds` (`L:lib/BrainContext.php:82`, `:168-172`), so this is structural, not only an instruction. Reason: a confident wrong place is worse than asking.

**What staff see.** It is computed when read; no migration and no stored guess.
- **Inbox:** "📍 near <town>, <district> district (approximate, offline map data) · open map".
- **The uCRM lead note** (`L:lib/UcrmLeadSync.php:362-364`) gains the same words.
- **The installation job's description** gains the district and the map link.
- **Logs** say only "area found" or "area not found". Never coordinates.

---

#### 5. New switches and stores in my part (all off; Uganda only)

| Area | Switches and stores |
|---|---|
| Catalogue | `ucrm_catalogue_direct`, `ucrm_install_product_id`, `ucrm_quote_hidden_product_ids`, `ucrm_catalogue_public_feed`; the table `ucrm_standard_quotes`; the permission `quote_standard` |
| Media | `ai_media_enabled`; `ai_media_{voice,image}` plus `_handover` and `_reply`; `ai_media_document` plus `_handover` and `_reply`; `ai_{transcription,image,document}_provider=openai`; `ai_media_openai_{stt,vision}_model`; `ai_media_document_ocr_max_pages`; the four daily caps |
| Batching | `ai_reply_batch`, `ai_reply_batch_quiet_s`, `ai_reply_batch_max_hold_s` |
| Location | `wa_location_area`, `install_districts` |
| Migrations | `085_wa_media.sql` (as is), and `0NN_wa_media_provider.sql` with the release's next number. The catalogue, batching and location need none |

---

#### 6. Problems in the live code found while reading (outside this scope, recorded)

- **A phone number in a log line:** `L:evo_webhook.php:357-358` logs the phone when an opt-out is recorded.
- **A phone number in a log line:** `L:lib/QuotationService.php:1210` logs the phone when a WhatsApp send fails.
- **The quote WhatsApp claim fails open:** "sending anyway" (`L:lib/QuotationService.php:1197-1198`).
- **Quotes may bypass opt-outs:** quotations travel through `NotificationService::sendViaEvolution` with class `staff` (`L:lib/NotificationService.php:1943-1952`), so whether a STOP blocks a quote is not established.
- **The public feed is unfiltered:** `prices.php` does not filter archived, inactive or other-organization rows (`L:prices.php:61-62`).

---

#### 7. What I could not establish

- Whether uCRM 4.5.33 returns `isActive`, `archived` and `organizationId` on products and plans (docs/71 §D.2). Whether it has a product field that would tell hardware from a service product, which would make C-5 unnecessary. The default page size of `prices.php`'s unlimited reads.
- What uCRM holds today: whether each plan has exactly one product spelled like it, which product is the installation, and which products should be hidden.
- Whether Uganda's agents use the retailer web app, the PWA or the mobile app, and where the mobile app's source lives.
- OpenAI's current prices, data-retention terms and zero-retention option, and whether `gpt-4o` accepts PDF parts on the operator's account. No network was available.
- How well Whisper transcribes Luganda. Whisper is not documented for it; Swahili and English are. The `unclear_audio` thresholds must be measured in the dry run.
- How `MediaFetcher` treats `requestUg`'s "may have been sent" answer to the `getBase64FromMediaMessage` POST (`L:lib/EvolutionApiService.php:813-830`).
- The source, licence and freshness of a Uganda districts-and-towns dataset (districts change often), and its size after simplification.
- Whether the server's switches for the existing follow-up engine and the media flags are set. They are server state, and the release's checks read them.
- Whether the operator means quiet hours to cover replies. This design applies them to proactive sends only (§3).

### F.3 The adversarial review of both drafts (reviewer C)

### 72 §F: adversarial review of Draft 1 (measurement and follow-up) and Draft 2 (catalogue and the AI's senses)

Status: **review only. No file was changed, no test was run and nothing touched the network.**

Citation shorthand:
- `L:` = `origin/release/5.18.102:dishnet-hybrid-sudan/` at `1a0ea5f`.
- `D:` = the development working tree (`dishnet-hybrid-sudan/`).
- `W:` = the repository root.

#### 1. Method and what held up

I checked about 120 cited lines against `git show` of the live release and the development tree. Most citations are exact.

**Verified as stated:**
- `NotifyGate::applies` and `uganda()` (`L:lib/NotifyGate.php:54-70`).
- The QuoteWaLedger claim (`L:lib/QuoteWaLedger.php:36-42`).
- `sendWA` returns true whatever the transport answered (`L:lib/QuotationService.php:1203-1213`).
- The 2,000-row cap (`:1248`).
- `wa_messages` is written only when the send succeeded, under channel `support` or `accounts` (`L:lib/NotificationService.php:2412-2444`, `:2420`).
- The three quote markers (`L:lib/QuotationService.php:489`, `L:webhook.php:3002`, `L:includes/api/api_whatsapp.php:665,674`).
- The sender's nine re-checks and their order (`L:cron/followup_send.php:95-158`).
- The window and the Sunday rule (`L:lib/FollowUpPolicy.php:182-206`).
- `nextDueAfterSend` (`:258-275`).
- The fail-open dedupe and the 45-day purge (`L:lib/NotificationService.php:3227-3254`).
- "Service Restored" sent on a first activation (`L:webhook.php:2122-2132`, `:2160-2171`).
- MigrationRunner applies unrecorded files alphabetically, so an 085 added later still runs (`L:lib/MigrationRunner.php:170-203`).
- The live `escalate()` matches `D:lib/Handover.php:20-80` statement for statement.
- `WorkerBase`, `EventBus`, `AutomationPolicy` and `ChannelContext` are byte-identical between the release and the tip.
- The BrainContext location allowlist (`L:lib/BrainContext.php:82,166-173`).
- The location prohibition in the prompt (`L:lib/DishNetAiBrain.php:1522-1525`).
- `set_config` validates the organization against uCRM (`L:tools/set_config.php:457-472`).
- `requestUg` (`L:lib/EvolutionApiService.php:813-831,894`).
- Draft 1's note on a default feed secret in source (`L:includes/api/api_whatsapp.php:768`). The value is not reproduced here.

#### 2. Findings

##### 2.1 Blockers

**F1 — blocker (Draft 1): the quote switch does not stop quote messages.**

- **The contradiction.** §4.6 says that with `$quoteOn` false "each file runs its 5.18.102 code". It also says that turning the switch off "stops at once … the sender skips quote drafts". Both cannot be true.
- **What the 5.18.102 code does with quote rows.**
  - `followup_run` takes every due open row (`L:cron/followup_run.php:84`). That includes open `kind='quote'` rows. It asks the AI about them and auto-approves the answer when `followup_auto_send` is on (`:198-216`).
  - `approvedDrafts` has no kind filter (`L:lib/FollowUpService.php:336-345`).
  - `followup_send` sends any approved draft (`L:cron/followup_send.php:43,73,160`), without the quote pre-send check or the claim.
- **Effect.** Switching off mid-flight would leave open quote rows to the AI engine, which would write and send messages about quotes.
- **Fix.** Make the enquiry path's filter unconditional once 089 is applied:
  - add `COALESCE(f.kind,'enquiry')='enquiry'` in `due()` and in the enquiry branch of `approvedDrafts()`;
  - South Sudan has no quote rows, so its results are identical;
  - quote drafts are sent only by the quote branch, and only that branch requires the switch;
  - with the switch off, quote rows stay open but inert, and the doctor counts them;
  - add a test (switch off, an open quote row, `followup_auto_send` on) that sends nothing and asks the AI nothing.

**F2 — blocker for switching batching on (Draft 2): the idle wait would run inside `master.php`.**

- **Why.** `run_worker.php` is the `ai_reply` job (`L:cron/master.php:194`). Every job is `include`d in-process, one after another (`:385`), under a 300 s budget for the whole cycle (`:109-110`). When the budget runs out, "BUDGET EXCEEDED … deferring remaining jobs" (`:318-320`).
- **Effect.** `idleWaitSeconds` adds up to 45 s per cycle (`L:run_worker.php:43`). Everything after `ai_reply` in the list would be delayed or deferred: efris, dpo_reconcile, the watchdog, the four follow-up jobs, inv_notify, the backup, LTE, customer_reminders, notify_retry. Those are live 5.18.x behaviours.
- **Fix.**
  - The idle wait runs only in the process `evo_webhook` spawns, for example behind a `--spawned` argument on the exec line (`L:evo_webhook.php:444`). It is never used when the worker is included by `master.php`.
  - A test proves `master.php` runs `ai_reply` with no idle wait.

##### 2.2 Major

**F3 — Draft 1: a send claim that is never released stalls the sender.**
- **Why.** `INSERT OR IGNORE` on `(followup_id, attempt)` has no defined release. `recordFailure` deliberately leaves the draft approved so it is retried (`L:lib/FollowUpService.php:396-401`). The retry then finds its own claim and skips, forever.
- **Effect.** Each stuck draft keeps one of the sender's 10 places (`:336-345`, `ORDER BY decided_at LIMIT`; `L:cron/followup_send.php:73`). Ten of them stop every send, enquiry follow-ups included under `followup_send_guards`.
- **Fix.**
  - Settle every claim with an outcome.
  - A certain non-send (refused, or never left, per `NotificationRetry::classify`) allows a fresh claim: key the claims by `(followup_id, attempt, try)`. The ledger stays append-only.
  - A send that may have gone goes to `recordUncertain` (`:408-413`).
  - A claim left unsettled for more than 15 min counts as uncertain.

**F4 — Draft 1: owner rule 1 credits the person who typed the form, not the salesperson.**
- **Why.** `retailer_id` is the account that submitted the form (`L:lib/KycService.php:366`). `sales_person` is the salesperson chosen on the form, and falls back to the submitter only when empty (`:361`, `:1552-1563`). The same value feeds uCRM's Sales Person attribute (`:1541`).
- **Effect.** When office staff register for a salesperson, daily lists go to the wrong person.
- **Fix.** Use this order:
  1. `sales_person`, matched exactly to one active staff name;
  2. `retailer_id`, only when `sales_person` is empty or equals the submitter's name;
  3. the remaining rules.

**F5 — Draft 1: the fallback to "the quote's own thread" breaks for `accounts` and for a support number whose assistant is off.**
- **The `accounts` thread.** NotificationService files quotes under `'accounts'` (`L:lib/NotificationService.php:2420`). Evolution channels are `support`, `account` and `sales` (`:1888-1898`).
  - With the registry on, `instanceFor('accounts')` is empty, so the sender **closes** the chase (`L:cron/followup_send.php:139-146`).
  - With the registry off, there is no `evo_instance_accounts` (pattern at `:178`).
- **A support number with its assistant off.** With the registry on, `AutomationPolicy` refuses that number (`L:lib/AutomationPolicy.php:17-18,214-219`). Every chase to a customer who never wrote is then closed as final.
- **Who answers the reply.** A reply to a chase from `support` is answered in the support role, not the sales role.
- **Fix.**
  - Open the chase on the `account` thread for `accounts` quotes.
  - Have the report and the doctor count "chasable / refused by channel" before Q3.
  - Make `quote_chase_cold_channel` and the support assistant's state an explicit operator decision.

**F6 — Draft 1, WhatsApp tolerance: one shared daily limit per number, paced sends and no 08:00 burst.**
- **The gaps.**
  - The quote cap counts only `followup_sends`. After-sales has its own cap.
  - The support number carries never-wrote chases, day-3 and day-30 messages and enquiry follow-ups.
  - The sender sends up to 10 per 5-minute run with no spacing (`L:cron/followup_send.php:73`). After a Sunday or a night, the backlog leaves at 08:00 Monday as identical template texts. That is the pattern WhatsApp flags on unofficial numbers.
- **Fix.**
  - One daily budget per number, counted across `followup_sends` and `aftersales_sends`.
  - At least 90–180 s between automated messages on a number, with random variation.
  - Backlog spread across the window.
  - A pilot cap of 5–10 per number.
  - Two or three wordings per step.
  - A test that no number sends more than one automated message inside the spacing.

**F7 — Draft 1: the after-sales "welcome" duplicates welcomes that already go out.**
- **What exists.**
  - "Service Activated" on WhatsApp (`L:webhook.php:1651-1665`) and the welcome e-mail (`:1674`, `:2163-2170`).
  - `event_client_add` (`:929`) and `ops_kyc_customer_welcome` (`L:lib/NotificationRetry.php:30`).
  - The installation-booked message.
- **Effect.** An installation job closing usually coincides with activation, so a new customer gets two or three welcomes in one day.
- **Fix.** Skip the welcome step when any welcome or activation event reached the phone in the last 7 days (`wa_messages.event_key`). Or replace the step with the corrected activation text alone (the `aftersales_first_activation_text` switch).

**F8 — Draft 1: a chase is opened without the existing escalation-word gate.**
- **Why.** The open rules in §4.4 skip `FollowUpPolicy::gate` gate 6, which closes a row when the thread mentions refund, complaint or similar (`L:lib/FollowUpPolicy.php:333-341`). The automatic approval also skips the operator's `followup_auto_send` (`:101`).
- **Fix.**
  - Run `EmailReplyPolicy::scanForEscalation` over the thread at open and again in `preSendCheck`.
  - State plainly that `quote_chase_enabled` is the operator's approval to send without reading, replacing `followup_auto_send` for templated quote rows.

**F9 — Draft 2: at the hand-over rung the AI still answers a photo's caption.**
- **Why.** 9a is moved to the reply rung. At the hand-over rung every photo goes to a person, yet the caption is still queued for the AI as text (`D:evo_webhook.php:444-452`; the document precedent at `:454-464`).
- **Effect.** A payment screenshot captioned "paid, please confirm" gets an AI answer next to the hand-over. §2.4's "the AI says nothing" does not hold.
- **Fix.** For images, 9a applies at the hand-over rung and above. The dry run still leaves the caption to the text path.

**F10 — Draft 2: the description of a payment or identity photo is written into the message body.**
- **Why.** `D:lib/ImageUnderstanding.php:175-191` writes the full description into `wa_messages.body` for payment evidence too. The body is what the assistant's history reads (`L:lib/ConversationService.php:635-683`). Draft 2 widens this, since every photo's description goes into the body at the hand-over rung.
- **Effect.**
  - On the customer's next message the AI reads the amounts on the receipt.
  - A `document_photo` puts identity-document text into the Inbox and the AI's history.
  - This contradicts Draft 2's own §2.10 ("nothing for identity documents") and the document ladder, which keeps the body untouched at the dry-run and hand-over rungs (`D:lib/MediaPolicy.php:116-129`).
- **Fix.**
  - For payment, `document_photo` and anything rated human-only: the body gets a fixed evidence label only. The description stays in `wa_media.understanding` for staff.
  - At the hand-over rung, leave the body untouched for every kind.

**F11 — Draft 2, D1 scope: the retailer app and mobile API stay on the plugin's old rows.**
- **Why.** C-8 leaves those clients on the stale plugin rows. If Uganda's agents register through them, D1 ("remove all the plugin's plans and hardware") is not delivered.
- **Fix.** Before choosing scope, establish which client the agents use. If it is the app, the web retailer app's switched step joins this release.

**F12 — the drafts conflict on QuotationService.**
- **Why.** Draft 2's standard quotation, the new Send Quotation pickers and `buildLeadItems` change the very sends that Draft 1's tracker reads. Draft 1 depends on:
  - the event keys `quote_lead` and `quote_manual`;
  - the `📋 *Ref:*` marker (`L:lib/QuotationService.php:489`);
  - `quotes_log.sent_by` (owner rule 2).
- **Effect.** If any of these drifts, the one-click quotes the owner most wants chased would never be tracked.
- **Fix.** A shared test: every quote-sending path, the standard quotation included, produces exactly one tracker row with an owner.

**F13 — both drafts: migration numbers are not coordinated.**
- **Why.** Draft 1 takes 089 and 090. Draft 2's "next free number" is also 089. The runner would apply both files, since it keys on the filename, but the convention is one number per file (`L:lib/MigrationRunner.php:28`).
- **Also.** The runner swallows statement errors and always marks a file applied (`:220-247`). A missing table or trigger is silent.
- **Fix.**
  - Fix the numbers now: 085 (as on the development branch), 089 quote follow-ups, 090 after-sales, 091 media provider.
  - Each part's doctor asserts that its tables, indexes and triggers exist.

**F14 — Draft 2, D3/D4: batching and location have no switch-on check.**
- D4 requires each part to come with its own check. Batching and location have none. Draft 1's after-sales steps have no per-step doctor either.
- **Fix.**
  - Batching: count merged, held and expired events, and confirm one reply per burst.
  - Location: count area found / not found.
  - After-sales: per-step due / sent / skipped counts.

##### 2.3 Minor

**F15 — Draft 2's claim about STOP and quotes is wrong.**
- §6 says it is "not established" whether STOP blocks a quote. It is established:
  - `sendVia` checks the opt-out against the caller's class before Evolution (`L:lib/NotificationService.php:2312-2314`);
  - `sendWA` passes no class, so the class is transactional (`:2299`);
  - a keyword STOP is recorded with scope `proactive` (`L:evo_webhook.php:350`).
- So STOP does not block a quote, and an `all` opt-out does. Correct §6 and drop the redundant `SCOPE_ALL` check in §1.12.

**F16 — Draft 1, §2.3: the scan's opening rule is misstated.** "Opens only where the customer spoke last" is wrong. The scan and `isFollowable` check only the age of `last_customer_at` (`L:cron/followup_scan.php:67-77`; `L:lib/FollowUpPolicy.php:391-418`). §4.1 also says "eleven re-checks" where nine are listed.

**F17 — Draft 1, §3.5: the report's includes and table reads are understated.**
- The report says it loads two includes, but it also uses `PluginConfig::read` and `TenantProfile::current`.
- `kyc_applications`, `leads`, `retailers` and `quotes_log` are JSON `data` tables (`L:lib/SqliteStore.php:388-397`). Every read needs `json_extract`, and that should be written down.

**F18 — Draft 2, C-3: the list of fields that read the plugin ids is incomplete.**
- **Missed readers of `package_choice` and `device_id`:**
  - the uCRM attributes `ATTR_PACKAGE` and `ATTR_DEVICE_ID` (`L:lib/KycService.php:1543-1544`; `L:lib/KycCrmSync.php:537-538`);
  - `L:lib/LifecycleService.php:258`;
  - `L:includes/api/api_cron_debug.php:1103-1168`;
  - `L:includes/api/api_products_admin.php:73`.
- **Also.** C-6 and C-7 are referenced but never defined.

**F19 — Draft 2: the media job's dispatch is not Uganda-gated.** The development branch dispatches the media job on its `flag` alone (`D:cron/master.php:199,330-336`). South Sudan gets a schedule row if the flag is ever set. Add a tenant gate.

**F20 — Draft 2: the hold can end before a voice note is transcribed.** `max_hold` 25 s is shorter than a voice fetch (20 s) plus the provider's answer (30 s). The merge rule ("after the last outbound") can then drop the voice event's own message. Always include the event's own message, and set the hold for unsettled media from MediaPolicy's timeouts.

**F21 — Draft 2: every voice note at the hand-over rung pages the alert number.** Each one sets `needs_human` and alerts the alert number (`D:lib/Handover.php:28-46`). It also closes Draft 1's chases as "taken" and blocks enquiry automatic sends (`L:lib/FollowUpPolicy.php:133-136`). State the expected alert volume.

**F22 — Draft 1: after-sales checks the policy on one number and may send from another.** It checks `AutomationPolicy('support')`, but `sendVia` may send on `account` (`L:lib/NotificationService.php:1892-1899`) through an instance that ignores the registry (`:1919`). Check the policy on the channel `evoChannelFor` returns.

**F23 — Draft 1: a GET unsubscribe link conflicts with the ACTION_LINKS rule.** A one-click GET unsubscribe that writes a row goes against "no GET link that changes state" (`L:lib/NotifyGate.php:24`). Use `List-Unsubscribe-Post` (RFC 8058) and a confirm page that POSTs.

**F24 — Draft 1, §5.3: bill reminders and win-back still send on Sundays.** `JobWindow` has no weekday (`L:lib/JobWindow.php:11-17`; `L:cron/master.php:295`). §D item 7 names bill reminders. Put this to the operator; do not leave it silent.

**F25 — the two drafts gate differently.**
- Draft 1 uses NotifyGate names; Draft 2 uses StaffJobsGate and QuoteOrganization.
- QuoteOrganization can be explicitly off on Uganda (`L:lib/QuoteOrganization.php:91-95`); the other two cannot.
- State one gate per part.
- C-5's `public_price_exclude` matches by **name** (`L:lib/PublicPriceFeed.php:24-25`). That is acceptable only because it can only hide an item.

**F26 — Draft 2, location: the decision differs from the brief's wording.** §D item 5 says the area is "said as a colleague's to confirm". G-1 never puts the area into the AI's context. The choice is safe, but it departs from the brief and belongs to the operator (§E).

**F27 — Draft 1: a STOP reply must count as opted out, not answered.** The tracker must classify a STOP-only reply as `opted_out` before `answered`.

**F28 — Draft 2, unwrap: one live bug stays hidden behind the media switch.** Gating the wrapped-message unwrap on media keeps today's dropped `ephemeral` and view-once texts on Uganda. Consider its own Uganda switch.

#### 3. Where the drafts collide

| File | Draft 1 | Draft 2 | Resolution |
|---|---|---|---|
| `cron/master.php` | adds `quote_chase`, `quote_daily_list`, `aftersales` (gate) | adds `ai_media` (flag) | one commit for the job list; F19 |
| `tools/set_config.php`, `tools/cron_status.php` | quote and after-sales keys | catalogue, media and batching keys | one merged key table |
| `migrations/` | 089, 090 | 085, 0NN | F13 |
| `lib/QuotationService.php` | depends on markers, events and `quotes_log` | changes pickers and adds the standard quotation | F12 |
| `webhook.php` | after-sales hooks in `service.add` and `service.activate` | — | Draft 1 alone |
| `workers/AiReplyWorker.php`, `WorkerBase.php`, `evo_webhook.php` | not touched | media port, Handover move, batching | land the media port first, then batching (F2) |
| conversation `state` | `needs_human` closes chases | the hand-over rung sets `needs_human` on every voice note | F21 |
| alert number | daily list (`sales`) | hand-over alerts | both counted in the doctor |

#### 4. Missing against D1–D4

- **D1:** the app clients (F11); the full list of plugin-id readers (F18).
- **D2:** the description written to the body (F10); the hand-over caption (F9); publication of the Privacy Policy line before any provider is set (Draft 2 has this).
- **D3:**
  - owner attribution (F4);
  - the `accounts` thread and a support number with its assistant off (F5);
  - the escalation gate (F8);
  - the kill switch (F1);
  - send pacing and one daily limit per number (F6).
- **D4:** checks for batching, location and each after-sales step (F14).

#### 5. Size, as an estimate (code / tests)

| Part | Size | Rough effort |
|---|---|---|
| Report and wrapper | ~1.0k / ~0.9k lines | 3–4 days |
| Catalogue direct, standard quotation, public feed | ~2.0k / ~2.0k | 10–15 days |
| Media port and OpenAI adapters (~20 files exist; nine live files merged) | ~1.5k new and merge / ~2.0k ported and adapted | 10–15 days |
| Batching | ~0.3k / ~0.6k | 3 days |
| Location (the dataset's source and licence dominate) | ~0.3k + data | 3–5 days |
| Quote tracking, list, chase, e-mail, engine fixes | ~2.5k / ~2.5k | 10–12 days |
| After-sales v1 | ~1.2k / ~1.0k | 5–7 days |

Total: about 45–60 working days, one coherent release.

#### 6. Recommended build order

0. **Skeleton (all OFF):**
   - the migration numbers (F13);
   - the gate names;
   - `set_config` keys and the `master.php` entries;
   - a pure `QuoteSendEvidence` class;
   - a shared daily-limit and spacing primitive per number (F6).
1. **The report.** It is read-only and can be used before the release. It measures the baseline, including chasable / refused-by-channel (F5).
2. **Catalogue direct (D1),** with the standard quotation emitting tracker evidence (F12).
3. **Engine hardening:** the unconditional kind filter (F1), the claim and settle ledger (F3), escalation at open (F8). Then Q1 tracking, Q2 the daily list, Q3 chases, Q4 e-mail last.
4. **Media:** the gate, the foundation and the OpenAI adapters at the dry run, with the Privacy Policy line published first. Then the hand-over and reply rungs (F9, F10). Document OCR last.
5. **Batching,** after media and after F2.
6. **Location,** once the dataset is decided.
7. **After-sales,** after the wording, the review URL, the welcome dedupe (F7) and the outage policy.

#### 7. Could not establish (read-only, no server)

- **Server settings:**
  - `followup_enabled`, `followup_auto_send`, `multi_number_channels_enabled`;
  - the support and account numbers' `ai_enabled`;
  - `wa_force_accounts`, `wa_followups_on_owned_numbers`, `customer_emails_enabled`.
- **Agents' client:** whether Uganda's agents register through the web KYC form, the retailer app or the mobile app.
- **Volumes:** daily quote, activation and voice-note volumes per number, so F6's budget is a guess.
- **uCRM 4.5.33 fields:** quote status values, the issue-date field, the payments filter, and whether products carry `isActive`, `archived`, `organizationId` or a type field.
- **OpenAI terms:** retention and pricing, and whether PDF input works on the operator's account.
- **Whisper on Luganda.**
- **Location data:** the source, licence and size of a Uganda district and town dataset.
- **Two line ranges were not re-read:** the 3,005–3,100 region of `webhook.php` and the after-sales job-title rule in `InstallScheduledWhatsApp`.

## G. 5.18.103 — the catalogue straight from uCRM: the design to build (1 Nov 2026)

Status: **the design to build; nothing built, nothing deployed.** It replaces §F.2's §1 and folds in §F.3's findings
F11, F12, F13 and F18, under D6 and D7. Base: `origin/release/5.18.102` = `1a0ea5f`. Citations `L:path:line` are that
commit's `dishnet-hybrid-sudan/path:line`. Two read-only agents mapped the code on 1 Nov; two more reviewed the first
draft adversarially (36 findings, none a blocker, 19 major) and every finding is folded in below — §G.15 lists what
each changed.

### G.1 What changes, in the owner's words and in the agents'

*"remove all the hardware and internet plans for plugin … use what is in the ucrm make it simple"*, *"show all the
things of hardware … same like website and give standard one click predefined quote"*.

- **For an agent on the KYC form:** the plans and hardware listed are uCRM's own service plans and products, at
  uCRM's prices, the hardware grouped as the website groups it (kits, accessories). Above them, one button per
  **standard quotation** an admin defined (for example "Starlink Mini + Residential + installation"): one click fills
  the plan, the hardware and "DishNet installs"; the agent still checks and submits. A Cash sale collects exactly the
  total shown, as in 5.18.102. If uCRM cannot be read, Credit registrations still go in, as today.
- **For staff on Send Quotation:** the plan list and a new hardware list come from uCRM too, each picked line carrying
  its uCRM product at uCRM's price, and the same standard-quotation buttons fill the lines; Send works as today.
- **For an admin:** on Uganda, the Plans and Hardware screens give way to one **uCRM catalogue** screen: what the
  forms offer, what they do not and why (inactive, archived, priced 0, spelled like a plan…), and four settings — the
  switch, the installation product, products kept off the forms, and the standard quotations. Nothing is linked any
  more. Prices are changed in uCRM, as today.
- **Nothing changes for agents until an admin turns it on** on that screen, after reading the list (§G.3). Until then
  5.18.103 behaves exactly as 5.18.102 (D6's state: no plugin quotation).

Out of 5.18.103, recorded: the website's price feed (§F.2 §1.11 — the site already reads uCRM, `L:prices.php:52-65`);
the retailer app and the mobile API stay as in 5.18.102 (§G.11); the AI's senses, the quote follow-up, the daily list,
after-sales (D7: the release after). No migration, so F13 does not arise here (§G.12).

### G.2 Decisions

| # | Decision | Reason |
|---|---|---|
| G-1 | **Direct mode is a switch, OFF until an admin turns it on** on the uCRM catalogue screen. Turning it on reads uCRM fresh and needs at least one plan offered and the installation product set **and passing** `badProduct` and rule 7 on that read. Turning it off needs no uCRM read. Off = 5.18.102 exactly | The admin reads what the forms will offer before agents see it (D4's "each with its own check", applied here); a deploy never changes what agents see; turning it off is the kill switch — back to D6's state, never to a wrong price — and it works during the very outage it may be needed for |
| G-2 | **The switch and the settings live in one new store table, `ucrm_catalogue`**, written under `withLock` with provenance; never a config key | Web pages read config from the store row and the crons from the files (`L:public.php:512`, `L:lib/PluginConfig.php:53-90`); a web-written key would be invisible to the cron that retries quotations. `tools/set_config.php` is pinned by `test_quote_company` I3 and is not touched; `QuoteOrganization.php` stays byte for byte (`test_quote_from_ucrm` I's kept list) |
| G-3 | **The direct rules live in `UcrmQuoteLines`** (new public methods), reusing its private rule functions — `planRules`, `servicePlanRules`, `badProduct`, `likePlan`, `monthPrice`, `exclusion` — with direct-mode wording for a row uCRM no longer lists (`unlisted()`, `L:lib/UcrmQuoteLines.php:727-739`, says "an admin links it again", which is impossible with the switch on) | One class prices the form, the cash and every quote (docs/71 §D); reuse means direct mode cannot judge a uCRM row by different rules. The link-mode texts, which 5.18.102's tests pin, are untouched |
| G-4 | **A plan is a uCRM pair**: a service plan that passes rule 6 (`servicePlanRules`: counted, fewer than 200 rows, key unique, one enabled 1-month price, `planPrice` equal to it) **and, among the counted products, exactly one keyed like it — counted before any judging** — which must then pass `planRules` (`badProduct`, price equal to the 1-month price). The quote line is that product, unit `month` | The WhatsApp quote summary (`L:webhook.php:339-358`, kept) shows a line as monthly only when its label keys to a service plan, and `billing/quotes` takes products. Counting before judging is docs/71 §D.3's rule: two products spelled like one plan are ambiguous even when one of them fails, and the plan is then not offered ("two uCRM products are spelled like this service plan") |
| G-5 | **Hardware is every counted product the website would show** (`L:lib/PublicPriceFeed.php:59-77`) that passes `badProduct`, is not keyed like any service plan (rule 7), is not the installation product and is not kept off by an admin; grouped kit / accessory by `ShopCatalogue::accessoryNameKeys`, the website's own split, for display only. **`public_price_exclude` is not read**: the website reads it from the config files and the form from the store row (`L:prices.php:24-29` against `L:public.php:512`), so it would mean different things in different places; "Keep off the forms" is the one control | *"same like website"*, with 5.18.102's rules, so nothing the website lists that uCRM would quote wrongly (taxable, priced 0, more than 2 decimals) reaches a quote. The deploy check counts how many offered items the website's list leaves out by name, as a hint for the admin (§G.13) |
| G-6 | **The installation is one setting, a uCRM product id**, picked on the screen from the products that pass `badProduct` and rule 7. It is never offered as hardware, and a cart naming it is refused | No name is matched (docs/71 D14). Rule 8 ("installation ≠ an offered kit") becomes a plain id comparison |
| G-7 | **A direct sale never stores a uCRM id in `package_choice`, `device_id`, `hw_cart_json` or `quote_sale_cart`.** The direct form draws no input of those names at all; the server, at the top of `handleNew`'s direct branch — before the duplicate check and `buildAttributes` — sets `package_choice` and `device_id` to `''` and replaces `hw_cart_json` with its own (§G.7) | 5.18.102 and the gate-off paths read those fields as plugin row ids or as priced cart lines (`L:lib/UcrmQuoteLines.php:511-523`, `L:lib/KycCrmSync.php:400-421`, `L:includes/api/api_cron_debug.php:1101-1112`, `kyc_quote_debug`). The browser is never trusted to blank them (three are posted twice today, `L:tabs/sales/kyc_form.php:842-844`, `:1637`, `:1667-1668`) |
| G-8 | **A direct sale is stored with `quote_sale_entry = 'web-direct'`**, which 5.18.102 reads as `app` (`L:lib/KycService.php:1698-1700`) | After a rollback to 5.18.102 its retry refuses at the entry rule — no quotation, P1 or P2 — and Make the quotation is not drawn (`canMakeQuotation` wants `'web'`, `:1945-1952`) |
| G-9 | **The posted form says which world it came from**: marker `5.18.103-direct`. Switch on: that marker is `web-direct` and goes to `resolveDirect`; every other post (a form opened before the switch, the retailer app, the mobile API) goes to 5.18.102's own `resolve()` as `app` — unpriced, Credit P1, Cash refused, plugin ids as posted. Switch off: a direct-marker post is **refused before anything is written** ("this form was opened under the uCRM catalogue, which an admin has since turned off — reload the form; nothing was saved"); everything else is 5.18.102's | A form's ids are only meaningful in the world that drew them; an old form must still register a Credit sale (5.18.102 does), and a direct form must never reach code that reads its ids as plugin ids |
| G-10 | **Stored applications keep the branch they were saved under, whatever the switch says**: `web-direct` records are retried and quoted by the direct rules; `web` records by their links. But with the switch **on**, a `web` record is not offered Make the quotation and the Orders card says to make its quotation in uCRM by hand | The ids in a record do not change meaning when the switch moves. Under D6 every `web` record since the 5.18.102 copy is unpriced because nothing is linked, its stored text says "do not make it by hand — once the item is linked…" (`L:lib/UcrmQuoteLines.php:94`), and with the switch on nothing can be linked: the card must give the remedy that works |
| G-11 | **The link store is frozen, never deleted**: with the switch on, link saves and clears are refused, and deleting a plan or kit row no longer ends its links | It prices 5.18.102's applications if the switch is turned off, and keeps a rollback working |
| G-12 | **A standard quotation stores ids only, never prices**, and is priced live each time it is used; if any item no longer resolves its button is disabled with the reason — never a partial bundle | A stored price would be a second price list |
| G-13 | **No new permission, no migration.** The settings and the standard quotations: admins. Using a standard quotation: whoever may already use the KYC form or Send Quotation (the owner's question in §E — this is the build's default, §G.14) | A new RBAC permission needs a migration (`L:lib/RbacService.php:35-41`); the release norm is "no migration" |
| G-14 | **Every Send Quotation send, the standard one included, goes through the existing `sendLeadQuote` / `sendManualQuote`**: type `lead` / `manual`, event `quote_lead` / `quote_manual`, the Ref marker and the `quotes_log` row unchanged | F12: the next release's quote tracker reads exactly those; a new type would be uncounted on Quote History and never retried (`L:lib/NotificationRetry.php:32`) |
| G-15 | **Picked lines are re-priced inside `QuotationService`**, which the web page and the mobile API share; in direct mode a line that carries a `productId` but was not picked loses the `productId` | Re-pricing in the web handlers alone would leave the mobile API (`L:api/index.php:1702-1707`, `:1738-1741`, kept byte for byte) able to send a uCRM product at a posted price — a hole 5.18.102 already has with the gate on, closed here in direct mode |

### G.3 The switch, the settings, and the uCRM catalogue screen

**The store table `ucrm_catalogue`** (the plugin store; `SqliteStore::load()` creates a table it does not find, so it is
read only where the quoting organization's gate is on — `QuoteOrganization::setting()['on']` **with** an integer `id`,
since `on` can come with `id` null, `L:lib/QuoteOrganization.php:80-114`). Records, each with an int `id`, `kind`,
provenance (`by_id`, `by_name`, `at`, `at_ts`) and `ended_at_ts` (null = in force; a change ends the record in force and
appends a new one, so the history stays):

| kind | holds | in force at once |
|---|---|---|
| `mode` | `on: true/false` | one; none = off |
| `install` | `product_id` | one; none = not set |
| `hidden` | `product_id` | one per product |
| `standard` | `title` (plain, ≤ 60), `plan {sp, p}`, `hw [{p, q}]` (q 1–99, ≤ 10 items), `install: bool` | at most 10 |

**`lib/UcrmCatalogue.php`** owns it: `fromStore($store, $setting)` (opens nothing when the gate is off or the id is not
an int), `direct()`, `installProductId()`, `hiddenIds()`, `standards()`, `history()`, and the writes `setMode`,
`setInstall`, `setHidden`, `saveStandard`, `endStandard` — each under `withLock`, each re-reading the gate inside the
lock, each refusing a value it cannot validate, each returning a fixed result kind. No uCRM request is made inside a
lock: the caller reads and judges first and passes the judged ids in, as `UcrmQuoteLinks::set()` does.

**The screen** `tabs/sales/ucrm_catalogue.php`, tab `ucrm_catalogue`, under the `plans` or `hardware` permission
(admins by default, `L:public.php:2343-2344`; `$_tabPerms` takes a list, `:2971-2985`):

1. **The switch:** on or off, who set it and when. "Turn on" shows its three conditions, each met or not, and is
   pressable only when all are met; "Turn off" always. With the switch off, the screen also lists, by application id,
   the `web` applications saved since the 5.18.102 copy that are waiting with P1 — the ones whose quotation must be
   made in uCRM by hand once the switch is on (G-10).
2. **Plans offered:** name, speed (`downloadSpeed`, as the website shows it, `L:lib/PublicPriceFeed.php:36`), monthly
   price. **Not offered**, each with its reason.
3. **Hardware offered**, kits then accessories, with price and "Keep off the forms" per product. **Kept off**, with
   "Offer again". **Not offered**, each with its reason.
4. **The installation product**: the one in force, or "not set", and a picker of the products that may be it.
5. **Standard quotations**: each with its items and live total, or the reason it cannot be used; "Edit", "Stop
   using"; "Add a standard quotation" (title, a plan, hardware with quantities, "includes installation").

Rows uCRM filters out (inactive, archived, another organization's) are named, not only numbered: `catalogue()` keeps
their names beside their ids (`excluded_names`) and **`CACHE_SHAPE` moves to `d103-1`**, so 5.18.102 reads a 5.18.103
page cache as a miss after a rollback, and 5.18.103 reads a 5.18.102 one as a miss (`L:lib/UcrmQuoteLines.php:71`,
`:157-166`). Every uCRM string passes through `h()`, every reason through `QuoteOrganization::plain`. The page reads
with `quoteClient()` and the page timeouts through `forPage()`; every save that judges a uCRM item reads fresh.

**The POST handlers** (`includes/post/post_sync.php`: `ucrm_cat_mode`, `ucrm_cat_install`, `ucrm_cat_hidden`,
`ucrm_cat_standard_save`, `ucrm_cat_standard_end`) copy 5.18.102's guard order exactly
(`L:includes/post/post_sync.php:716-741`): the gate, `requireAdmin`, the fresh re-read of the account (`retailers.json`,
active and admin, else 403), `csrfCheck() && !CustomerSession::crossSite() && BankGate::fromOwnPage('POST')`, then the
integer id — never `$can()` or `$isAdmin`, which do not exist yet when POST handlers run (`L:public.php:978` against
`:2202`, `:2363`).

**Nav and tabs on Uganda** (`L:includes/navigation.php:503-512`, `L:public.php:2693-2694`, `:2822-2823`): with the gate
on, a "uCRM catalogue" entry for those who may see Plans or Hardware. With the switch **on**, the Plans and Hardware
entries are not drawn, and `?tab=subscription_plans` / `?tab=hardware` (also linked from `includes/onboarding.php:173-174`)
show one card — *"On Uganda, plans and hardware come straight from uCRM — see the uCRM catalogue"* — and stop before
5.18.102's body, which then opens nothing. With the switch off, both screens are 5.18.102's, byte for byte. Known
consequence: the Hardware screen's per-equipment photos and spec sheets for the assistant (`L:tabs/sales/hardware.php:
461-499`) are then reached only through WA AI setup, which offers upload and removal already
(`L:tabs/engage/wa_ai_setup.php:69-82`). South Sudan: no entry, no change.

### G.4 What is offered — `UcrmQuoteLines::offered(?int $installId, array $keptOff)`

One catalogue read (`catalogue()`: `GET products?limit=500`, `GET service-plans?limit=500`, fail closed,
`L:lib/UcrmQuoteLines.php:206-251`). Returns `{ok, reason, plans, hardware, install, not_offered}`:

- **plans** — G-4. Each `{sp, p, label (the product's name), price, speed, key, token}`.
- **hardware** — G-5. Each `{p, label, price, unit, group, token}`.
- **install** — the product `$installId` judged by `badProduct` and rule 7: `{ok, p, label, price, token}` or
  `{ok: false, reason}` ("the installation product is not set — an admin sets it on the uCRM catalogue screen", or the
  rule's reason).
- **not_offered** — every service plan and product left out, with its reason and a fixed `why` code
  (`plan_no_product`, `plan_two_products`, `kept_off`, `install_product`, and the codes the reused rules already give),
  for the screen and the deploy check.

**The token** of an item is computed in PHP only — the first 16 hex characters of
`sha256(productId . '|' . label . '|' . cents . '|' . unit)`, `cents` = `(string)(int)round(price * 100)` — and drawn
into the page beside the item. The form never computes one.

### G.5 The KYC form (direct mode)

`tabs/sales/kyc_form.php` builds its price-cell map from `offered()` when direct mode is on
(`L:tabs/sales/kyc_form.php:11-64`); with it off, the 5.18.102 block runs unchanged, and South Sudan (`$_q102 === null`)
stays byte for byte.

- **When uCRM cannot be read** at page draw, the form draws the **last good offered list** (a copy kept beside the page
  cache with no expiry, written whenever a read succeeds), marked *"uCRM could not be read — the list may be out of
  date; Cash is unavailable; Credit registrations still go in"*, with Cash disabled — as 5.18.102 says today
  (`L:tabs/sales/kyc_form.php:852-857`). With no last good list (never read), it says so and draws nothing to choose
  from. The submission reads uCRM again: if it is still unreadable, a Credit sale is registered unpriced with its ids
  kept for the retry (§G.7); Cash is refused (CASH_UNPRICED).
- **Rows.** The plan list: one radio per offered plan, value `"<sp>:<p>"`, name **`ucrm_plan`**. The hardware list: one
  row per offered product, `data-id` the product id, kits before accessories. Items not offered are not drawn; the form
  says how many, and that the uCRM catalogue screen lists them. **Types:** only Starlink is offered. The Fiber card is
  not drawn in direct mode — 5.18.102's deploy log of 1 Nov (QL-G1) counted **0 Fiber plan rows** on Uganda, so no
  Fiber sale could be submitted there today either. LTE keeps its own flow (`lte_create_subscriber`,
  `L:tabs/sales/kyc_form.php:477-500`), untouched.
- **No `package_choice`, `device_id` or `hw_cart_json` input is drawn** in direct mode (G-7). `syncOuterFields`,
  `renderCart` and `kycFormSubmit` (`L:tabs/sales/kyc_form.php:2254-2295`, `:1892-1971`) work on `ucrm_plan` and the
  direct cart; the submit check is "a plan is selected".
- **Quantities** are capped at 99 per item in `hwAdd` and the quantity box (`:1867-1879`), the server's limit.
- **Standard quotations.** One button per usable standard quotation above the plan list. Pressing it selects the plan,
  sets the cart to its hardware and quantities, and answers "Who installs?" with DishNet when it includes the
  installation (otherwise leaves the question open). It never submits. The keyword defaults (`applyDefaults`,
  `:1707-1726`, `:1764-1837`) do nothing in direct mode: nothing is preselected unless a standard quotation is pressed.
- **The total** stays `q102Quote`'s single all-or-nothing total in integer cents, keyed by the new ids; the installation
  cell is the installation product's.
- **Posted fields:** `ucrm_plan`; `ucrm_cart_json` = `[{"p":id,"q":n}]`; `install_by`; `ucrm_total_seen`;
  `ucrm_items_seen` (the drawn tokens of the selected items, sorted, comma-joined); `ucrm_quote_form = 5.18.103-direct`.

### G.6 Pricing a direct sale — `resolveDirect()`

`KycService::saleFromPost` adds `direct_plan` (`[sp, p]` from `ucrm_plan`: two positive ints, else null) and
`direct_cart` (from `ucrm_cart_json`: at most 30 entries, each two positive ints, q 1–99, duplicates merged) and sets
`entry` per G-9.

**Before resolving** (handleNew, direct branch): no plan pair → *"Please select a service plan."*; a cart that cannot
be read, or breaks a limit → *"The sale's hardware could not be read — reload the form. Nothing was saved."* (both
refusals, Cash or Credit; nothing written).

`UcrmQuoteLines::resolveDirect($sale, $installId, $keptOff)` answers the shape `resolve()` answers
(`L:lib/UcrmQuoteLines.php:511-592`) — `status`, `reason`, `lines`, `total`, `plan_price`, `device_price`,
`plan_label`, `entry`, `link_ids` (always `[]`) — with `offer` = `['name' => plan label]` and `device` =
`['title' => first hardware label, 'price' => its price]` (so the existing client note at `L:lib/KycService.php:789`
reads uCRM's names), plus `item_ids {plan:{sp,p}, hw:[{p,q}], install:p|null}`, `tokens`, `why`:

1. Fiber → `FIBER_REASON` (a crafted post only);
2. the catalogue unreadable → its reason, `why` `unreadable`;
3. the plan pair must be offered now (G-4) → else its reason;
4. each hardware id must be offered now (G-5) — so not kept off, not the installation → else its reason;
5. "Who installs?" as today (`needsInstallAnswer`); DishNet → the installation must be set and pass → else its reason.

`handleNew` calls it at the same place, once, before any write (`L:lib/KycService.php:549-564`); its
`package_choice > 0` check (`:735-738`) applies to every entry but `web-direct`. `cashRefusal` keeps every step but the
last; for `web-direct` the last is **`seenItemsMatch(ucrm_items_seen, tokens)`** — the two token sets equal, as sets over
unique items — refused with a new text, **`CASH_ITEM_CHANGED`**: *"An item on this sale changed in uCRM since the form
was opened — reload the form. Nothing was saved and no money was taken."*, which also drops the page cache.
(`seenLinksMatch` would refuse every direct Cash sale: it needs a non-empty link set, `L:lib/UcrmQuoteLines.php:
695-702`.) The token also closes 5.18.102's recorded limit that a rename at the same id and price went unnoticed.

`handleExisting` is unchanged: only the two APIs reach it. (The web form draws an always-empty `customer_id`,
`L:tabs/sales/kyc_form.php:1144`; `kycUseExisting`, which would fill it, `:214-216`, has no caller.)

### G.7 What a direct application stores

`linesRecord` for `web-direct`:

- `quote_sale_entry = 'web-direct'`, `install_by`, and **`quote_item_ids` always**, priced or not;
- priced: `quote_items`, `quote_lines_source = 'ucrm-direct-5.18.103'`, `quote_items_total`, `quote_lines_read_at`,
  `offer_price`, `device_price` — never `quote_link_ids`;
- unpriced: `quote_items null`, `offer_price null`, `device_price null`, **`quote_unpriced_why`** (the `why` code) —
  never `quote_sale_cart`;
- set by the server over anything posted: `package_choice ''`, `device_id ''`, `offer_name` (the plan's uCRM label
  when listed), `device_title` (the first hardware label), `kitQty` (its quantity), and **`hw_cart_json` rebuilt** as
  `[{"id":0,"title":<uCRM label>,"price":<uCRM price>,"qty":q}]` — no `ucrm_product_id`, and `id 0` matches no plugin
  row anywhere.

The rebuilt cart keeps what its readers read — titles and quantities for the installation prefill and the stock
deduction at job completion, titles and prices for the export (`L:includes/api/api_install_auth.php:96-108`,
`L:includes/api/api_scheduling.php:700-741`, `L:includes/post/post_leads.php:795-810`).

**Stock deduction changes, and is counted:** it matches each cart title to a stock category by exact title
(`L:lib/StockService.php:1442-1447`), and categories were seeded from the plugin's kit titles
(`L:includes/api/api_stock.php:33-36`). Under D6 almost nothing is linked, so today's records carry plugin titles; a
direct record carries uCRM's names, and where no category is titled so, a completed job deducts nothing ("No stock
category found"). The deploy check counts the offered hardware with no stock category of the same title (§G.13); the
handover tells the owner to title those categories as in uCRM.

### G.8 Retries and Make the quotation

`saleFromApp` reads `quote_sale_entry` per G-8/G-10 and, for `web-direct`, the sale from `quote_item_ids`.
`retryLines` gains one branch, chosen by the record, never by the switch (G-10):

- `quote_lines_source = 'ucrm-direct-5.18.103'` with lines → **`confirmDirect($lines, $item_ids, $installId)`**: each id
  judged again by the rules (the plan pair as G-4, each product as G-5 but **without** "kept off", the installation as
  stored), every stored line equal to its item's line now in product id and price at 2 dp. A changed price is said in
  words, never with the figure, as `confirmLines` does (`L:lib/UcrmQuoteLines.php:613-666`). A sale already priced at
  submission is not refused later because an admin has since kept an item off.
- `web-direct` without lines → `resolveDirect` over the stored ids, **with** "kept off": a sale unpriced because an item
  was kept off stays unpriced on every automatic retry.
- the Cash rule (P2) and P3 unchanged.
- An unpriced Credit `web-direct` sale gets **P1_DIRECT** — at submission (`p1Text` extended to `web-direct`) and at
  retry alike: *"No quotation was made: {REASON}. Make the quotation in uCRM by hand, or — once the reason is put right
  — an admin makes it from the Orders card."* It stays true after a rollback, where the button is gone. A sale without
  an answer to "Who installs?" keeps P1.

`canMakeQuotation($app, bool $directOn)` — one implementation for the card and the handler
(`L:lib/KycService.php:1945-1952`, `L:tabs/sales/applications.php:396`, `:407`, `L:includes/post/post_sync.php:1023`):
`web-direct` always qualifies; `web` qualifies only with the switch off. With the switch on, the card shows a `web` P1
record's remedy as *"make the quotation in uCRM by hand"*, never P1_UNLINKED's. `makeQuotation` (`:2007-2104`),
`KycCrmSync::ugQuote` (`L:lib/KycCrmSync.php:349-392`) and `retry_quote` reach the same `retryLines`; none of them writes
`quote_link_ids` for a direct record.

**With the quoting gate off** (Uganda's `ucrm_quote_organization_id` set to off — a supported state), the gate-off
builders would quote a `web-direct` record from `offer_name` at `offer_price ?? 0` and its cart's prices
(`L:lib/KycCrmSync.php:313-317`, `:400-421`; `L:includes/api/api_cron_debug.php:1101-1112`): a free plan line for an
unpriced one. So both refuse a `web-direct` record outright (`KycCrmSync` records P1 with a fixed text, *"a sale from
the uCRM catalogue is not quoted while the quoting organization is off — make the quotation in uCRM"*; `retry_quote`
answers the same). Neither line can be reached on South Sudan, which has no such record.

### G.9 Send Quotation and lead quotes

In direct mode (`tabs/sales/send_quote.php`, `lib/QuotationService.php`, `includes/post/post_leads.php`); with the
switch off, byte for byte 5.18.102:

- the plan picker lists the offered plans (value `sp:p`); a new **hardware picker** lists the offered hardware and the
  installation; a picked line carries `{kind, sp?, p, q}` plus the label and price it was drawn with, shown read-only;
- lines typed by hand stay allowed, marked "typed — not from uCRM" **on the page only** (never in the label sent), under
  the price-0 rule (`L:lib/QuotationService.php:975-986`);
- the standard-quotation buttons fill the lines (G-12);
- the handlers `send_lead_quote` and `send_manual_quote` (`L:includes/post/post_leads.php:132-169`, `:205-239`) split
  picked lines from typed ones **before** their price filter (`:144-155`, `:208-214`), so a picked line is never dropped
  for its posted price; picked quantities 1–99;
- **`sendLeadQuote` / `sendManualQuote` re-resolve every picked line** from a fresh catalogue read through the direct
  rules (G-15): a picked id that is not offered refuses the whole send with its reason; a picked line whose fresh price
  differs from the price it was drawn with refuses with *"a price changed in uCRM since the page was opened — reload"*;
  both before anything is created or sent. A line carrying a `productId` that was not picked loses the `productId`;
- `buildLeadItems` (`L:lib/QuotationService.php:1109-1122`) keys the lead's interest against the offered plans' keys and
  adds the one plan that matches uniquely, else nothing — the same hint 5.18.102 takes through a plugin row;
- the WhatsApp claim (`claimQuoteWa`, `:1190-1201`) is unchanged: when it cannot be written the quote still goes, as
  every quote does today. §F.2 §1.12's "fail closed" is dropped from this release: it would make the standard quotation
  behave unlike every other send, and the claim's ledger is the next release's tracker work (F12);
- the cash proforma is unchanged; the mobile API's send reaches the same two methods, so it is covered (G-15).

### G.10 South Sudan, the switch off, the gate off, and rollback

- **South Sudan:** the gate is off; nothing new is constructed, drawn or read (the table is never opened).
- **Uganda, switch off:** 5.18.102 exactly, plus the uCRM catalogue screen in the nav for admins; a direct-marker post
  is refused (G-9).
- **Turning the switch off** with direct applications stored: they keep being retried by the direct rules (G-10).
- **Uganda, quoting gate off:** direct mode is off with it (it lives under the gate); a `web-direct` record is never
  quoted by the gate-off builders (§G.8).
- **Rollback to 5.18.102** (redeploy): the `ucrm_catalogue` table stays, read by nothing; the page cache is a miss
  (shape). Direct applications read as `app`: no quotation (P1, or P2 where cash was collected), no Make the quotation —
  their stored P1_DIRECT text already says to make it in uCRM by hand, and the rollback prints their application ids
  (§G.13). `package_choice ''` and a cart with ids 0 leave nothing for 5.18.102 to match to a plugin row. A direct form
  still open in a browser posts no `package_choice`, so 5.18.102 refuses it ("Please select a valid package/plan."),
  nothing written. The link store is as 5.18.102 left it. There is no price-0 hazard (that was 5.18.101's, §R.6) while
  5.18.102 keeps its gate on.

### G.11 The retailer app and the mobile API — unchanged; F11 measured at the deploy

They post plugin ids with no marker, so they stay `app` on Uganda as in 5.18.102: Credit with no quotation (P1), Cash
refused (`L:includes/api/api_retailer.php:7-8`, `L:api/index.php:197-219`). They list the plugin's kits whole, with the
plugin's prices — a recorded limit.

**F11 is not settled by inference.** 5.18.102's deploy log, as the operator pasted it on 1 Nov, counted 0 active
`kyc_packages` rows (QL-N7) and 11 KYC applications in 90 days (QL-N4) — but the web retailer app falls back to its own
mock packages when the list is empty and posts `package_choice` 1 (`L:retailer/index.html:55-62`, `:1280`, `:1290-1292`),
so it can register a sale. Since the 5.18.102 copy every Uganda application stores `quote_sale_entry` `web` or `app`
(`L:lib/KycService.php:1743`), so **stage A counts them** (§G.13). If agents use the app, moving it to the uCRM catalogue
is the next step after this release.

### G.12 Files, and what stays byte for byte

New: `lib/UcrmCatalogue.php`, `tabs/sales/ucrm_catalogue.php`, `tests/test_catalogue_direct.php` and its directory.
Changed: `lib/UcrmQuoteLines.php`, `lib/KycService.php`, `lib/KycCrmSync.php`, `lib/QuotationService.php`,
`tabs/sales/kyc_form.php`, `tabs/sales/send_quote.php`, `tabs/sales/subscription_plans.php`, `tabs/sales/hardware.php`,
`tabs/sales/applications.php`, `tabs/sales/product_mapping.php` and `includes/api/api_products_admin.php` (the texts
that say "linked on the Plans and Hardware screens", worded for direct mode), `includes/api/api_cron_debug.php`
(`retry_quote`, §G.8), `includes/navigation.php`, `public.php` (the tab), `includes/post/post_sync.php`,
`includes/post/post_leads.php`, `manifest.json` (5.18.103), `SAFETY.md`. **No migration** (the table is created lazily,
as `ucrm_quote_links` was). Byte for byte: `webhook.php`, `cron_quote_wa.php`, `api/index.php`, `QuoteOrganization.php`,
`PublicPriceFeed.php`, `prices.php`, `shop.php`, `tools/set_config.php`, `UcrmQuoteLinks.php`, and every South Sudan
path.

### G.13 Tests, checks and the deploy

- **`tests/test_catalogue_direct.php`**, built on `test_quote_from_ucrm`'s runner and fake uCRM
  (`tests/fixtures/fake_ucrm_kyc.php` serves configurable products and service plans and records quote items with
  `productId`): the store and the switch (its conditions; turning off with uCRM down); `offered()` over every rule and
  reason, and the pair rule's counting; the form in direct mode (headless Chromium: rows, standard buttons, caps, the
  stale list, no legacy inputs); submission, cash and every refusal; the stored record field by field (G-7); retries and
  Make the quotation across the switch (G-10) and with the gate off; Send Quotation, through the page **and through the
  mobile API's methods** (G-15), and the standard quotation (one `quotes_log` row with `sent_by` and the existing event
  per send — F12); the screens and their guards; South Sudan and the switch off byte for byte; the rollback reading —
  5.18.102's `retryLines`, `canMakeQuotation` **and `handleNew`**, run from Git, on a direct record and a direct form
  post; the release boundary from Git against `1a0ea5f`. Each guard with a weakened copy that it catches.
- **Rewritten on purpose, never weakened** — found by the review, each confirmed or corrected when the build runs the
  suite: the manifest version pins (`tests/test_quote_company.php:1980`, `test_bank_boundaries.php:234`, `:535`,
  `test_cashbook_physical_list.php:1920`, the five `test_distributor_*` files, `quote_from_ucrm/I_boundary.php:128`);
  `navigation.php` and `public.php` held byte for byte by `test_bank_boundaries.php:207-212`, `:250` and
  `test_cashbook_physical_list.php:1923-1926` (to be held instead by the new boundary, as 5.18.99 did for
  `set_config.php`); `test_quote_from_ucrm` section I re-pointed (its files measured against `f2055baf` at `1a0ea5f`,
  then held to `1a0ea5f`, as 5.18.100 did for `test_quote_company`); the weakened copies anchored on lines this release
  changes (`quote_from_ucrm/mutants.php:117`, `:124`, `:139`, `mutants_C.php:32`, `:35`, `:66`), each re-anchored and
  still caught.
- **The deploy script** (from 5.18.102's, by asserted edits). 5.18.102's QL-LK1 and QL-CA1 are **retired** (with the
  switch on, links decide nothing; under D6 every item is unlinked, so QL-LK1 would fail every run). Its own checks,
  `QC`, counts and fixed codes only in the log:
  - **stage A** (on a copy, GET only, through the pin's `offered()`): plans and hardware offered and not offered by
    `why`; the switch as stored — **said in capitals if it is already on** (a redeploy after a rollback: agents see
    direct mode at once); the installation; standard quotations and whether each resolves; the applications since the
    5.18.102 copy by `quote_sale_entry` (`web`, `app`) — F11's measure; the `web` P1 applications waiting (G-10); the
    offered hardware with no stock category of the same title; how many offered items the website's
    `public_price_exclude` names;
  - **after the copy:** the same through the installed code, equal item by item to stage A's; the new screen and its
    POST handlers without a session (sign-in page) and their guard order read as text beside the pin's; the send
    handlers' re-pricing present;
  - **`--after-only`, once the admin has turned the switch on:** the switch on; the installation set and passing; every
    standard quotation usable; and **G-7's invariant over the live records** — every `web-direct` application has
    `package_choice ''`, `device_id ''`, and only id 0 in its cart;
  - **the rollback:** the direct applications 5.18.102 will leave unquoted, counted, with their ids on the 0600 sheet to
    quote by hand (a note, never a refusal — §G.10); and 5.18.102 reaching neither `?tab=ucrm_catalogue` nor
    `lib/UcrmCatalogue.php`, as QL-RB1 did for 5.18.102's files.
- **The rehearsal** chained on 5.18.102's.

### G.14 Open — the owner's; none blocks the build

- **Who may press a standard quotation.** The build's default: whoever may already use the KYC form or Send Quotation;
  only admins define them. A narrower rule needs a permission, so a migration.
- **What the first standard quotation holds** — which kit, plan and installation. It is set on the screen; the build
  needs none.
- **Products kept off the forms.** On 1 Nov, 31 uCRM products could have been linked as hardware or the installation
  (QL-G1); the screen will list those that pass G-5. Any that is not hardware (a fee, a service) is kept off by an admin
  there.

### G.15 What the review changed

| Finding | Change |
|---|---|
| uCRM unreadable blocks every registration (A-R103-2, B-F1) | the last good list, Cash disabled, Credit unpriced with ids kept (§G.5); turning off needs no uCRM read (G-1) |
| Fiber dead card (A-R103-3, B-F16) | the Fiber card is not drawn in direct mode, on QL-G1's 0 Fiber rows (§G.5); the media controls' other home stated (§G.3) |
| 5.18.102 `web` records stranded (A-R103-4, B-F2) | G-10's switch-on rule, the card's wording, the screen's list, stage A's count |
| F11 inferred (A-R103-5) | counted at stage A from `quote_sale_entry`; F11 open until it reads (§G.11) |
| mobile API bypass (A-R103-6) | re-pricing inside `QuotationService` (G-15) |
| kept off, then quoted by the cron (A-R103-7) | "kept off" applies to every retry of an unpriced sale (§G.8) |
| `public_price_exclude` read from two sources (A-R103-8, B-F3) | not read; a hint count at stage A (G-5) |
| tests not named (A-R103-9) | listed (§G.13) |
| deploy checks missing (A-R103-10, B-F13) | QL-LK1/QL-CA1 retired; the QC list (§G.13) |
| legacy fields and the browser (A-R103-11, B-F5, B-F6) | no legacy inputs drawn, server blanking before `buildAttributes`, G-9's routing and refusal, caps (§G.5–§G.6) |
| attributes carry the post (A-R103-12) | blanking moved before `buildAttributes` (G-7) |
| "links it again" in direct reasons (A-R103-13, B-F8) | direct wording (G-3) |
| owner question decided by default; deletes; fail-closed dropped (A-R103-14) | §G.14, G-11, §G.9 |
| pair rule written twice (A-R103-15) | written once, counted before judging (G-4) |
| stock deduction by title (A-R103-16, B-F7) | stated and counted (§G.7, §G.13) |
| P1_DIRECT wording (A-R103-17, B-F9) | one text, true after a rollback, used at submission too (§G.8) |
| Send Quotation price drift and the typed mark (A-R103-18) | refused on a changed price; mark on the page only (§G.9) |
| 31 products (A-R103-19) | reworded (§G.14) |
| gate off quotes a direct record (A-R103-1, B-F10) | no `ucrm_product_id` in the cart; both gate-off builders refuse `web-direct` (§G.7, §G.8) |
| unbounded quantities (B-F4) | capped at 99 on the form and the server; a bad cart refused before anything is written (§G.6) |
| Turn on with a failing installation (B-F11) | the condition is "passing", on the fresh read (G-1) |
| filtered rows unnamed (B-F12) | `excluded_names`, `CACHE_SHAPE` `d103-1` (§G.3) |
| picked lines dropped by the price filter (B-F14) | split before the filter (§G.9) |
| token format (B-F15) | PHP only, integer cents, set comparison (§G.4, §G.6) |
| citations (B-F17) | corrected: `seenLinksMatch` `:695-702`; the Bidal job runs only in `handleExisting` (`L:lib/KycService.php:464`), so it is not on the direct path; `customer_id` is drawn empty and `kycUseExisting` has no caller; the app lists kits with plugin prices; the table is created by the first read |

### G.16 Status, 3 Nov — built and tested; NOT deployed

- **Built:** `release/5.18.103` = **`221c9dc`**, one commit on 5.18.102 (`1a0ea5f`): 59 files, 26 added, 33 changed; no
  migration. **Not pushed** — `release/5.18.103` goes to GitHub on the owner's word.
- **Re-cut once.** The first cut, `c09c03c` (kept on `wip/5.18.103-first-cut`, never pushed), failed one test of its own
  in its first suite pass, stopped at file 223 of 300: `tests/test_cash_in_hand_p3b_south_sudan.php`, 148 passed, 18
  failed. On its Uganda book every one of its 17 rendered pages differed from `f4b4c96`'s by exactly 148 bytes, the uCRM
  catalogue's menu entry. That entry is drawn wherever the quoting organization is in force for a user with the Plans or
  Hardware permission, whatever the switch. §G.13's list named the tests that hold `navigation.php`, but not this one,
  which holds whole rendered pages. **The release was right and the list was incomplete.** The test is rewritten on
  purpose, never weakened:
  - the entry is taken out of the judged tree's pages only, and counted on each page: once on each Uganda page where the
    tree has `lib/UcrmCatalogue.php`, and never otherwise or on `f4b4c96`'s;
  - two new weakened copies of `navigation.php` are each caught by exactly their books: the entry drawn on every tenant,
    and the entry not drawn;
  - 171 passed, 0 failed (was 166 assertions).

  `SAFETY.md`'s *"Off = 5.18.102 exactly"* now names the menu entry. An independent review of the re-cut confirmed that
  one finding and refuted four.
- **The deploy script:** `scripts/deploy-5.18.103.sh`, re-derived on `221c9dc` by the same asserted edits (`d28c8f3`),
  10,137 lines.
- **Where the build differs from §G.13's plan** — found by the build, or by the fact-check of the release record (2
  Nov), each now in root `docs/07`'s 5.18.103 entry:
  - `test_cash_in_hand_p3b_south_sudan` was missing from its list of rewritten tests (above);
  - `navigation.php` and `public.php` are not handed to the new boundary: `test_bank_boundaries` (both files) and
    `test_cashbook_physical_list` (`navigation.php`) still hold every byte but 5.18.103's declared lines, read through
    `tests/fixtures/r103_revert.php`, and those lines are held by `test_catalogue_direct` I;
  - `test_quote_from_ucrm`'s section I is not re-pointed: it is still measured against `f2055baf`, its declared files
    gain 5.18.103's and I6 its direct sites (30 → 31 checks), and the boundary against `1a0ea5f` is
    `test_catalogue_direct` I;
  - the deploy script retires five of 5.18.102's checks, not two: QL-LK1 and QL-CA1 as planned, and QL-RB1–QL-RB3 with
    `--rollback-unpriced-pending`, which guarded a rollback to 5.18.101;
  - QC-ON4 judges G-7 on a fresh copy of the book, never the live records.
- **The release suite, twice on `221c9dc`:** **pass A2**, 2 Nov 01:11:24 – 06:55:20 UTC, and **pass B3**, 3 Nov
  01:14:03 – 07:32:08 UTC: each 300 files, 18,856 passed, 7 failed — only `test_install_authorisation` 2,
  `test_mail_quote` 1, `test_migration_integrity` 1 and `test_secure_file` 3, the same checks in both, which fail the
  same way on `1a0ea5f` in this container (root `docs/07` 5.18.103, known limit 9). Pass B2 was cut off by a container
  restart in file 221 and does not count.
- **The rehearsal:** `scripts/harness/deploy-5.18.103/rehearse.sh`, chained on 5.18.102's. Trial 3 (on `d28c8f3`) found
  one defect, the rehearsal's own: check 4e looked for the summary's QC-N4 sentence without the `docs/72 G-10;` that the
  deploy script prints, so it could never match. The rehearsal was fixed in `8b9191b`; the deploy script is unchanged.
  **Trial 4, the record, 2 Nov 12:41:22 – 18:14:24 UTC on `8b9191b`: 2,103 ok, 3 failed** — exactly the three inherited
  checks that read development branches absent from this container and from GitHub (98-0, 99-0, 100-0); **5.18.103's own
  sections 182 ok, 0 failed.** Not a clean trial under the 23 Oct rule; whether that is enough to deploy is the owner's
  decision, as for 5.18.99.
- **Found after the suite passes:** one sentence of the release's `SAFETY.md` (Operator action, step 3) says the
  screen lists the waiting 5.18.102 web registrations once the switch is on; it lists them only while the switch is
  off. Recorded as root `docs/07` 5.18.103's known limit 12, not re-cut; their ids are on the deploy's sheet (QC-N4).
- **Next — the owner's:** the go-ahead to push `release/5.18.103`, then the deploy (root `docs/07`'s 5.18.103 entry is
  the runbook). After the deploy the switch is still OFF; an admin turns it on from *uCRM catalogue* once the
  installation product is set. §G.14's three questions are still open, and none blocks the deploy.

## H. 5.18.104 — the sales follow-up (D8–D11; design in progress, 4 Nov 2026)

### H.1 The approved wording (D9), as the owner approved it on 4 Nov

`{ref}` is the quotation's number; `{date}` its last valid day. The two wordings of each step alternate. No price, plan
name or customer name; STOP on every message.

- **Day 1**
  - A: *Hello, this is DishNet. Do you have any questions about quotation {ref}? Reply here and we will help, or we can
    book your installation. Reply STOP if you do not want these messages.*
  - B: *Hello from DishNet. We sent you quotation {ref}. Is anything unclear, or shall we book your installation? Just
    reply here. Reply STOP to stop these messages.*
- **Day 3**
  - A: *Hello, a quick follow-up on quotation {ref} from DishNet. We can answer any question about the kit, the plan or
    the installation — just reply here. Reply STOP to stop these messages.*
  - B: *Hello, DishNet here about quotation {ref}. If you would like to compare options or need help deciding, reply
    here and we will guide you. Reply STOP to stop these messages.*
- **Day 7**
  - A: *Hello, quotation {ref} is valid until {date}. If you would like to go ahead, or need another option, reply here
    and we will take care of it. Reply STOP to stop these messages.*
  - B: *Hello from DishNet. Your quotation {ref} is valid until {date}. Reply here to go ahead or to ask for a different
    option. Reply STOP to stop these messages.*
- **On a salesperson's own number** the first words become *"Hello, this is {first name}'s assistant at DishNet."*
- **The instant reply to a voice note** (until the AI hears them):
  - A: *Thanks for your voice note. We can't listen to voice notes automatically yet — could you type your question in a
    short message? A colleague will also listen and reply.*
  - B: *We got your voice note, thank you. To help you faster, please type your question here. A member of our team will
    also listen to it.*

English only; another language would be the owner's to ask for.
