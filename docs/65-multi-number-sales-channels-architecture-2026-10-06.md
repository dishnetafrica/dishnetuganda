# 65 — Multi-number sales channels: discovery and architecture (06 Oct 2026)

**Status: DISCOVERY AND DESIGN ONLY.** Nothing was changed while preparing this document: no code, no migration, no
table, no configuration, no push, no deploy, no WhatsApp message. Nothing here is approved for building. The operator
reviews it and approves the next batch explicitly.

**Update — Batch 1 built in development (5.18.86), §Z.** Approved 06 Oct; pushed 07 Oct (`b1865ea`), NOT deployed, the new switch
`multi_number_channels_enabled` OFF everywhere. Sections A–Y are the discovery as written and are not edited.

**What was inspected.**
- The code production runs: release commit `4790019` (plugin 5.18.85, deployed 06 Oct 19:13 UTC), read from a detached
  checkout. Every citation is `dishnet-hybrid-sudan/<path>:<line>` at that commit unless it says BRANCH.
- The development branch (`claude/study-this-jhe2eg`, plugin `4a7127d`): undeployed work in this area is noted where it
  matters (the "Batch 0" lead-path fixes, the media layer, the partner portal).
- Earlier documents: docs/17, 18, 25–28, 40, 43, 45, 47–55, 59.

**Evidence labels.**
- **[CODE]** read in the live code, with file:line.
- **[DOCS]** recorded in an earlier document; not re-measured here.
- **[SERVER?]** cannot be read from the repository; needs a read-only look on the server (§X).
- **[DECISION]** a business decision for the operator (§W).

**Not done, deliberately.** No real message was traced on the server: this session cannot reach it, and sending a test
message is out of scope. §C traces one message through the code the server runs, step by step.

---

## Summary

1. **Today the department is the identity, not the number.** [CODE] There are exactly three channel slots — `sales`,
   `support`, `account` — each holding one Evolution instance name (`lib/EvolutionApiService.php:37-42, 118-147`). One
   Evolution server, one global API key, and one webhook URL and secret serve every instance.
2. **The receiving instance is used once, then forgotten.** The webhook turns it into a department
   (`evo_webhook.php:96-101`). After that, conversations, history, AI context, leads and alerts all key on the
   department. Nothing durable records which number a message arrived on.
3. **AI replies leave from the receiving number only because each department holds one number.** The worker sends on the
   department (`workers/AiReplyWorker.php:128, 184`), which is turned back into an instance at send time
   (`EvolutionApiService.php:195-198, 415-428`). With twenty sales numbers, every "sales" reply would leave from the one
   instance configured for sales.
4. **Three paths already send from a different number than the customer wrote to:** staff replies typed in the Inbox go
   out on support (`includes/api/api_whatsapp.php:239`); every staff alert goes out on sales (`lib/AlertService.php:73`);
   a legacy follow-up retries on support.
5. **The brain is already central.** One prompt, prices only from uCRM, one knowledge base. What it knows about the number
   is one role line per department (`lib/DishNetAiBrain.php:866, 931, 944`); the instance is deliberately kept out of its
   context (`lib/BrainContext.php:91`). A per-number context is a small extension, not a second brain.
6. **Owner, territory and portfolio already have a foundation:** the distributor registry built 1–2 Oct (`dist_partners`,
   `dist_regions`/`dist_territory_map`, `dist_customer_links`), dormant behind `distributors_enabled`. Separately, leads
   carry `retailer_id`/`assigned_to`, dealt by workload and taken back after 72 h; Uganda customers have no owner; every
   salesperson sees every lead.
7. **The operator already chose the transport.** [DOCS] docs/49 (1 Oct, "WS-B"): each authorised person on their own
   DishNet-owned SIM (21 bought), one Evolution instance per SIM, Evolution rather than the Cloud API, a spare-SIM pool,
   warming and modest volume, and no number connected until onboarding, messaging policy, cost and rollback are approved.
   This document generalises docs/49's "instance → distributor map" to "instance → channel → owner", where the owner is
   a salesperson or a retailer.
8. **Recommendation in one line:** a channel registry binding each verified Evolution instance to a channel id, an owner,
   a territory and its switches; the channel id carried on every conversation, event, lead and hand-over; replies sent by
   channel id, never by department. The three current numbers become the first three rows **under their present
   strings**, so no stored conversation, message or lead changes.
9. **Before any of it:** three live defects would corrupt a pilot (§G, §D), and five security observations want a look
   (§O.3).

---

## A. Current WhatsApp architecture

| Part | What it is | Live? | Evidence |
|---|---|---|---|
| Evolution API server | One server: one URL `evo_api_url`, one global key `evo_api_key` | yes | `lib/EvolutionApiService.php:113-114` |
| Evolution receiver | `public.php?page=evo_webhook` → `evo_webhook.php` | yes | `public.php:829-833` |
| Legacy receiver (WASender / WhatsML) | `public.php?page=wa_webhook` → `wa_webhook.php` | routed | `public.php:746-750` |
| Legacy feed poller | `cron_wa_sync.php` every 60 s; on unless `wa_sync_enabled` is false | scheduled | `cron/master.php:161`, `cron_wa_sync.php:47` |
| Legacy instance importer | `cron_evo_sync.php` every 300 s; one instance `evo_instance_name`, label `marketing` | scheduled; skips when unset | `cron/master.php:238`, `cron_evo_sync.php:40-47, 129` |
| Website chat | `web_chat.php`, channel `web` | yes | `web_chat.php:297` |
| Queue | `events` table, type `ai.reply` | yes | `migrations/001_*.sql:13-30`, `evo_webhook.php:343-362` |
| AI worker | `run_worker.php` → `workers/AiReplyWorker.php`, only while `ai_enabled` | yes | `run_worker.php:33` |
| Brain | `lib/DishNetAiBrain.php` + `lib/BrainContext.php` + `lib/ReplyPrivacyGuard.php` | yes | §H |
| Notifier | `lib/NotificationService.php`: two senders only, `support` and `accounts` | yes | `NotificationService.php:58-59, 1851-1858` |
| Staff alerts | `lib/AlertService.php`: one number `alert_whatsapp`, always sent from sales | yes | `AlertService.php:41, 73` |
| Distributor channel port | `lib/WhatsAppChannel.php`: `NullWhatsAppChannel` by default; the Evolution adapter is constructed nowhere | dormant | `WhatsAppChannel.php:45-86` |

```
customer ──WhatsApp──► Evolution instance (one paired number each)
                           │  POST {instance, data…} — same URL, same secret for every instance
                           ▼
                  evo_webhook.php ── instance → department (3 slots) ── unknown instance → refused
                           │
           wa_conversations (phone, department)  ·  wa_messages
                           │
                  events: ai.reply {channel = department, whatsapp_instance}
                           ▼
                  AiReplyWorker ── context ── DishNetAiBrain ── ReplyPrivacyGuard
                           │
                  sendText(department) ── department → instance ── Evolution ──► customer
```

**Two legacy transports are still wired beside Evolution.** [CODE] The WASender/WhatsML receiver and poller file
messages into the same `(phone, 'support')` conversations the Evolution support AI reads as history
(`WaInbound.php:55`). They reply only when `wa_bot_enabled` or `wa_auto_reply_enabled` is set
(`WaMessageProcessor.php:123-128`). Whether they are alive in production is [SERVER?].

---

## B. Current three-number architecture

**The slots** [CODE] (`lib/EvolutionApiService.php:118-147`):

| Department (the stored channel string) | Config key | Legacy key that fills a gap |
|---|---|---|
| `sales` | `evo_instance_sales` | — |
| `support` | `evo_instance_support` | `evo_instance_name` |
| `account` | `evo_instance_account` | `evo_accounts_instance_name` |

- **An instance named in two slots** resolves inbound to the first of sales → support → account (first write wins,
  `:134-147`).
- **One global key**, and no per-instance token anywhere in the code (`:113-114`).
- **One webhook URL** `…/public.php?page=evo_webhook&token=<secret>` for every instance (`lib/wa_webhook_url.php:60-65`),
  with one secret (`lib/EvoWebhookGuard.php:43-95`). It is registered per instance by
  `tabs/engage/wa_ai_setup.php:272-288` and re-checked every 10 minutes by `cron/wa_webhook_guard.php:92-139`, which
  iterates the three slots.
- **The brain's role text depends on the department:** `DishNetAiBrain.php:866` (sales), `:931` (accounts), `:944`
  (support).
- **NotificationService has no sales sender:** `accounts` → the account instance, anything else → support
  (`NotificationService.php:1851-1858`); `wa_force_accounts` moves everything to account.

**How many numbers production has is not settled.** [DOCS]
- docs/43:794 and docs/45:243 (27 Sep) record three numbers connected.
- The plugin's own `docs/UGANDA-AI-BRAIN-GAP-ANALYSIS.md:140-152` (≈26 Sep) records two: one on sales, one on support.
- docs/18:24-25 records support and account sharing one number on Uganda. If they do, inbound on that number resolves to
  support, and the account role is never used.

[SERVER?] One read-only command answers it (§X.1).

---

## C. Exact inbound routing path — one message traced through the live code

A customer writes to the sales number.

1. **Entry.** Evolution posts the event to `public.php?page=evo_webhook&token=…` (`public.php:829-833`). POST only
   (`evo_webhook.php:57-59`).
2. **Authentication.** One shared token, from `?token=` or the `X-DishNet-Token` / `X-Webhook-Token` header, compared with
   `hash_equals`; fails closed (`EvoWebhookGuard.php:107-127`; `evo_webhook.php:65-73`). The payload's own `apikey` field
   is not checked.
3. **Body and event.** JSON of at most 512 KB (`evo_webhook.php:76-81`). Accepted events are `messages.upsert`,
   `messages.update` and `connection.update`; anything else gets 200 "ignored" (`EvoWebhookGuard.php:31-35`;
   `evo_webhook.php:87-91`).
4. **The receiving number.** `$instance = payload['instance'] ?? payload['instanceName']` (`:84`), then
   `channelFor($instance)` gives the department (`:96-97`).
   - An unknown instance gets 200 `unknown_instance` and nothing is stored (`:98-101`). The comment states why: guessing
     "would route one number's customers into another number's business context". That rule carries over unchanged.
   - From here on the instance is only logged, kept in `evo_webhook_seen` (pruned after 72 h) and carried as
     `whatsapp_instance` in the queue payload.
5. **Filtering.** Groups and broadcasts are skipped (`:132-135`). A replay older than 900 s is dropped before storage
   (`:138-143`). The message id is claimed once in `evo_webhook_seen` (`:149-152`).
6. **Messages typed on the business handset** (`fromMe`) are stored as an agent message and **pause the AI on that
   conversation** (`markHumanHandling`, `:200-210`). They are never queued for the AI. This is the existing hand-over to
   whoever holds the phone, and it matters for per-person numbers (§L).
7. **The sender.** Digits of `key.senderPn`, else `key.remoteJid`; a `@lid` sender without `senderPn` is dropped
   (`:220-222`). Text is read from eight message shapes; a location pin is merged into the text (`:238-253`).
8. **Storage.** `importEvoMessage($msg, $channel)` → `ensureConversation($phone, $channel)`, which looks up
   `WHERE phone = ? AND channel = ?` (`lib/ConversationService.php:228-236`) → `storeMessage`, de-duplicated on
   `wa_message_id`.
9. **Colleagues (Uganda only).** A sender whose full number belongs to an active staff row is filed as `staff` and not
   answered (`evo_webhook.php:270-298`). Sales, field agents and collectors are deliberately not "staff"
   (`lib/StaffDirectory.php:23`), so the AI answers them like customers.
10. **STOP.** A whole-word match on a short message writes `contact_optouts` with channel `'*'` and scope `proactive`: every
    number, proactive messages only. The message is still answered (`evo_webhook.php:307-328`).
11. **Media without text** is stored but not queued (`:336-341`).
12. **Queue.** `ai.reply` for the conversation, priority 3 (`:343-362`), with payload
    `{channel, whatsapp_instance, customer_phone, message, push_name, wa_message_id, remote_jid, received_at, location}`.
    Then `exec php run_worker.php` when possible; otherwise the scheduler's `ai_reply` job every 60 s.
13. **Worker gate.** `run_worker.php` exits unless `ai_enabled` (`:33`). `AiReplyWorker` consumes `ai.reply` only. The event
    processor releases `ai.reply` claims instead of acknowledging them (`cron/event_processor.php:236-241`).
14. **Human pause.** If the conversation is `human_active` within `wa_human_cooldown_minutes` (default 1440), the message is
    parked and later dropped (`AiReplyWorker.php:149-159, 1246-1327`). `needs_human` does not pause the AI.
15. **Customer.** `identifyCustomerByPhone` checks the local index, then uCRM, by the last 9 digits; more than one match is
    ambiguous (`AiReplyWorker.php:319-366`; `lib/DishNetTools.php:67-139`).
16. **Context, chosen by department** (`AiReplyWorker.php:373-425`).
    - Sales gets the catalogue through `BrainContext::build` (`:494-508`).
    - Support gets the customer's services.
    - Account gets balance and invoices, only when the customer is identified unambiguously.
    - History is the last 20 messages of this conversation's identity epoch (`:443`).
17. **Brain.** `DishNetAiBrain::reply` (`:94`) builds the prompt (`:145-244`), calls the provider and parses the markers. The
    reply is checked by `guardReply` (`AiReplyWorker.php:685-765`).
18. **Reply.** `sendText($channel, $phone, $reply, CLASS_REPLY)` (`:184`) — see §D.
19. **After sending.** The echo is claimed and the reply stored as "DishNet AI" (`:210-225`). **Lead capture then fails
    silently:** line 258 passes `$ctx`, which does not exist in `handle()` (whose variable is `$context`, `:167`), and the
    TypeError is caught (`:275`). Fixed on the branch (Batch 0), not deployed.

---

## D. Exact outbound routing path

| What is sent | How the sending number is chosen | From the number the customer wrote to? | Evidence |
|---|---|---|---|
| AI reply, typing, photo, document, flyer, holding line | the worker's `$channel` (the department, from the event) → `instanceFor(department)` at send time | yes today, because each department holds one instance; **no** once a department holds several | `AiReplyWorker.php:128, 165, 184`; `EvolutionApiService.php:195-198, 415-428` |
| Hand-over alert to staff | always `sales` → `alert_whatsapp` | — (to staff, always from sales) | `AlertService.php:41, 73`; `AiReplyWorker.php:1425-1431` |
| Staff reply typed in the Inbox | `accounts` only if the conversation's channel is exactly `accounts`; otherwise `support` | **no** for sales and account conversations (Evolution writes `sales` and `account`) | `includes/api/api_whatsapp.php:239, 266, 988, 1018`; `NotificationService.php:1851-1858` |
| Follow-ups | the stored conversation channel | yes, by the same round trip as AI replies | `cron/followup_send.php:62, 118` |
| Invoices, receipts, quotes, OTP, job messages, welcome | NotificationService `support` / `accounts` | — (business-initiated) | `NotificationService.php:1520-1544` and its callers |
| Legacy lead follow-up | the legacy instance, then a second try via support | can be a second number | `tabs/engage/whatsapp.php:314-328`; `tabs/sales/wa_leads.php:65-78` |
| Distributor alerts | the Null port; nothing is sent | — | `WhatsAppChannel.php:45-53` |

- **No automatic fallback between instances.** An unmapped department fails, retries five times, then hands over
  (`AiReplyWorker.php:1376-1386`). NotificationService falls back to the WASender transport only when Evolution is not
  configured (`NotificationService.php:1869-1889`).
- **The Inbox defect is live today.** A colleague replying from the Inbox to a customer who wrote to the sales number
  sends from the support number. The message is also logged into a separate `(phone, 'support')` conversation
  (`NotificationService.php:2255-2281`). The customer's answer then arrives on support, where no pause exists, so the
  support AI can answer over the colleague. It wants fixing whatever is decided here.
- **For twenty numbers:** the outbound side has no way to name a number. Every send call names a department, and a
  department names one instance.

---

## E. Current conversation identity

- **The key.** `wa_conversations` is UNIQUE on `(phone, channel)` (`migrations/017_*.sql:40-41`), looked up with
  `WHERE phone = ? AND channel = ?` (`ConversationService.php:232`). There is no instance column.
- **What `channel` holds:** the department string `sales`, `support` or `account`; older rows `accounts`, `support` and
  `marketing`; web chat `web`.
- **Already separate per department.** The same customer writing to two departments gets two conversations, with separate
  history, identity epoch, human pause and lead link. That is the separation the multi-number design needs, one level too
  coarse.
- **History for the prompt** is `getMessagesForAi(conversation, identityKey, 20)`: the current identity epoch only
  (`ConversationService.php:635-683`).
- **The legacy receivers** also write into `(phone, 'support')` rows (`WaInbound.php:55`).

---

## F. Current customer identity

- **Customer = uCRM client, resolved by phone on every turn** (`DishNetTools.php:67-139`).
  - At least 9 digits are needed.
  - First the local `client_search_index` (one phone per client), matched with `CustomerIdentity::same`: the last 9 digits
    equal, and the country codes agreeing when both state one (`CustomerIdentity.php:80-117`).
  - uCRM is asked only when the index finds nothing.
  - Several matches make the customer ambiguous; the brain is then told to ask and reveal nothing.
- **Lead** = a row in the store-managed `leads` table, matched by `LeadMatcher` on the last 9 digits, with no country
  check, open leads only (`LeadMatcher.php:15-49`).
- **Three matching rules disagree:** the index is country-aware; leads and the uCRM lead sync use the last 9 digits; staff
  numbers use the whole number.
- **Identity is global, not per number. That is right and should stay:** the multi-number design must not create
  per-channel customers.
- **Rule already decided** [DOCS]: never attribute a customer to an owner by phone number (docs/49 §6.3; docs/47).

---

## G. Current lead ownership

- **Storage.** `leads` is a JSON-blob table (`id, data`) with indexes on `status`, `retailer_id` and `assigned_to`
  (`lib/SqliteStore.php:1125-1130, 1162-1167`).
- **AI leads** get `source = 'whatsapp_ai'`, `conversation_id`, `retailer_id = 0` and `assigned_to = null`
  (`lib/AiLeadService.php:216-237`).
- **Assignment is by workload, not by owner.** `cron_leads.php` runs every 4 h and deals leads to active sales and field
  agents, lightest load first. It flags a lead at 48 h and moves it to another agent at 72 h (`cron_leads.php:21-33,
  66-67, 126, 201-236`).
- **Visibility.** "All leads visible to all sales staff" (`tabs/sales/leads.php:4`). "My lead" (`retailer_id` or
  `assigned_to`) is a filter, not a boundary (`:347`).
- **"Which WhatsApp number generated this lead?"** — not answerable directly. There is no column for source number,
  department, owner or territory. The answer exists only indirectly: `lead.conversation_id` → `wa_conversations.channel`
  (a department) → today's instance for it → the number.
- **Live defects** [CODE], all three fixed or partly fixed on the branch, none deployed:
  - **Lead capture has never run on WhatsApp in production** (§C step 19).
  - **The uCRM lead sync drops every event:** `workers/UcrmLeadWorker.php:28` reads `payload`, but the worker base stores
    `_payload`. [DOCS] docs/55:136-148 records both defects, and records `ai_lead_capture` and `ai_crm_lead_sync` as ON
    in production.
  - **The event processor acknowledges every unknown event type except `ai.reply`** (`cron/event_processor.php:236-246`).
    `wa.escalation` and `crm.lead.sync` retries can be swallowed. The branch adds `ai.media` to that list, and nothing
    else.
- **Customers (Uganda) have no owner field in the plugin.** uCRM's "Sales Person" attribute is sent only on the South
  Sudan layout (`lib/UcrmClientTarget.php`).
- **Distributor ownership exists, dormant.** `dist_customer_links` allows one active owner per client or lead
  (`migrations/079_*.sql:63`). Links are human-confirmed and never made by phone (`lib/DistributorAttribution.php:127-197`).

---

## H. Current AI context

**What the brain knows about the number: the department role only.**
- The instance, the business number and the conversation id are on `NEVER_PRESENT` (`BrainContext.php:82-102`).
- `ai_identity_line` is one value per install (`DishNetAiBrain.php:264`).

**How the prompt is built** (`DishNetAiBrain.php:145-244`):

| Part | Scope today | Becomes, multi-number |
|---|---|---|
| Absolute rules, style, safety | global | global (unchanged) |
| Identity header (`ai_identity_line`) | install | install, plus an approved channel persona line (§K) |
| Role line and role rules (`:860-959`) | department | the channel's `role` |
| Knowledge base (`knowledge_items`), `ai_fact_*`, stock, currency | install | global (unchanged) |
| Plans, hardware, network, accessories — from uCRM only | global catalogue | global (unchanged) |
| Prospect, qualification, LEAD marker, hardware block | sales role | sales role (unchanged) |
| Customer, services, account, history | customer / conversation | customer / conversation (unchanged) |
| Portfolio relationship, territory label | — | channel (new, small, allow-listed) |

**Prices** come from uCRM only (`DishNetTools::getProducts`); a "PLANS: unavailable" fence applies when the lookup fails.
Nothing about prices changes per number.

**Department differences.** Sales gets the strict `BrainContext` contract, qualification, the LEAD marker and the hardware
block. Support and account use the older raw context (`BrainContext.php:281`), plus an "also sell here" block when
`ai_sales_on_all_numbers` is set.

**Hand-over** (`AiReplyWorker.php:1404-1464`). Triggers are the markers, a "speak to a human" regex and a guard block.
What happens: `needs_human` is set and `wa.escalation` is emitted (nothing consumes it); an alert goes to `alert_whatsapp`
from the sales number; the holding line goes to the customer on their own channel.

**Flags.**
- `ai_enabled` is the master switch.
- Behaviour switches: `ai_qualification`, `ai_hardware_expert`, `ai_sales_on_all_numbers`, `ai_lead_capture`,
  `ai_crm_lead_sync`.
- Wording and hand-over: `ai_identity_line`, `bot_custom_instructions` (with its mode), `ai_fact_*`, `ai_handover_message`,
  `wa_human_cooldown_minutes`.
- **No switch per number or per department on the Evolution path.** The only lever is to unmap an instance, which also
  stops messages being stored.

---

## I. Current Evolution architecture

- **One server, instances addressed by name** in the URL path (`/message/sendText/<instance>`,
  `EvolutionApiService.php:425`).
- **One global API key**, sent as a header on every call. No per-instance token is stored or used.
- **Webhook:** the same URL and secret for every instance. The instance name comes from the payload and is trusted once
  the secret matches.
- **Pairing is per instance:** QR code, connection state and phone are per instance (`EvolutionApiService.php:245-357`;
  `tools/set_evolution.php:226-229`). In this code an instance is one paired WhatsApp account, which is one number.
- **Where the credentials live:**
  - `kyc_config.json`, written at mode 0600 (`lib/PluginConfig.php:136-159`).
  - The vault (`lib/ConfigVault.php`), written 0640 though its docblock says 0600 (`lib/SecureFile.php:27`).
  - uCRM's Configuration form (`manifest.json`).
  - Not mirrored into the SQLite settings row (`PluginConfig.php:22-39, 308-314`).
  - `tools/set_config.php` refuses secrets; `tools/set_evolution.php` reads the key from stdin only.
- **Recorded decisions and constraints** [DOCS]:
  - docs/49 (operator, 1 Oct): one Evolution instance per SIM, 21 DishNet-owned SIMs, Evolution not the Cloud API.
  - docs/53: the operator chose the support instance for distributor sign-in codes (option D2).
  - docs/59 §2.5: there is no staging Evolution, and a new instance is "a change to Domain A's gateway, which needs its
    own authorisation". docs/53 calls the same thing "the plugin's own Evolution integration".
  - **Whichever wording is right, creating instances on the production Evolution server is a production change outside
    this plugin and needs explicit approval** [DECISION D11].
- **Not knowable from the repository** [SERVER?]: the Evolution version; per-instance tokens; memory and CPU per connected
  instance on the host; reconnect behaviour after a host restart.

---

## J. Proposed Channel model

**One registry row per WhatsApp number DishNet operates** (proposed table `wa_channels`; a design, not a migration):

| Column | Meaning | Notes |
|---|---|---|
| `channel_id` (PK) | stable id stored on conversations, events, leads | The three existing numbers keep their present strings `sales`, `support`, `account` as ids, so no stored row changes. New rows get new ids, e.g. `SALES-002`, `RET-001`. |
| `evo_instance` (UNIQUE, case-insensitive) | the Evolution instance name, exactly | The only key inbound routing uses; never a display name. |
| `business_number` (UNIQUE) | the number, E.164 | Read from Evolution's own instance report when the number is verified, never typed. |
| `display_name` | how staff see it | e.g. "Sales — Kampala 2" |
| `role` | `sales` / `support` / `account` | The brain's role; replaces today's switches on the channel string. |
| `owner_type` | `department` / `staff` / `partner` | |
| `owner_staff_id` | a staff row id | an internal salesperson |
| `owner_partner_id` | a `dist_partners.id` | a retailer or distributor |
| `territory_region_id` | a `dist_regions.id`, or NULL | |
| `portfolio_scope` | `own` / `territory` / `all` | what "this number's customers" means |
| `ai_enabled` | 0/1 | per number, beneath the install-wide `ai_enabled` |
| `handover_to` | `owner` / `department` / `central` | §L |
| `status` | `active` / `paused` / `disabled` / `retired` | disabled refuses traffic: fail closed |
| `verified_at`, `verified_by` | the instance ↔ number check | |
| `created_*`, `updated_*` | who and when | |

**A second, append-only table `wa_channel_log`** records every create, edit, owner change, disable and re-pairing: the
previous value, the new value, who made the change and why. It is protected by triggers, like migration 086's.

**Owners reuse what exists — no new owner entity.**
- An internal salesperson is a staff row (`retailers.json`, role `sales`).
- A retailer or distributor is a `dist_partners` row. The types `authorised_reseller` and `corporate_retail` already exist
  (`migrations/078_*.sql:24-43`).
- The three existing numbers have owner type `department`.

**Territory reuses `dist_regions` / `dist_territory_map`, with one conflict.** `area_key` is unique across the whole
business (`migrations/079_*.sql:44`), so an area can belong to one partner only. Two salespeople who both cover Kampala
cannot be expressed there. [DECISION D6] Options:
- the channel row references a region, with no exclusivity; or
- exclusivity is kept for partners and not used for staff.

**Portfolio reuses `dist_customer_links` for partners.** For salespeople, today's `assigned_to` on a lead is a workload
pointer that moves at 72 h, not an owner. [DECISION D5] Two options; (a) is recommended:
- **(a)** widen the link table's owner to `owner_type` plus `owner_id`: one ownership model for everyone, with history and
  audit already designed;
- **(b)** keep staff on `assigned_to`, and exempt leads that came in on an owned channel from workload reassignment.

**Not to be duplicated:** a second owner entity, a second customer → owner link, a second territory table, a second
number-verification store, a second WhatsApp integration. docs/49 rules out the last one; the `WhatsAppChannel` port is
the seam. Note that `dist_contacts` holds a distributor's *alert destination* numbers. It is not a register of
DishNet-owned serving numbers, and must not become the channel registry.

---

## K. Proposed ChannelContext

**Resolved once per inbound message** by the resolver. Only the `channel_id` travels in the event; the row is re-read when
it is used, so a disabled or edited channel binds the next turn.

```
ChannelContext {
  channel_id, role, display_name, status,
  owner:     { type, id, first_name },        // first name only — the rule the authorisation messages already follow
  territory: { region_id, label } | null,
  portfolio_scope, ai_enabled, handover_to,
  instance                                    // server side only; never reaches the model
}
```

**What the brain receives** — allow-listed, the way `BrainContext` allow-lists today:
- the `role`;
- one persona line, from wording the operator approves [DECISION D3], for example *"You are DishNet's sales assistant on
  the line of <first name>, <territory>"*;
- whether this customer is in this channel's portfolio (`yes` / `no` / `unknown`), and the owner's first name only when
  the answer is yes;
- the territory label.

**Never:** the instance, the business number, an owner's phone, another channel's customers or conversations, or any
portfolio list.

**Global knowledge is unchanged.** The product, price, plan, policy and knowledge-base blocks are built exactly as today.
The channel adds one small block, so twenty numbers share one prompt template and one knowledge base.

**The resolvers, conceptually:**
- `resolveChannel(instance)` → a ChannelContext, or a refusal (exact instance, status active).
- `resolveCustomer(phone)` → today's `identifyCustomerByPhone`, unchanged and global.
- `resolveSalesContext(channel, customer)` → the portfolio relationship, read from the ownership links, never from a
  phone number.
- `DishNetAiBrain::reply(context)` → the one brain.

---

## L. Proposed multi-number routing

**Inbound**
1. The webhook resolves `instance` → `channel_id` through the registry (exact match, case-insensitive). An unknown
   instance is refused, as today. A disabled channel: [DECISION D9] store the message and alert, with no AI; or refuse.
2. **The conversation key becomes `(phone, channel_id)`.** The existing unique index already does this once `channel`
   holds the channel id. The three existing ids are the existing strings, so today's rows are already correct.
3. The event carries `channel_id`, and the instance as received. The instance is used for the consistency check below,
   never for routing.
4. Every `switch ($channel)` that means "which role" becomes `switch ($role)`, with the role read from the registry.

**Outbound**
1. **Every send for a conversation names the conversation's `channel_id`;** the registry gives the instance. Never a
   department name, and never a default.
2. **Before sending, the worker checks that the instance it is about to use is the one recorded on the inbound event.** A
   mismatch (the channel was re-paired mid-flight) means no send and a hand-over. Fail closed.
3. **A disabled channel sends nothing.**
4. **Inbox replies are sent on the conversation's channel.** This also fixes today's defect for the three numbers.
   - Sending from the staff app stays admin-only (`lib/WhatsAppAccess.php:36-54`).
   - A salesperson or retailer replies from the handset that holds the number. The webhook already sees those messages
     (`fromMe`) and pauses the AI on that conversation (`evo_webhook.php:200-210`). That is the natural hand-over for a
     person who owns a number.
5. **Business-initiated messages keep their senders.** Invoices, receipts, OTP and job messages stay on
   `support`/`accounts`. [DECISION D10] whether a quotation for a channel's customer is sent from that channel.

**Hand-over and notifications**
- **An escalation alerts the channel's owner** (a staff phone; for a partner, its single verified `dist_contacts` number),
  and the central team as decided in [DECISION D4].
- **Staff alerts are sent from a DishNet number designated for them, never from a customer-facing personal number** —
  every automated send from a person's number adds to its ban risk. Today all alerts come from the sales instance.
- **Owner notifications:** `NEW_LEAD`, `CUSTOMER_REPLIED`, `QUOTE_REQUESTED`, `PAYMENT_REPORTED`,
  `INSTALLATION_ACCEPTED`, `INSTALLATION_COMPLETED` and `SUPPORT_ESCALATION`.
  - Each resolves its owner from the channel or the portfolio.
  - Each reuses the distributor notifier's spine: dedup key, owner mute, privacy guard, draft → approve
    (`lib/DistributorNotifier.php:218-250, 313-340`).
- **Prerequisites:** a consumer for `wa.escalation`, and every new worker-owned event type on the event processor's
  protected list.

**Leads**
- An AI lead carries `channel_id`, `source_number` (the registry's verified number at that moment), the owner, the
  territory and the conversation id.
- A lead from an owned channel is not reassigned by workload [DECISION D5].
- With these fields, "which number generated this lead?" is a field, not a log search.

---

## M. Proposed customer / channel / owner relationship

**Three identities, kept apart:**
- **Customer** — global: a uCRM client or a lead, resolved by phone with today's strict rules.
- **Conversation** — `(customer phone, channel_id)`: one for each number the customer writes to.
- **Owner** — of the channel (who holds the number) and of the customer (the portfolio link). The two can differ.

| Case | Behaviour |
|---|---|
| A customer of A writes to A's number | Normal. The brain is told "in portfolio", with A's first name. |
| A customer of A writes to B's number | B's conversation. The AI answers from global knowledge and reveals nothing of A's dealings. [DECISION D2] Notify A, redirect the customer, or let B serve. |
| A new prospect writes to B's number | B's conversation. The lead is created with owner B, if D5 says so. |
| One customer writes to both A and B | Two conversations and two histories; one customer identity. |
| A customer is transferred from A to B | Relink with audit (previous, new, reason, who). Each channel's conversation keeps its own history. B does not inherit A's conversation text unless D2 says so. Open leads move. |
| A leaves DishNet | A's channel is disabled (the number stays DishNet's) or re-owned. The portfolio is relinked, with audit. |
| A's number is banned or replaced | A new SIM and instance are bound to the same `channel_id` after verification. The plugin's history continues, because the key is the channel id, not the number. On the customer's phone it is a new chat. |

---

## N. Proposed retailer architecture

**A retailer is made of parts that already exist** (live, dormant), plus one new registry row:
- **who:** a `dist_partners` row, with its appointment;
- **where:** its territory, in `dist_regions`;
- **who to alert:** its contact, in `dist_contacts` (verified);
- **which number:** a channel row with `owner_type = partner` (the only new part);
- **whose customers:** its portfolio, in `dist_customer_links`.

The AI inherits the central knowledge without change.

| Onboarding step | What does it |
|---|---|
| Create the retailer | `DistributorRegistry::create`, or appointment from an application (live, behind the flag) |
| Create / assign the WhatsApp number | a DishNet SIM (docs/49), and an Evolution instance on the server [DECISION D11: Domain A approval] |
| Connect the Evolution instance | pairing by QR; the admin functions exist (`EvolutionApiService.php:245-357`) |
| Assign the territory | `DistributorAttribution::addArea` |
| Assign the sales role | the channel row's `role = sales` |
| Enable the central AI | the channel row's `ai_enabled` |
| Number is ready | the instance ↔ number check passes, and staff-only test contacts get correct replies |

**What the retailer can see and do:**
- **Their portfolio,** through the partner portal on the branch. It is scoped by the session's `partner_id`, never by the
  request, with isolation tests including a weakened copy that leaks.
- **Undeployed, read-only, and limited:** the portal shows link rows today, not customer details.
- **No staff or uCRM login** — ruled out in docs/47 §10.2.
- **No sending from the staff app.** They answer from their handset.

**Branding and pricing** [DECISION D3]: whose name the AI uses on a retailer's number, and how it describes the
relationship. The brain knows uCRM's prices only; retailer-specific prices or margins are out of scope.

---

## O. Security and permission model

### O.1 The boundaries, in layers

1. **Number binding.** Instance ↔ channel ↔ verified number, in the registry. Unknown or disabled instances are refused.
   Sends go by channel id, with the inbound-instance check.
2. **Conversation isolation.** Per `(phone, channel_id)`; history and pause are per conversation; no memory across
   channels in the prompt.
3. **Brain context.** Allow-listed. The ChannelContext adds no customer data. `ReplyPrivacyGuard` is unchanged: it still
   blocks identifiers that are not this customer's.
4. **People.** Salespeople and retailers see only their own portfolio. This is enforced server side, with the partner
   portal's pattern: scope from the session and never from the request, composite id checks, field allow-lists.
   **Today every salesperson sees every lead** (`tabs/sales/leads.php:4`), so that boundary must be built before a
   portfolio model means anything.
5. **Sending.** Stays admin-only in the staff app; people reply from the handset.
6. **Audit.** Registry changes and ownership changes go into append-only logs.

### O.2 What multiplies with twenty numbers

- **One global Evolution key controls every instance** — sending, logout, deletion. Its blast radius becomes twenty
  numbers. If the deployed Evolution offers per-instance tokens, sending should use them, and the global key should be
  kept for administration only, on the server side [SERVER?].
- **One webhook secret serves every instance**, and once it matches, the payload's instance name is trusted. A leaked
  secret would let anyone post as any of the twenty numbers. [DECISION D12] Options: a per-channel secret
  (docs/49 §11), or confirming each inbound message with Evolution before acting on it.

### O.3 Observations found during discovery — not fixed; each needs its own approval

- **SEC-1. An admin page carries the Evolution key in its HTML.** `tabs/accounts/wallet_admin.php:64-75` writes
  `evo_api_key` and the WASender keys into hidden form fields. The tab is admin-only, but RBAC has a grantable
  `wallet_admin` permission (`lib/RbacService.php:428`). Whether the database settings row the page reads still holds the
  key is [SERVER?]. **Answered 07 Oct, 04:25 UTC, by 5.18.86's deploy (A3, yes/no only): it does** — the key is in the
  page's HTML in production. Still open, not fixed (docs/07, 07 Oct, 5.18.86 RESULT).
- **SEC-2. A credential sits in source.** `cron_wa_sync.php:45-54` carries a literal fallback feed secret and session ids;
  the job runs every 60 s and is on by default. Whether the legacy feed is still alive is [SERVER?].
- **SEC-3. The legacy receiver can run unauthenticated.** `wa_webhook.php:84-100` skips authentication when
  `wa_webhook_secret` is empty, and `public.php` defaults it to empty. An unauthenticated POST could then write messages
  into `(phone, 'support')` conversations, which the support AI reads as history. Whether the secret is set is [SERVER?].
- **SEC-4. The AI tools endpoint is not bound to the conversation.** `ai_tools.php:104-126` returns customer, services,
  account and invoices for any `client_id` sent with its bearer token. Nothing binds the id to the phone in the
  conversation, though its own header says it should. Any tool exposed to a many-number brain must be bound to the
  conversation's identified customer.
- **SEC-5. File mode.** The vault is written 0640 while its docblock says 0600 (`lib/SecureFile.php:27`).

---

## P. Migration strategy

**Backwards compatible and additive. Nothing stored is rewritten.**

1. **P0 — fix the live defects that would corrupt a pilot.** Each is its own approved release:
   - Inbox replies' sending number (§D);
   - deploy the Batch 0 lead fixes (capture and sync, §G);
   - the event processor's protected list, plus a consumer for `wa.escalation`.
2. **P1 — the registry table, dark.** An additive SQLite migration, applied by the plugin's own runner as 086 was,
   seeded with exactly three rows from today's keys: `sales`, `support` and `account` → their instances. Because the ids are
   today's channel strings, `wa_conversations`, `wa_messages`, events and leads keep every stored value.
3. **P2 — the resolver reads the registry,** falling back to the `evo_instance_*` keys when the registry is empty or
   absent (South Sudan, or before seeding). It sits behind a switch that is off by default; off means byte-identical
   behaviour, proved by golden tests.
4. **P3 — role lookups replace the channel-string switches.** For the three rows the role equals the id, so behaviour is
   identical.
5. **P4 — sends go by channel id.** Again identical for the three.
6. **P5 — the fourth number** is added (§Q).

- **The `evo_instance_*` keys stay readable** until every install has a registry; the admin screen writes the registry.
- **Never migrated:** customers, conversations, messages, leads.
- **South Sudan:** no registry → the keys path → unchanged. Uganda-gated, like every recent feature.
- **Domain B** (the MikroTik control plane) is not touched.

---

## Q. Rollout strategy

| Phase | What | Gate to the next | Rollback |
|---|---|---|---|
| 1. Architecture / design | this document; decisions D1–D12 | operator approval | — |
| 2. Channel registry | P0 fixes, then P1–P2 dark; three rows seeded | golden tests: no behaviour change; pinned deploy and rehearsal, as for 5.18.85 | none needed (dark) |
| 3. Convert the three numbers | switch on; the three served through the registry | a week's traffic per number (counts, reply time, hand-overs) matches the week before | switch off → the keys path |
| 4. Verify AI behaviour unchanged | golden prompt fixtures byte-identical for the three; Inbox fix confirmed | zero differences | switch off |
| 5. Add one salesperson number | one SIM, warmed; instance created (D11); row added; AI on; staff-only test contacts first | wrong-number sends = 0; replies from that number only | disable the channel |
| 6. Controlled pilot | that one number with real customers, about two weeks | no ban signal; hand-over reaches the owner; leads carry channel and owner | disable the channel |
| 7. Add a retailer | one retailer, after the portal decision | the portfolio boundary proven in production | disable the channel |
| 8. Scale to twenty | batches (e.g. +3, +6, +10), never all at once; spare SIMs ready | per-batch review: bans, Evolution host load, cost | disable channels singly |

---

## R. Testing strategy

**How the tests would work:**
- A sandbox fake Evolution that hosts several instances and records which instance each send used (an extension of the
  fake Evolution the media tests already use).
- Every test has weakened copies, as the project does. For example, a copy that sends by department instead of by
  channel id must fail test 1.

| # | Test | How |
|---|---|---|
| 1 | Number A receives → AI replies from A | two channels; assert the send's instance equals A's |
| 2 | Number B receives → AI replies from B | as above, reversed; also the instance-mismatch case fails closed |
| 3 | Customer history correct | messages land in `(phone, channel_id)`; the prompt history holds only that conversation |
| 4 | Customer identity global | the same uCRM client resolves on both channels |
| 5 | Same customer on two numbers | two conversations; no cross-history; pause on A does not pause B |
| 6 | Salesperson A cannot see B's portfolio | server-side scope tests in the style of `test_dist_isolation.php`, with a leaking weakened copy |
| 7 | Retailer isolation | as 6, for partners |
| 8 | Knowledge identical | the prompt minus the channel block is byte-identical across channels |
| 9 | Channel context differs | role, persona and portfolio flag differ as configured |
| 10 | Territory context differs | the label follows the channel's region |
| 11 | Leads carry the source channel | `channel_id`, `source_number`, owner and conversation on every AI lead |
| 12 | Hand-over routed correctly | the alert reaches the channel owner; the holding line goes from the customer's channel |
| 13 | Notifications to the right owner | each event resolves the owner; dedup and mute hold |
| 14 | Disabled channel | no AI and no send; the inbound handling per D9 |
| 15 | AI-disabled channel | messages stored, no AI reply; a human can still answer from the handset |
| 16 | Three department numbers unchanged | golden fixtures: routing, prompts, sends byte-identical |
| 17 | South Sudan unchanged | its fixtures and its keys path unchanged |
| 18 | Domain B untouched | no file under the control plane in the diff |

**Also tested:** an unknown instance is refused; an edited or re-paired channel fails closed; Inbox replies go out on the
conversation's channel; a STOP behaves as D8 decides.

---

## S. Risks

| Risk | Why | Mitigation |
|---|---|---|
| WhatsApp bans | Evolution automates WhatsApp's linked-device protocol (an unofficial session); twenty numbers mean twenty exposures | DishNet-owned SIMs, warming, modest volume, no bulk proactive sends from person numbers, spare SIMs; the Cloud API as a fallback adapter behind the port (docs/49) |
| Wrong-number reply | today's department round trip | send by channel id, the instance check, tests 1–2 |
| Key or secret compromise | one global key, one webhook secret | §O.2 |
| Evolution host load | one live session per instance | measure memory and CPU per instance before scaling [SERVER?]; scale in batches |
| Approval boundary | the Evolution server is recorded as Domain A's gateway | D11 before any instance is created |
| Human and AI answering at once | the person also types on the handset | the existing pause (default 24 h), tunable per channel [DECISION D4] |
| Portfolio leakage | the AI or staff screens show another owner's customers | server-side scoping; the brain gets a yes/no only |
| Ownership churn | workload reassignment moves owned leads | D5 |
| Legacy transports | WASender/WhatsML still wired; a second AI could answer | decide on retirement (SEC-2, SEC-3) |
| Lost events | the event processor swallows unknown types | P0 |
| AI cost × N | more conversations | per-channel AI switch; volume review per batch |
| Opt-out semantics | STOP is global across numbers today (`'*'`) | D8 |
| A person leaves | the number and portfolio must move | numbers are DishNet's; the transfer procedure in §M |

---

## T. Exact files and modules that would need modification

**New**
- `migrations/087_wa_channels.sql` — the registry and its append-only log.
- `lib/ChannelRegistry.php` — resolve by instance and by id; admin writes with audit.
- `lib/ChannelContext.php` — the value object.
- An admin screen — new, or an extension of `tabs/engage/wa_ai_setup.php`.
- A read-only CLI to list and verify channels.
- Tests.

**Changed**

| File | Change |
|---|---|
| `lib/EvolutionApiService.php` | instance resolution through the registry; sends by channel id; the department API kept as an alias for the three |
| `evo_webhook.php` | resolve the channel; handle a disabled channel; carry `channel_id` |
| `lib/ConversationService.php` | channel ids beyond the three, including the unread counts that only look at support, web and accounts (`:1032-1037`) and the `'support'` defaults (`:72, 142`) |
| `workers/AiReplyWorker.php` | role from the ChannelContext; send by channel id; the instance check; hand-over target; lead fields |
| `lib/DishNetAiBrain.php` | role from the context, not the channel string (`:147, 185, 392, 541, 863-955, 1017, 1204`); the persona block |
| `lib/BrainContext.php` | a `channel` sub-object in the allow-list; the default role |
| `lib/AlertService.php` (and the branch's `lib/Handover.php`) | owner routing; a designated alert sender |
| `includes/api/api_whatsapp.php` | Inbox sends on the conversation's channel (`:239, 266, 988, 1018`) |
| `cron/wa_webhook_guard.php` | iterate the registry |
| `cron/followup_send.php` | channel id → registry |
| `lib/AiLeadService.php`, `workers/UcrmLeadWorker.php` | channel, owner and territory on leads |
| `cron_leads.php` | owned leads not reassigned by workload |
| `cron/event_processor.php` | protected list for new worker-owned events; a `wa.escalation` consumer |
| `lib/DistributorAttribution.php`, `lib/DistributorNotifier.php`, `lib/DistributorEvents.php` | owner types staff and partner; channel-owner events |
| `tabs/sales/leads.php`, `includes/api/api_leads.php` | the portfolio boundary |
| `lib/WhatsAppAccess.php` | a `channels.manage` admin action |
| `tools/set_evolution.php` | list the registry |

**Not changed:**
- `NotificationService`'s transactional messages;
- the uCRM webhook handlers in `webhook.php`, except owner notifications later;
- installation authorisation, cashbook and photos;
- South Sudan behaviour;
- Domain B.

---

## U. Is one Evolution instance per number appropriate?

**Yes.** The four options asked about:
- **A (one instance per number) and C (one instance per number) are the same arrangement.** It is the only one this code
  and Evolution's pairing model support: an instance is one paired WhatsApp account, with its own QR code, connection
  state and phone (§I). It is also what the operator decided on 1 October (docs/49).
- **B (several numbers through one instance)** is not supported by the per-instance pairing this code uses.
- **D (another arrangement)** — the official WhatsApp Cloud API:
  - registers each number with Meta, signs its webhooks with HMAC, charges under Meta's own pricing, and requires
    approved templates for business-initiated messages;
  - the operator ruled it out for WS-B on 1 October;
  - it remains the fallback adapter behind the `WhatsAppChannel` port, so a number that keeps getting banned can move to
    it without a second integration.

**To verify before scaling** [SERVER?]:
- the Evolution version and whether it offers per-instance tokens;
- memory and CPU per connected instance on the host;
- webhook behaviour per instance;
- reconnection after host restarts;
- pairing logistics for twenty handsets;
- the approval boundary (D11).

**Conventions:**
- **Instance names are deterministic** (e.g. `dn-ug-<channel_id>`), never reused for another channel.
- **The registry binds instance ↔ verified number,** reading the number from Evolution's own report, never from a typed
  value.
- **Spare instances** are prepared for SIM swaps.
- **The webhook guard checks every channel's connection.**

---

## V. What should remain unchanged

- **The brain:** one central brain and one prompt template; prices only from uCRM; one knowledge base;
  `ReplyPrivacyGuard`.
- **The existing numbers:** the behaviour of the three current numbers (routing, prompts, sends), proved by golden tests.
- **Business messages on business numbers:** invoices, receipts, quotes, OTP and job messages stay on support/accounts.
- **Routing safety:** unknown instances are refused, never defaulted.
- **Identity rules:**
  - customers are never attributed by phone;
  - identity is global and strict;
  - ambiguous identity reveals nothing;
  - colleagues' numbers are not answered.
- **Permissions:** WhatsApp sending in the staff app stays admin-only.
- **Already-built features:** Customer Installation Authorisation (5.18.83–5.18.85), the booking WhatsApp, cashbook,
  photos.
- **Other tenants and systems:** South Sudan; Domain B; uCRM as the source of truth.

---

## W. Decisions needed from the operator

| # | Decision |
|---|---|
| D1 | Are retailers companies (`dist_partners`), individuals, or outlets of a partner? Does "authorised person" (docs/49) mean salespeople, retailers, or both? |
| D2 | When a customer of A writes to B: notify A, redirect, or let B serve? On transfer, does B see A's past conversation? |
| D3 | What the AI says about who it is on a person's or a retailer's number (persona wording, retailer branding). |
| D4 | Hand-over target per channel: the owner's handset only, the owner and the central team, or the central team for some topics (payments, complaints)? Pause length per channel? |
| D5 | Do leads from an owned channel belong to its owner and stay out of the 72 h workload reassignment? One ownership model for staff and partners (§J option a)? |
| D6 | Territory exclusivity for salespeople, given `area_key` is unique across the whole business. |
| D7 | Who sees what: does a salesperson see only their portfolio? Does a sales manager see everything? |
| D8 | A STOP on one number: all numbers (today) or that number only? |
| D9 | A disabled channel: refuse inbound, or store and alert without the AI? |
| D10 | Do quotations for a channel's customer go out from that channel or from support? |
| D11 | The approval boundary for creating Evolution instances on the production server (recorded as Domain A's gateway). |
| D12 | Webhook authentication per channel: a per-channel secret, or confirming with Evolution. |

**Decided 07 Oct, for salespeople's own numbers.** The operator, adding a salesperson (twenty in all), answered three of
these for a number that belongs to one salesperson:
- **D4 — *"AI, hand-over to that person (Recommended)"*.** The assistant answers on that number, and a hand-over goes to
  that salesperson. Still open: the pause length, and whether some topics (payments, complaints) go to the central team.
- **D5 — *"That salesperson, never moved (Recommended)"*.** A lead from that number belongs to that salesperson and is
  never reassigned, so the 72 h workload reassignment leaves it alone. Still open: whether partners' numbers follow the same
  model (§J option a).
- **D7 — *"Own chats and leads only (Recommended)"*.** A salesperson sees only their own chats and leads; admins and
  managers see everything.

None of it is built. It binds the design of the next batch: the screen that adds a number, the owner's hand-over, owned
leads and own-only visibility. The new salesperson's Evolution instance is the intended pilot once it is connected (on
07 Oct the WhatsApp settings screen showed it *close*). Batch 1's registry, built and not deployed, is the foundation.

Also outstanding from docs/49: WS-B's onboarding, messaging policy, cost and rollback approvals.

---

## X. Read-only checks for the server

1. **How many instances exist, and which serve sales, support and account.** Inside the uCRM container, from the plugin
   directory:

   `php tools/set_evolution.php`

   With no arguments it changes nothing. It prints:
   - the Evolution URL;
   - whether the key is set (its length only);
   - the three mapped instance names;
   - every instance Evolution holds, with its state and phone.

   Send back the instance names and their states; the phone column can be left out.
2. **Set / not-set questions.** For SEC-1 to SEC-3 and the open values in §Y, a dedicated read-only census script should
   be the first deliverable of the next batch: prepared, rehearsed and pinned like the deployment census (docs/123). It
   would print only "set" / "not set" and counts — never a value.

---

## Y. Not determinable from the repository

- **Evolution mapping:** the production values of `evo_instance_sales`, `evo_instance_support`, `evo_instance_account`,
  `evo_instance_name` and `evo_accounts_instance_name`; whether support and account share an instance (§X.1).
- **Switches and destinations:** whether the SQLite settings row holds the Evolution key (SEC-1 — answered 07 Oct: it
  does, §O.3); `wa_sync_enabled`, `wa_webhook_secret` set or not, `wa_bot_enabled` / `wa_auto_reply_enabled`;
  `ai_lead_capture` and `ai_crm_lead_sync` (answered 07 Oct: lead capture ON in both copies, the uCRM write OFF in both,
  switched off by the operator before 5.18.87's deploy — `docs/07`); `alert_whatsapp`; `distributors_enabled` (the
  pilot: ON, read at every deploy).
- **Data:** lead counts by source and assignee; staff rows by role; distributor rows.
- **The Evolution server:** version, per-instance tokens, capacity, and whether it is shared with the South Sudan tenant.
- **Business rules:** D1–D12.

---

## Z. Batch 1 — built in development (5.18.86): the three numbers made safe, and the registry foundation, dark

**Status: BUILT and proved in development only. Pushed 07 Oct (`b1865ea`, docs/07); NOT deployed. No production flag set, no Evolution instance
created, no number connected, no real message sent, no production data read or written.** Approved on 06 Oct as
*"MULTI-NUMBER SALES — BATCH 1 / FOUNDATION + EXISTING ROUTING SAFETY"* (parts A–R). The commit and every number
below are recorded in docs/07.

### Z.1 With the flag OFF — the production default — exactly two behaviours change

1. **The Inbox fix (Part A, approved by name). Uganda only.** Every Inbox send chose its sender with one line,
   `channel === 'accounts' ? 'accounts' : 'support'`, so a sales conversation — and an `account` conversation, which is
   not `accounts` — was answered from the support number. `lib/InboxReplyRoute.php` now decides, for all four send
   actions (`wa_send_reply`, `wa_send_document`, `wa_send_image`, `wa_send_media`):

   | Conversation channel | Before (5.18.85) | Uganda, 5.18.86 |
   |---|---|---|
   | `sales` | support number | **the sales number** (`NotificationService::sendOnChannel`) |
   | `account` | support number | **the account number** |
   | a registry channel id | support number | **its own number**, or not sent when it has none (flag off: always not sent) |
   | `support` | `sendVia('support')` | unchanged |
   | `accounts` (notification thread) | `sendVia('accounts')` → account number | unchanged |
   | `web`, `marketing`, empty | `sendVia('support')` | unchanged |

   The channel route takes the steps of `sendVia()` that apply (the number's WhatsApp form, the opt-out check against
   this channel, dry run, the rate limit, the Message Log row, the echo claim) and refuses three: **no other number and
   no WASender** (a channel that cannot send sends nothing, and the person in the Inbox is told — 502 with *"Not sent on
   the sales number … Nothing was sent from any other number."*, or *"May have been sent … check the chat"* when
   WhatsApp did not answer); **no failure queue** (its retry goes through `sendVia()` and would send from support); **no
   conversation-store copy** (the Inbox stores the message once, in its own conversation, now with the WhatsApp message
   id, so the echo dedupes on the row as well). Support and accounts keep their `sendVia()` path and its reporting
   exactly. **South Sudan keeps the 5.18.85 line verbatim.**
2. **The event processor (Part C). Uganda only.** See Z.5; South Sudan keeps the 5.18.85 loop exactly.

Everything else runs the 5.18.85 code: `EvolutionApiService::forStore()` — now used by the webhook, the AI worker, the
media worker, the follow-up sender and the Inbox's channel route — returns exactly `new EvolutionApiService($config)` when the flag is off, and
when the flag is on anywhere but Uganda (proved for four configuration shapes, including the legacy gap-fill keys and
a shared instance). Migration 087 adds two tables and three seed rows that no running path reads while the flag is off
(only the read-only `tools/channels.php`, when someone runs it).

### Z.2 With `multi_number_channels_enabled` ON — Uganda only (dark: nobody has set it)

- **Registry (Part D, migration 087).** `wa_channels` holds the columns of Part D plus CHECKs (role, owner type and the
  matching owner id, status, portfolio, hand-over, AI switch), a case-insensitive unique instance and a unique business
  number. `wa_channel_log` is the append-only trail (UPDATE and DELETE refused by trigger); a channel is retired, never
  deleted (trigger). **The three numbers are seeded under their present ids** — `sales`, `support`, `account`, the very
  strings in `wa_conversations.channel` — **with no instance stored**: their instance stays in the configuration keys
  and their legacy fallbacks, read through `EvolutionApiService::configInstanceMap()` (the constructor's own rule, now
  shared). No production value is guessed; a department row carrying an instance is ignored. Nothing in 001–086 is
  touched; no conversation, message, lead or event is rewritten.
- **Resolver (Part F).** Exact, case-insensitive instance match. The first department naming an instance owns it for
  inbound, as the constructor always had it — and when that department is not active the instance is **refused, never
  handed to the next department sharing it**. A channel that is not `active` (paused, disabled, retired) is refused in
  and out. An unknown instance is unknown. A registry channel naming a department's instance is never routed and is
  logged once. An unreadable registry leaves the three department numbers routing as configured and routes nothing
  else, said once.
- **ChannelContext (Part G).** `lib/ChannelContext.php`: the instance and the business number are server-side only;
  `forBrain()` is `role`, `persona`, `territory`, `portfolio` — no instance, no number, no owner id.
- **Inbound (Part H).** instance → registry → channel id → conversation `(phone, channel id)`. A switched-off number is
  answered `channel_disabled` (an unknown one, as before, `unknown_instance`); neither stores anything. A number with the
  assistant off stores the message and its STOP, and queues nothing (no `ai.reply`, no `ai.media`). The `ai.reply`
  payload keeps its exact shape; its `whatsapp_instance` is used only for the check below.
- **Outbound (Part I).** The AI worker, before any send (the typing indicator included), asks
  `EvolutionApiService::replyRoute()`: the channel known and active, its assistant on, and its instance **the instance
  the message arrived on**. On a mismatch, an unknown or a switched-off channel **nothing is sent** — no reply and no
  holding line — the conversation goes to `needs_human`, `wa.escalation` is queued and the staff alert goes; with the
  assistant off the event is settled quietly. The dead-letter hand-over sends its holding line only on a confirmed
  route. The media worker checks the same route before fetching anything; refused, the row is settled `skipped` /
  `channel_refused` and a person is told with no holding line. Follow-ups leave only on their own conversation's
  number: a paused number keeps an approved follow-up waiting, a disabled or retired one closes it (`cancelled`).
- **Brain (Part J).** One brain. The worker passes the channel's **role** where it passed the department (for the three
  numbers the same string), so a second sales number gets the sales behaviour, the sales knowledge and the uCRM prices,
  with this conversation's history only. Proved: the same question on the department sales number and on a second
  sales number produces the identical context and the identical system prompt. **BrainContext gains no key:** its rule
  is that a key earns its place by changing what the model does, and persona and territory have no approved wording
  yet (D3). They wait in `ChannelContext::forBrain()` for Batch 3.
- **Leads (Part K).** With the registry on, a lead records `channel_id`, `channel_role`, `source_number` (the registry's
  number, null until verified), `channel_owner_type`, `channel_owner_id`, `territory_region_id` — beside the
  `conversation_id` every WhatsApp lead already carried, which is unchanged. Set on creation; on an
  existing lead filled in only where missing and only from the lead's own conversation (first touch keeps the lead).
  `crm.lead.sync` carries `channel_id`. Assignment is unchanged (the existing round-robin; D5 is open). The uCRM sync
  writes its own keys and never these (proved).
- **No admin UI (Part M).** The registry's methods and `tools/channels.php` (read-only: the switch, every channel, where
  its instance comes from, whether it routes, `--resolve <instance>`, `--trail <channel>`; numbers masked to two
  digits, never a key). The flag is listed by `tools/set_config.php`.
- **Nothing touches Evolution itself (Part N):** no instance created, no webhook registered, no setting changed.
  `cron/wa_webhook_guard.php` still registers the three configured instances only (Batch 2).

### Z.3 Decisions taken inside the instruction — and those left open

- **D9 (a disabled channel):** refused inbound, as Part F orders (*fail closed for unknown and disabled*). "Store
  without the AI" exists as the per-channel `ai_enabled = 0`; it does not alert anyone. Revisit with D4.
- **A new channel is created `disabled`**: switched on deliberately, never by being created.
- **Department notifications do not consult the registry.** `sendVia()` (OTP, invoices, alerts…) still addresses a
  department sender. Only sends that belong to a conversation route by channel. A department number switched off in
  the registry therefore stops its conversation replies, its inbound and the AI on it, but not its notifications.
- **Staff alerts leave from the `sales` number (`AlertService`) — with one difference, recorded for Batch 2.** The
  hand-over's alert, raised by the AI and media workers, is sent through their registry-aware service, so with `sales`
  switched off in the registry that one alert is not sent (it is recorded as failed; the conversation is still marked
  `needs_human` and `wa.escalation` still queued). The watchdog, webhook-guard, web-chat and Starlink-mail alerts build
  their own service and keep sending on the configured number, as department notifications do. Dark: with the flag off
  every alert is exactly as in 5.18.85. Which number staff alerts leave on once the department numbers are managed in
  the registry is for Batch 2 to decide.
- **The legacy support/account context object** still carries `whatsapp_instance` and `customer_phone`, as it has since
  before 5.18.85 (BrainContext's B3.5 migration debt). Neither is rendered into the prompt, and the external ShopBot
  payload's contract excludes both. Unchanged here because the golden test requires it.
- **Left open, untouched:** D1–D8, D10–D12; persona wording (D3); owner-based lead assignment (D5); portfolio
  visibility (D7); per-channel webhook authentication (D12).

### Z.4 Part B — the Batch 0 lead path, verified against the current code, not copied

- `workers/AiReplyWorker.php` passes `$context` to `latestPin()` (the defect was `$ctx`); unchanged by Batch 1, and the
  Batch 0 weakened copy still finds its anchor.
- `workers/UcrmLeadWorker.php` reads the payload through `payloadOf()`; unchanged.
- `tests/test_lead_path_batch0.php`: 25/0. Batch 1's own lead proof (routing test, part L) runs the chain with the flag
  off and on: WhatsApp AI lead → capture → `crm.lead.sync` → `UcrmLeadWorker` → a uCRM client per lead, both linked.
- No production flag was changed: `ai_lead_capture` and `ai_crm_lead_sync` keep whatever production holds.

### Z.5 Part C — the event processor never swallows a worker's event

`cron/event_processor.php` claims every type, and two worker-owned ones were lost through it: `crm.lead.sync` was not
on its list, so this 30-second loop could acknowledge a lead's sync as an unknown type before `UcrmLeadWorker` saw it;
and `ai.reply` / `ai.media` were claimed and released, so a backlog of more than twenty (priority 3, sorted first)
starved every type it does handle. Now:
- `EventBus::consume()` takes an optional exclusion list (additive; every existing caller passes none);
- the processor excludes `ai.reply`, `ai.media`, `crm.lead.sync` in SQL, and still releases one if it ever sees it;
- `wa.escalation` — emitted by the hand-over after it has already acted, consumed by nothing — is acknowledged as a
  known type instead of being logged as unknown (protecting it would leave it pending for ever);
- the early `return` on an empty claim is gone: an install whose only traffic is AI events never reached the
  dead-letter pass (found by the new test).
Success, transient failure, retry and dead letter are proved against the real `UcrmLeadWorker` and a fake uCRM.
**Uganda only.** The processor is shared code, and South Sudan's behaviour may not change: there it still claims every
type, releases `ai.reply` / `ai.media`, logs `wa.escalation` as unknown and returns early on an empty claim — proved by
South Sudan control scenarios beside the Uganda ones, and by a weakened copy that ignores the country gate.

### Z.6 Recorded, not fixed

- **SEC-1 … SEC-5 (§O.3): untouched, all five still open,** each awaiting its own approval. **Batch 1 adds no credential
  exposure:** the registry stores no credential (the migration names none, asserted); nothing it logs carries the
  Evolution key or a whole number (asserted); the CLI prints neither (asserted); Inbox error texts name no instance
  (asserted); the brain is never given the instance or our number (asserted); the external ShopBot contract is unchanged.
- **South Sudan's event processor keeps its two defects** — `crm.lead.sync` can be acknowledged as unknown before
  `UcrmLeadWorker` sees it, and a backlog of AI events starves the processor's own types — and the early return that
  skips the dead-letter pass on an empty run. Fixing them there changes South Sudan's behaviour: its own decision.
- **`efris.submit`** is owned by `EfrisWorker` (`cron/efris_sync.php`) yet acknowledged by the event processor as an
  unknown type. Left as it was (*do not modify unrelated event types*). Mitigated today: the sync runs only with
  `efris_environment = test`, and its scan re-queues any invoice still unsubmitted.
- **`wa.escalation` has no consumer.** Acknowledged as known (Z.5); a consumer is for D4.
- **`wa_send_quote_pdf`** (the KYC quotation sender) names no conversation and still sends from support.
- **`cron/wa_webhook_guard.php`** is not registry-aware: a registry instance's webhook is not watched (Batch 2).
- **`ConversationService` unread counts** cover `support`, `web`, `accounts` only; `sales`, `account` and registry
  channels are not counted there (display only).
- **An Inbox reply on a `support` or `accounts` conversation is stored twice** — the Inbox's row and `sendVia()`'s
  conversation-store copy — as before 5.18.86 (measured: two rows, one carrying the message id); the channel route
  stores it once.
- **`event_processor` `ticket.status_changed`** calls `$splynx->isConfigured()` on null when Splynx is not configured.
- **[SERVER?] Whether the Inbox can see the Evolution connection.** The Inbox's send path reads the SQLite settings
  row (`public.php`), not the settings files the webhook and workers read. If that row lacks the Evolution URL or key,
  a support reply has been reported *sent* while nothing left (`sendVia()` finds no transport and returns quietly);
  with 5.18.86 a sales or account reply says *"WhatsApp (Evolution) is not configured here"* instead. The same
  question as SEC-1; to be read on the server before any deployment (§X.2). **Answered 07 Oct, 04:25 UTC, before
  5.18.86 was installed, by its deploy script (A3, yes/no only): `evo=yes sales=yes support=yes account=yes
  registry=absent`.** The row reaches Evolution, names all three instances, and leaves the registry switch unset.
  Whether each instance is connected on Evolution is not read (§X.1).

### Z.7 Rollback

Nothing is deployed, so nothing in production needs rolling back. Before deployment, the registry is undone by leaving
the flag off; its tables are inert. The two always-on changes (Z.1) are code: undoing them means deploying 5.18.85
again. Migration 087 is additive and its tables may stay.
