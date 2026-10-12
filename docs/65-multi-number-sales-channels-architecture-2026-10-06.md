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
these for a number that belongs to one salesperson, and D3 after 5.18.88's deploy:
- **D4 — *"AI, hand-over to that person (Recommended)"*.** The assistant answers on that number, and a hand-over goes to
  that salesperson. Still open: the pause length, and whether some topics (payments, complaints) go to the central team.
- **D5 — *"That salesperson, never moved (Recommended)"*.** A lead from that number belongs to that salesperson and is
  never reassigned, so the 72 h workload reassignment leaves it alone. Still open: whether partners' numbers follow the same
  model (§J option a).
- **D7 — *"Own chats and leads only (Recommended)"*.** A salesperson sees only their own chats and leads; admins and
  managers see everything.
- **D3 — *"As [the salesperson]'s assistant (Recommended)"*, decided 07 Oct after 5.18.88's deploy.** On a number that
  belongs to one salesperson, the assistant says it is that salesperson's assistant at DishNet. It names them by their
  first name and **never claims to be them**, for example: *"Hi, I'm <first name>'s assistant at DishNet. <First name>
  will follow up with you personally."* Hand-overs go to that salesperson (D4). This keeps the assistant's standing rule
  never to impersonate a person. Branding on a retailer's number (D3's other half) stays open.

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

---

## AA. Batch 2 — a salesperson's own number (design, 07 Oct; to be built dark)

**Where it starts.** Batch 1 is in production since 5.18.88 (07 Oct, 09:52 UTC), dark: migration 087's three department
rows, the routing by channel behind `multi_number_channels_enabled` (OFF), and Uganda's event processor leaving the
workers' events alone. The registry's writers exist (`ChannelRegistry::create`, `setStatus`, `setAiEnabled`,
`setInstance`, each one row change and one trail row in one transaction) but nothing calls them. The first salesperson's
Evolution instance exists, not connected. Batch 2 makes that number work end to end, behind the same switch.

**Decisions in force** (§W):
- **D3** — on the salesperson's number the assistant is *that salesperson's assistant at DishNet*. It names them by first
  name and never claims to be them.
- **D4** — a hand-over alerts the salesperson. **Decided 07 Oct, with the build:** a copy goes to the central alert
  number as well (*"[the salesperson] + copy to central (Recommended)"*), so nothing is missed while the salesperson is busy or off.
  Alerts are sent from the DishNet sales number, never from the salesperson's own (§L).
- **D5** — a lead from the number belongs to the salesperson and is never moved by the automatic distribution.
- **D7** — a salesperson sees only their own leads; admins and managers see everything. **Decided 07 Oct, with the
  build:**
  - **who is a manager** — admins, plus anyone granted the existing **All Leads** permission (*"Admins + 'All Leads'
    grant (Recommended)"*). There is no manager role to add.
  - **chats** — on the salesperson's phone only (*"On [the salesperson]'s phone only (Recommended)"*). Their WhatsApp shows every
    message, the assistant's replies included. In the plugin, chats stay admin-only as today, so no new chat view is
    built.

### AA.1 What is built

1. **Salesperson numbers, on the WhatsApp AI screen** (`tabs/engage/wa_ai_setup.php`, admin only, the screen's own CSRF
   check). One new card lists every registry channel (the business number masked, `ChannelRegistry::mask`) with its
   owner, status, the assistant's switch and the last trail rows. Its actions, each written through `ChannelRegistry`
   with the admin's name and a reason:
   - **add** — an Evolution instance that is no department's and no channel's, a salesperson (an active staff row with a
     sales role), a display name. The channel id is generated (`sales-<n>`), the role is `sales`, the owner `staff`,
     `handover_to = owner`, `portfolio_scope = own`. The status is **`disabled`**: a number is switched on deliberately,
     never by being added.
   - **pair** — Evolution's QR code (or pairing code) for that channel's instance. Today the screen can show a QR only for
     a department's instance.
   - **verify** — reads the number from Evolution's own report for that instance (`fetchInstances`, the paired account),
     **never a typed value**. It writes `business_number`, `verified_at` and `verified_by` (new: `verifyNumber()`), and is
     refused while the instance is not connected.
   - **webhook** — registers the plugin's webhook on that instance (the screen's existing call, now for a channel).
   - **assistant on/off, activate / pause / disable / retire** — `setAiEnabled`, `setStatus`.
   - **new owner** — for a salesperson who leaves (new: `setOwner()`, staff owners only; the trail keeps the previous one).
2. **The assistant on the number (D3).** For a staff-owned channel the worker passes the owner's first name (from their
   staff record, letters only, at most 30 characters) to the brain as `line_owner`. It is a new `BrainContext` key; the
   external brain's payload carries it too. `DishNetAiBrain::identityHeader` then says the assistant is that person's
   assistant at DishNet, is not them, never signs as them, and that they will follow up personally. Rule 4 ("never
   reveal staff names") gains one exception: the line owner's first name, on their own line. Without an owner, the
   prompt is byte-identical to today's.
3. **The hand-over (D4).** On a channel whose `handover_to` is `owner`, the hand-over alert goes to the owner's phone (their
   staff record), sent from the DishNet sales number, with its own 30-minute cooldown per conversation. The copy to the
   central alert number keeps today's key and wording, plus the line's name. Config `wa_handover_copy_central`, default
   ON, records the decision. An owner with no phone on record: central only, and a log line says why. The assistant's
   pause is unchanged: it stands down for `wa_human_cooldown_minutes` once a person replies, from the handset or the
   Inbox.
4. **Owned leads (D5).** A new AI lead captured on a staff-owned channel is assigned to the owner at capture
   (`assigned_to`, `assigned_name`, `assigned_at`, `assigned_by = channel:<id>`, a history row). Then:
   - `cron_leads.php`'s drip and its 72 h reassignment, and the admin's smart distribution, leave such a lead alone;
   - an admin's explicit assignment of one lead still works, and is recorded in its history;
   - the existing lead alerts (`cron_lead_alerts.php`) notify the assigned owner, as for any assigned lead;
   - an existing lead found for the customer keeps its owner (D2 is open: nothing is taken from anyone).
5. **Own leads only (D7)** — behind its own switch, `sales_own_leads_only`, **default OFF**, because it changes what
   every salesperson sees. ON: for a viewer who is not a manager (admin, or holder of `all_leads`), Sales → Leads lists,
   opens and acts only on leads assigned to them, created by them, or on their call rota for today. The same rule binds
   the status change (`update_lead_status`) and the call log (`log_call`). A refused lead is answered as not found.
6. **Follow-ups on an owned number** — `cron/followup_send.php` holds automatic follow-ups on a staff-owned channel
   unless `wa_followups_on_owned_numbers` is set (default OFF). The assistant has told the customer the salesperson will
   follow up personally, and every automated send from a person's number adds to its ban risk (§L).
7. **The webhook guard** — with the registry on, `cron/wa_webhook_guard.php` keeps the plugin's webhook registered on
   every active channel's instance, not only the three departments', and alerts when one is disconnected.

### AA.2 Defaults recorded, not asked

- **D8** — a STOP still applies on every number (today's rule).
- **D10** — invoices, receipts, quotes and job messages stay on the business numbers (§V).
- **D11** — the operator pairs the salesperson's existing instance on the screen after the deploy; the plugin creates no
  instance.
- **D12** — one webhook secret serves every number for the pilot. The risk (§O.2) is recorded and per-channel secrets are
  left for later.
- **D2** — open. A customer's existing lead keeps its owner.
- **D6** — no territory on the pilot number.

### AA.3 Dark, and how it goes live

- Everything in AA.1 acts only with `multi_number_channels_enabled` ON on a Uganda install. Two exceptions:
  - the screen may prepare a channel row while the switch is off — the row is `disabled` and nothing routes to it;
  - own leads only has its own switch (item 5).
- South Sudan and Domain B are untouched.
- **Going live** — each step the operator's, after the deploy:
  1. add the salesperson's number on the screen, then pair it, verify it and register its webhook, with the row still
     disabled;
  2. switch the registry ON with the three department numbers alone, and compare their traffic (§Q phases 3–4);
  3. activate the salesperson's channel, with staff-only test contacts first;
  4. the pilot (§Q phases 5–6).

  The rollback for the number is `disable`; for the registry, the switch OFF.

### AA.4 Not in Batch 2

- retailers' and partners' numbers;
- territory;
- per-channel webhook secrets;
- an in-plugin chat view for salespeople;
- owner notifications beyond the hand-over and the existing lead alerts;
- the media layer.

## AB. Batch 2 as built (5.18.89, development, 07 Oct) — dark; NOT pushed, NOT deployed

Built on the development branch as §AA designed it, behind the same switch, `multi_number_channels_enabled`, which stays
OFF. Where the build went beyond the design, or stops short of it, the item says so.

### AB.1 What is built

1. **The screen** — a *Salesperson numbers* card on the WhatsApp AI screen (`tabs/engage/wa_ai_setup.php`); its rules
   live in `lib/SalesNumbersAdmin.php`. It shows on Uganda wherever migration 087 has run, with the registry switch on
   or off, and never on South Sudan. Admin only: the screen is, every action checks again, and every form carries the
   screen's own CSRF check. The card lists every registry number — the three departments first, read-only (*"changed
   under Numbers"*) — with the business number masked, the owner, the status, the assistant's switch, Evolution's state
   and the last three trail rows. Its actions, each through `ChannelRegistry` with the admin's name and a reason:
   - **add** — an instance Evolution reports and nobody uses (no department's in the configuration, no other number's),
     an active salesperson (role `sales`, `sales_staff` or `field_agent`) and a display name (default *"Sales —
     [their name]"*). The id is `sales-NNN`, the next free number; ids are never reused, because a channel is never
     deleted. Role `sales`, owner `staff`, `handover_to = owner`, `portfolio_scope = own`, status **disabled**.
   - **pair** — Evolution's QR code (and pairing code) for that number's instance.
   - **verify** — the number Evolution reports for that instance (`fetchInstances`), only while it is connected,
     through the new `ChannelRegistry::verifyNumber()`. It writes the number, `verified_at`, `verified_by`, and a trail
     row with the number masked. Never typed.
   - **webhook** — the plugin's webhook on that instance. The address and its secret go straight to Evolution and appear
     on no page.
   - **switch on** — refused until the registry switch is on, the number is verified and its owner is an active staff
     member: the go-live order of §AA.3, enforced.
   - **pause, switch off, retire** — retiring needs a ticked confirmation, and a retired number takes no further change.
   - **assistant on/off** and **new owner** — the new `ChannelRegistry::setOwner()`, a salesperson only. The trail
     keeps the previous owner.

   *Found in Evolution* now marks an instance a salesperson's number uses as *in use*, so it is never offered to a
   department. That card still shows an admin each instance's own number, as Evolution reports it, as it did before.
2. **The assistant (D3).** `lib/LineOwner.php` finds the owner of a staff-owned, active number whose owner is an active
   staff member with a usable first name. The AI worker asks once per turn, and only with the registry on.
   - `line_owner` is a new key in `BrainContext` and in the external brain's payload (`ShopBotPayload`). It holds the
     first name only: one word of letters, apostrophe and hyphen, at most 30 characters. `DishNetAiBrain` checks it
     again.
   - The identity line then says the assistant is [the salesperson]'s assistant at DishNet. It is not them, never signs
     as them, and promises nothing on their behalf except that they will follow up personally. Rule 4a lets it use that
     one name.
   - WhatsApp only, never by e-mail or in the website chat. The identity line and rule 4a read one answer, so they
     cannot disagree.
   - **Without an owner, the prompt is byte for byte the brain's before Batch 2.** 24 prompts were compared against
     `7195253`'s brain.
3. **The hand-over (D4)** — `AlertService::handover()`.
   - **No owner:** exactly the old alert — the same key, the same words, the central number.
   - **An owner:**
     - the salesperson's phone, under key `escalate:conv:N:owner` with a 30-minute cooldown;
     - and the copy to the central number naming the line. That copy is `wa_handover_copy_central`, default ON; an
       empty value counts as absent.
   - **No phone on record:** the central number, and a log line saying why.
   - Both are sent from the DishNet sales number. This happens only on a number whose `handover_to` is `owner`, which
     the screen sets.
4. **Owned leads (D5).**
   - **At capture:** a NEW lead from a salesperson's number is assigned to them, with `assigned_by = channel:<id>` and a
     history row.
   - **The rule:** `lib/OwnedLead.php` protects the lead while it came from a staff-owned number, is still with that
     owner, and the owner is active.
   - **What leaves it alone:**
     - `cron_leads.php`'s 72 h reassignment and 48 h warning skip it, and say how many they skipped;
     - the admin's smart distribution leaves it out;
     - **the Leads page's daily rota puts it on nobody's call list.** The design did not name the rota; without this it
       would hand the lead to other callers every day.
   - **Deliberate moves still work** — the admin's `assign_leads` and the leave cover (`reassign_agent_leads`).
     `assign_leads` now writes a history row when an owned lead leaves its owner; the leave cover always wrote one.
   - **An existing lead** keeps whoever has it (D2 stays open).
   - **The lead alerts** (`cron_lead_alerts.php`) treat it as any assigned lead: the owner is told it is theirs, then
     warned at 45 minutes, and the supervisor at 60 if no call is logged. **For the pilot:** a salesperson who answers on
     WhatsApp without logging a call will trigger that 60-minute escalation.
5. **Own leads only (D7)** — `lib/LeadVisibility.php`, behind `sales_own_leads_only` (default OFF; Uganda only).
   - **A manager** is an admin, or anyone with All Leads by the page's own rule: an RBAC grant, the person's module list,
     or the role default.
   - **ON:** a salesperson sees and acts on a lead only if it is assigned to them, created by them, or on their call list
     today.
   - **Where it is checked:**
     - the Leads page — every list, every count, and a lead opened by id; the check runs after the rota has saved;
     - the quote page's lead picker and the More menu's count;
     - `save_lead`, `send_lead_quote`, `update_lead_status` and `convert_lead`;
     - the call log (`log_call`).
   - **A refused lead** is answered *"Lead not found."* (404 on the API).
   - **Beyond the design:** the design named only the status change and the call log. The quote picker, the menu count
     and the other three handlers had the same leak.
6. **Follow-ups (§AA item 6)** — `lib/OwnedNumberHold.php`. With the registry on, every salesperson's number is held
   unless `wa_followups_on_owned_numbers` is set, **at all three steps**:
   - the scan never opens one — those chats are left out of its query, so they take none of its 25 places;
   - the drafter closes an open one before any model call;
   - the sender closes an approved one before any send.

   The departments are never held. A registry that cannot be read holds every number but the departments.
7. **The webhook guard.** With the registry on it also watches every ACTIVE number's instance. A paused or switched-off
   one is not re-registered, and the disconnected alert names the number. Registry off, or unreadable: the three
   departments, exactly as before.

**Switches** (`tools/set_config.php`):
- `wa_handover_copy_central` — absent means ON;
- `wa_followups_on_owned_numbers` — absent means OFF;
- `sales_own_leads_only` — absent means OFF.

`multi_number_channels_enabled` stays OFF.

### AB.2 Known, and not done

- **The media worker's hand-overs** still alert the central number alone. They exist on the development branch only;
  production has no media layer.
- **A staff row with no `is_active` field:** `cron_leads.php` counts it as active, `OwnedLead` does not, so a lead owned
  by such a row is not protected. Every row the plugin writes carries the field.
- **Counts outside the Leads area** still count every lead — the staff API's LTE dashboard. Counts only; no lead is
  shown.
- **The call-recording upload** (`upload_call_recording`) is not checked. It stores a file against the lead id the phone
  app sends, and reads nothing from the lead.
- **Pre-existing, observed, not changed:** the Leads page's add/edit form posts actions (`add_lead`, `update_lead`) that
  no handler answers.
- **The salesperson is not told** when their number disconnects; the guard alerts the central number.
- §AA.4 stands.

### AB.3 Tests

- **`tests/test_sales_numbers.php`** (new) covers:
  - in-process facts: the two writers, `LineOwner`, the persona, the hand-over, the lead's owner, `OwnedLead`,
    `LeadVisibility`, the hold and the screen's service;
  - the golden prompts against `7195253`;
  - the real plugin under `php -S`: the pages, the handlers, the API, the crons, the screen through its forms, and the
    worker end to end;
  - South Sudan with every switch set;
  - 17 weakened copies, each caught.
- **`tests/test_multi_number_routing.php`** — two assertions rewritten to the new truth, not deleted. Its second sales
  number is a salesperson's, so:
  - *"one brain"* now allows exactly the persona;
  - its follow-up is held unless the switch lifts the hold.
- **South Sudan, found by the full suite.** `test_staff_jobs_south_sudan` compares South Sudan's pages with 5.18.49's,
  byte for byte. It found the WhatsApp AI setup page one byte longer: the card's block is skipped on South Sudan, but a
  blank line after its `endif` still reached the page. The blank line is gone, and the page is identical again.

## AC. The salesperson pilot (07 Oct evening): the decisions, the acceptance test, the runbook — nothing changed in production

**Where it starts.** 5.18.89 is in production since 07 Oct, 19:02 UTC (docs/07), dark:
- `multi_number_channels_enabled`, `sales_own_leads_only` and `wa_followups_on_owned_numbers` are not set;
- the registry holds the three department rows alone.

The operator asked for the salespeople's lines to answer from their own numbers, staged: one pilot proved end to end,
then the others, one at a time. **Production was not touched for this section**: no setting, no number, no message, no
deploy.

### AC.1 Decided on 07 Oct evening (the operator's answers; nothing settled before was reopened)

- **Follow-ups on a salesperson's number: sent from their number** (*"Send from their number (Recommended)"*).
  `wa_followups_on_owned_numbers` is set when the pilot is activated, which replaces §AA item 6's default (held).
- **Own leads only (D7): with the pilot** (*"With the pilot (Recommended)"*). `sales_own_leads_only` is set before the
  pilot number goes live.
- **The pilot salesperson:** chosen by the operator. Documents say *[the salesperson]*.
- D3, D4 (the owner plus a copy to central), D5, D7's manager rule, D8, D10, D11 and D12 stand. D2 stays open; D6 is
  still "no territory".

### AC.2 The pilot needs no code change

5.18.89 already carries every part the pilot uses (§AB.1):
- the card;
- the routing by channel;
- the persona;
- the owner's hand-over;
- owned leads;
- own leads only;
- the follow-up switch;
- the guard.

Read against the code and then proved by the test in AC.4, the gaps were **in the tests only**:
- two salesperson numbers at once;
- the assistant's photo and document on a salesperson's number;
- an instance that cannot send.

The registry can be switched on safely for the pilot alone: an added number stays switched off until it is switched on,
and the department numbers behave the same with the registry off and on (AC.4, O and P).

### AC.3 Found while proving it — facts the runbook depends on

1. **The colleague rule covers admins, support and accounts only** (5.18.50, `StaffDirectory::STAFF_ROLES`). WhatsApp
   from such a number is kept for the team and never answered; a salesperson's phone is answered like a customer's. A
   live test must therefore come from a phone that is not an admin's, support's or accounts' number on record.
2. **The Inbox is not filtered by number.** `wa_inbox_roles` (absent by default: administrators only) lets the named
   roles read every number's chats. Granting a sales role would let one salesperson read another's customers. The
   runbook checks it before the pilot (docs/66, P2), and finding a sales role there is a STOP condition. Sending from
   the Inbox is never grantable.
3. **The hand-over on a salesperson's number:**
   - the customer's one message is the assistant's own hand-over line, on that number;
   - the operator's holding line (`ai_handover_message`) goes out only when the assistant produced nothing or its
     retries are spent — on that number too.
4. **An instance that cannot send** (the phone off, the number logged out):
   - the reply is retried on the same number only — five attempts over about forty minutes;
   - then the salesperson and the central number are told;
   - the customer is sent nothing from any other number;
   - a send that may have gone (a gateway timeout) is never repeated; a person is told at once;
   - the guard names the number on the central number within about ten minutes; the salesperson is not told (§AB.2).
5. **A switched-off number's messages are stored nowhere** (`channel_disabled`). While a number is off, its chats reach
   the salesperson's phone and not the Inbox.
6. **A WhatsApp Business greeting or away message** on the salesperson's phone reads as them typing, and stands the
   assistant down on that chat:
   - shorter than 40 characters, always;
   - longer, until the same words have reached three other chats within seven days.
7. **The full `tools/set_config.php` listing prints the payment settings.** The runbook reads only the five settings it
   needs, with a read-only one-liner. It was tested locally: it prints those five lines and nothing else, and leaves the
   database byte-identical.
8. **Recorded, unchanged:** the support number's brain context carries `line_owner` as an empty value, the same with the
   registry off and on. The brain reads empty as no persona.

### AC.4 The acceptance test — `tests/test_sales_pilot.php` (new)

**What it runs.** The real plugin under `php -S`, a fake Evolution and a fake uCRM, the AI worker in-process with a brain
that never leaves it. The plugin is in production's shape:
- the sales department on its own instance;
- support and account sharing one;
- two salesperson numbers added through the card exactly as the runbook adds one: add, QR, verify, webhook, switch on;
- the three switches set in the decided order.

Every number, name and instance in it is fictitious.

**What it covers** — the instruction's tests A–Q, the pilot checks 1–15 and the rollback:
- **A–D:** each number answers on its own number, as its own salesperson's assistant:
  - two customers on two numbers in one worker run;
  - one customer on both numbers — two conversations, isolated histories, one uCRM identity;
  - the lead assigned to the salesperson, with its channel and its number.
- **E:** the Inbox's text, image and document leave on the chat's own number; a salesperson cannot send.
- **F:** the hand-over line and the operator's holding line on the salesperson's number; the salesperson and the central
  number alerted from the DishNet sales number.
- **G:** a follow-up sent on the salesperson's number, and held without the decided switch. It needs a time zone inside
  the sending window; one was found in every run.
- **H:** the assistant's photo and document on the salesperson's number.
- **I, J, K:** fail closed:
  - a switched-off number;
  - an instance that refuses — the first failure, the retries spent, a possible send, the Inbox, the guard and the
    recovery;
  - an unknown instance, an unknown channel id, and a number moved meanwhile.

  In every case nothing reaches the customer from any other number, and a person is told.
- **L:** own leads only on the pages, the lead opened by id and the call log; the Inbox refused to salespeople; AC.3's
  measured exposure; the brain told nothing of another salesperson's customers.
- **M:** one brain — the same context and prompt on the department number and the salesperson's, but for the persona;
  never the instance, the business number, the channel id or the customer's number.
- **N:** South Sudan with every switch set: nothing changes.
- **O, P:** sales, support and account identical in every observable, in all three states — the registry off, on with
  the departments alone, and on with the pilot:
  - the webhook, the payload, the role, the context and the prompt;
  - the reply's number, the Inbox and the stand-down;
  - account's Inbox threads and notifications;
  - a department hand-over.
- **Q:** the scenario runs on a copy of the plugin without Domain B, and loads nothing from it. At the change-set level,
  `dishnet-mikrotik-control-plane/` and the plugin's `docs/` are identical at `53d5c4d` and on this branch, and this
  work touches neither.
- **Rollback:**
  - R1, the pilot off on the card: nothing stored or sent on it; a queued message handed to a person; the Inbox
    refused; an approved follow-up closed; the departments unaffected; everything kept, the trail one row longer;
  - R2, the registry off: the number unknown; nothing sent for it; the trail unchanged;
  - R3, the two other switches cleared: the lead pages as before;
  - R4, back on: the number answers on its own number again.

**12 weakened copies, each caught:**
- a failed send re-sent on the sales number;
- an unmapped channel borrowing the sales number;
- the registry routing a switched-off number;
- the webhook taking a switched-off number's messages as sales;
- the worker ignoring the route check;
- the Inbox answering from support;
- the follow-up, the photo, the document and the holding line each sent on the sales number;
- account taking the shared instance's inbound;
- own leads only letting everything through.

**Results:** with the final file:
- **the development tree** — 122 passed, 0 failed, in both full suite passes below;
- **the live release's code** — 122 passed, 0 failed, twice. It ran on a copy of `53d5c4d` that differs from it
  only in this test and the fixture, checked with `diff -r` against a pristine extract. There the fixture is the
  release's own, given the same control.
- **the 12 weakened copies** are each caught in every run, on both trees. On the release tree the holding-line copy
  targets the worker, where 5.18.89 sends that line; the development tree has since moved it to `lib/Handover.php`
  (docs/55 §9).

**The fixture.** `tests/fixtures/fake_evo_server.php` gains one test control, `/__test/fail_instance` (an instance that
refuses every send, recorded in `failed_calls`). Nothing it did before changes.

The full development suite ran twice, alone (19:46–20:28 and 20:29–21:09 UTC):
- 287 files, 13,984 passed, 0 failed, 0 skipped, both passes identical file by file;
- against the counted Batch 2 passes (286 files, 13,862 passed): exactly this test added (+122); no file moved, none
  gone;
- the same 5 PHP warnings per pass, all from `test_dpo_endpoints`;
- the comparer refuses its three planted faults.

**What a test cannot prove** — the runbook's live steps do:
- that the SIM is the right one;
- that Evolution pairs and stays connected;
- that WhatsApp delivers;
- how customers respond.

### AC.5 The runbook — docs/66

`docs/66-salesperson-pilot-runbook-2026-10-07.md`, in order:
- **0** — the operator's six confirmations before anything is paired;
- **P** — read-only checks, repeated after each step;
- **1** — the card, with the registry still off;
- **2** — the registry on, with the departments alone, and a watch of their traffic;
- **3** — the two decided switches;
- **4** — the number on, with the assistant off;
- **5** — the assistant on;
- **6** — the Phase 10 checks;
- the STOP conditions;
- **R** — four rollback levels, each on its own;
- salesperson #2, #3, … one at a time, the same steps, no code and no deploy.

### AC.6 What stays the operator's

Every production step:
- the six confirmations;
- how long to watch the departments with the registry on;
- the go for the pilot number;
- the go for each number after it.

No push and no deploy are needed for the pilot. This section, the runbook and the test wait on the branch for the
operator's approval to push.

## AD. The pre-pilot safety fix (5.18.90, development, 08 Oct) — the pilot stopped before step 5; NOT pushed, NOT deployed

**Where it starts.** The operator stopped the salesperson pilot before docs/66 step 5 (the assistant on): two defects
were reported in 5.18.89, and both were confirmed by reading the code before anything was changed. Production was not
touched: no setting, no number, no message, no deploy. The fix is built on the development branch only.

### AD.1 Root causes, measured

**Defect 1 — one DishNet number's assistant could answer another's.**
- Nothing on the inbound path knew DishNet's own WhatsApp numbers. The J8 colleague rule (`evo_webhook.php`,
  `AiReplyWorker::isStaffNumber`) knows **staff** — `retailers.json` rows with `StaffDirectory::STAFF_ROLES` or
  `is_admin` — and deliberately not salespeople, who may be dealers. A line is not a staff member, so a message from
  one DishNet line to another was a customer's message.
- The only thing holding one assistant back was the human pause: a fromMe echo marks the chat as handled by a person
  for `wa_human_cooldown_minutes` (5 in production), while a parked message survives 120 minutes. When the pause ran
  out, the parked message was answered — by an assistant, to an assistant.
- Seeds that need no customer: a person typing from one line's phone to another line; a staff alert
  (`AlertService::sendTo`, from the sales department) to an alert number or an owner's phone of record that is itself a
  line with the assistant on. The central alert's text asks for a human, so the receiving brain escalated again.
- The message-id dedupe (`EvoWebhookGuard`) keys on the WhatsApp message id alone. Whether two instances of one
  install see the same id for one message is **not measured**; the fix relies on it neither way, and the tests run both.

**Defect 2 — follow-ups ignored the number's assistant switch.**
- `FollowUpPolicy::mayAutoSend` takes no channel; `followup_scan`, `_run` and `_send` never read
  `wa_channels.ai_enabled`; `EvolutionApiService::sendText` checked only the opt-out and the instance; and routing gives
  an instance to every **active** channel whatever its assistant switch says.
- The only per-channel barrier was `OwnedNumberHold` (salesperson numbers), which `wa_followups_on_owned_numbers`
  lifts — and the pilot turns that on (§AC.1). From then on, **Assistant off** stopped replies and not follow-ups:
  docs/66 R-1's *"nothing is sent automatically"* was wrong.

### AD.2 What was built — dark: everything new acts only with the channel registry on

With the registry off — production today, every South Sudan install — every path is 5.18.89's, byte for byte in
behaviour (the registry-off parts of `test_pilot_safety` and the unchanged suites prove it).

- **`lib/InternalNumbers.php` (new)** — the one definition of DishNet's own numbers, built from records the plugin
  already keeps; nothing new is typed in:
  - **lines**, refused on every number, the departments included:
    - `wa_channels.business_number` of every row that is not retired;
    - the numbers the configuration already names (`wa_support_number`, `wa_accounts_number`, `web_chat_whatsapp`,
      `evo_number_sales`) — read from the configuration files **and** from the plugin store's `kyc_config`, where the
      Settings form saves them on an SQLite install (as `cron/wa_watchdog.php` reads them);
    - the number Evolution reports for **every** instance, as `cron/wa_webhook_guard.php` last read it (it reads them
      every run anyway). One record **per instance**: one phone may be linked to two instances, and each instance is
      compared with its own row. An instance whose owner Evolution reports with no phone number (an `@lid`) is recorded
      as such — never its digits — and matches no recorded number, so a row verified on it counts as unverified again.
      Membership only — it never counts as a verification;
  - **people on DishNet's side**, refused on a salesperson's number only — a channel the registry routes that is not a
    department; never on a department (J8 stands exactly), a notification thread or a website chat: the phone of
    record of each active owner of a salesperson number, and the alert numbers `alert_whatsapp` and
    `whatsapp_admin_phone`;
  - matching: the whole number, digits only, at least 8 — never the text, a name, a prefix, the time, STOP or the pause;
  - the lines come from the same `wa_channels` read the routing is built from, so they are never "unreadable" while
    anything is routed; the people are read lazily and **fail soft** — a failure adds nobody and is logged once; it
    never refuses or re-files a customer;
  - **completeness** (`gaps()`): each configured department instance has its number recorded on the department that
    takes its inbound, verified **for that instance**. A department moved to another instance is a gap again; so is one
    whose instance Evolution now reports with **another** number (re-paired to another phone).
- **The department numbers are recorded** — `ChannelRegistry::verifyDepartmentNumber`, the card's **Verify number** on
  the sales and support rows (account shares support's instance; one number covers both). The number is the phone
  number Evolution reports for the configured instance, never typed; an instance whose owner is an `@lid` has none, so
  it cannot be verified (on a salesperson's row either) and the gap stays open. The trail gains a `verified_instance` row. Works with
  the registry off, so it is done before the switch. A row that can no longer own the number gives it up, on the
  record, rather than leaving the department unverifiable: a department now covered by another on the same instance, or
  moved since; a retired line. Any other holder still refuses. Every verification on the card also records what
  Evolution reports at that moment, so a number just verified is never contradicted by the guard's older read.
- **Nothing switches on before them:**
  - `SalesNumbersAdmin::status → active` refuses a salesperson number while any gap remains;
  - `tools/set_config.php` refuses `multi_number_channels_enabled` truthy while any gap remains, or when it cannot tell
    (exit 1, nothing saved);
  - `tools/channels.php` prints whether the department numbers are all verified (Uganda only: elsewhere there is no
    card and the registry never takes effect);
  - the card, `set_config` and `tools/channels.php` count the gaps exactly as the policy does, a re-pair the guard has
    recorded included (`InternalNumbers::gapsWithReported`); `tools/channels.php` reads the record without opening the
    store, so it still writes nothing;
  - moving a department to another instance on the WhatsApp AI screen tells the admin to verify its number again, and
    every process with the registry on and a gap logs it once, loudly.
- **`lib/AutomationPolicy.php` (new)** — may anything automated leave on this channel, to this number? No transport, so
  `followup_run`, which must never name `EvolutionApiService`, can ask it. Reasons:

| reason | when | AI worker | follow-up run / send |
|---|---|---|---|
| `unknown_channel` | the registry does not know the channel | escalate | close |
| `channel_paused` | the number is paused | escalate | wait (defer 1 h / stay approved) |
| `channel_disabled`, `channel_retired` | switched off, retired | escalate | close |
| `channel_assistant_disabled` | **the number's assistant is off** | silent | close |
| `channel_unverified` | a salesperson number with no verified number, or one Evolution now reports with another | escalate | wait |
| `instance_changed` | moved to another instance since the routing was read, or a salesperson number switched on since | escalate | wait |
| `internal_numbers_incomplete` | a salesperson number while the department numbers are not all verified | silent | wait |
| `no_instance` | a department with no instance configured | escalate | close (as the sender has since 5.18.86) |
| `registry_unreadable` | the fresh read failed (salesperson numbers only) | retry (throw) | wait |
| `internal_recipient` | the recipient is a DishNet number | silent | close |

  Every check re-reads the channel's row, so a number switched off a moment ago is refused at once. **Nothing ever falls
  back to another number.**
- **The central guard, `EvolutionApiService`** — every reply-class and proactive-class send (text, photo, document) and
  the typing indicator asks the policy first, after the opt-out check. A refusal is `{ok: false, policy_refused: true,
  policy_reason}`, logged with the channel and the reason, never a number. **Staff-class sends are not asked**: a
  person's Inbox reply leaves on its conversation's own number with the assistant on or off, and staff alerts are as
  before.
- **Inbound** — `evo_webhook.php` (after J8) and `AiReplyWorker` (after its J8 check): a message from a DishNet number
  is stored, the chat filed `staff`, nothing queued, nothing answered — no reply, no typing, no lead, no follow-up, no
  escalation. The worker asks the policy again before the model and treats a refusal at the send by the table, never as
  a transport failure. `MediaWorker` (development only) refuses the same; a phone it cannot read is retried, never
  taken for a DishNet number.
- **Follow-ups** — the scan opens follow-ups only on a channel the registry routes and may send on (an allow-list: never
  a website chat, a notification thread or a refused number, which the run would only close and the next scan reopen),
  never for a DishNet number, and never for a `staff` chat — none of them takes a scan place. The run closes or defers
  before any model call and asks again before it approves on its own. The sender leaves drafts held on a number that
  may not send now out of its batch, so they take none of its ten places; it checks before the send and handles a
  refusal at the send itself. Ownership is unchanged (Part 5): a follow-up keeps its conversation, its channel and its
  lead's owner; D5 stands.
- **The watchdog** leaves a DishNet number's chat out of the unanswered-chat alerts, which would otherwise page every
  15 minutes about a chat nobody is meant to answer.
- **Leads** — `source_number` stays null for a department, as before; a salesperson number's lead records it.

### AD.3 Decisions taken in the build (none reopens an earlier one)

- The 5-minute pause, the 120-minute park limit, the message-id dedupe, J8, STOP and `mayAutoSend` are **unchanged**:
  the fix does not lean on any of them. Raising the pause and turning `followup_auto_send` off globally were both
  rejected by the operator's instruction.
- A salesperson's own phone of record is a DishNet number **on the salesperson numbers only**: a department keeps
  answering it as J8 always has, and a sales colleague who owns no number is still answered like a customer.
- No migration: the instance a department number was verified for is a trail row; Evolution's reported numbers are a
  store file the webhook guard rewrites each run.
- The assistant switch stops follow-ups on a department row too, should one ever be switched off (only the database can
  do it; the card refuses department ids).

### AD.4 The tests — `tests/test_pilot_safety.php` (new)

The instruction's tests 1–25 on the real plugin, with a fake Evolution and follow-ups behind a dead proxy, so no model
call and no message can leave. Among them:
- the loop both ways with the **same** message id and with **fresh** ones;
- the pause run out;
- the alert seed;
- an event queued before the fix;
- a switched-off line;
- the assistant off stopping every follow-up step;
- refusals that never fall back;
- the Inbox reply still leaving from its number with the assistant off;
- registry-off, South Sudan and Domain B controls.

The weakened copies are each caught — 66 on the development tree, 63 on the release's code (the media worker's three
are development-only). Copies 43–55 were added after the second review (AD.7), 56–63 after the third (AD.8), 64–66
after the fourth check (AD.9):

| | weakened copy |
|---|---|
| 1 | the webhook queues a DishNet number's message |
| 2 | the worker answers a DishNet number |
| 3 | nobody is ever DishNet's own (every layer at once) |
| 4 | a switched-off line's number is forgotten |
| 5 | the department numbers are forgotten |
| 6 | the owners are forgotten |
| 7 | the department numbers need not be complete |
| 8 | a department number verified for another instance still counts |
| 9 | the central guard lets a proactive send through |
| 10 | the central guard reads the registry once |
| 11 | the central guard blocks a person's Inbox reply too |
| 12 | nothing is refused for its recipient |
| 13 | the assistant switch is ignored |
| 14 | the follow-up run drafts on any number |
| 15 | the sender asks nothing before it sends |
| 16 | a paused number's follow-up is closed instead of waiting |
| 17 | the scan opens follow-ups on any number |
| 18 | the scan opens follow-ups for a DishNet number |
| 19 | the worker asks nothing before the model |
| 20 | the worker retries a refused send |
| 21 | the watchdog pages about a DishNet number's chat |
| 22 | a salesperson number is switched on before the department numbers |
| 23 | the registry is switched on before the department numbers |
| 24 | a department's lead records its number |
| 25 | numbers saved through Settings are not known |
| 26 | the alert number counts as a person on every channel that is not a department |
| 27 | the scan opens follow-ups on channels the registry does not route |
| 28 | a staff chat takes the scan's places |
| 29 | held drafts take the sender's places |
| 30 | the sender closes a draft that is only held |
| 31 | the sender retries what the policy refused at the send |
| 32 | the run approves on its own without asking again |
| 33 | the worker pages a person when the registry cannot be read |
| 34 | a registry that cannot be read counts as off in the crons |
| 35 | verifying a department never takes a number from a row that cannot own it |
| 36 | the guard records nothing Evolution reports |
| 37 | the numbers Evolution reports are never consulted |
| 38 | a department re-paired to another phone still counts as verified |
| 39 | a salesperson number re-paired to another phone still counts as verified |
| 40 | the channels tool speaks of department numbers in South Sudan |
| 41 | a department with no instance waits for ever |
| 42 | the media worker fetches a DishNet number's file (development tree) |
| 43 | the numbers Evolution reports are kept one per number, not one per instance |
| 44 | a salesperson verification leaves the guard's older read in place |
| 45 | a department verification leaves the guard's older read in place |
| 46 | an `@lid` owner's digits are verified as a phone number |
| 47 | verifying a department takes a number from any holder |
| 48 | every other department may give its number up |
| 49 | a department moved since its verification keeps its number |
| 50 | saving the numbers form says nothing of the department numbers |
| 51 | incomplete department numbers are never said in the log |
| 52 | a salesperson number switched on under an older read is closed as having no instance |
| 53 | a department with no instance waits as if it might get one |
| 54 | the guard records what Evolution reports with the registry off |
| 55 | a conversation read that fails settles the media worker's file (development tree) |
| 56 | a department's follow-up waits while the registry cannot be read |
| 57 | the card counts only the verification, never a re-pair |
| 58 | P3 (`tools/channels.php`) counts only the verification, never a re-pair |
| 59 | `set_config` counts only the verification, never a re-pair |
| 60 | an `@lid` owner is not recorded at all |
| 61 | an instance reported with no phone number counts as nothing reported |
| 62 | the card never flags a verified number re-paired onto an `@lid` owner |
| 63 | the media worker's client asks the policy without the store (development tree) |
| 64 | **Switch on** ignores what Evolution reports for the number's own instance |
| 65 | the card calls an `@lid` owner "another number" |
| 66 | a gap only the store shows is never logged by the webhook and the workers |

Copies 30–32 reach a check that the one before it normally decides first. They run a variant of the cron in the test's
own sandbox copy: the held-channel query, or the pre-check, set aside; or a model stub saying SEND while the assistant
goes off. The weakening is applied to the cron itself, so it reaches the variant too.

**The sending window.** Follow-ups send 08:00–20:00, Monday to Saturday, in the install's time zone. On Sundays from
07:00 to 19:00 UTC no time zone is inside it. Then the follow-up sends cannot run at all, and the test **fails**, saying
so, instead of skipping — and so does any weakened copy whose run falls in that window.

**Amended deliberately, never deleted.** Four existing tests built active salesperson numbers with no verified number,
or no department numbers. They now verify them, as the card requires:
- `test_channel_registry`;
- `test_multi_number_routing`;
- `test_sales_numbers`;
- `test_sales_pilot`.

In `test_sales_pilot`, *"a sales colleague is answered"* used a colleague who **owns** sales-002. It now asserts that an
owner is not answered, and a new assertion keeps the original intent with a colleague who owns no number.

After the review, `test_sales_numbers` was amended twice more, for the same reason: its fake Evolution reported numbers
for verified instances that were never the verified ones — in the guard part and in the screen part. Since 5.18.90
that is a re-pair, and the salesperson numbers stop. Each now reports the number it was verified with.

### AD.5 What it does not cover — the risk register

- **DishNet numbers on no Evolution instance and in no record** — a personal phone that is no owner's record, another
  provider's line. Not recognised; the runbook's loop test is the check. A number on any instance of DishNet's
  Evolution *is* recognised, through the guard's report — including another system's line on the same Evolution.
- **The first ten minutes after a re-pair.** A number re-paired to another phone, or a department moved to an instance
  with a new number, is recognised once `cron/wa_webhook_guard.php` next reads Evolution (every 600 s). Until then
  the new number is a stranger. After it, the department counts as unverified (no salesperson number sends) and a
  salesperson's re-paired number is unverified, until **Verify number** is pressed again. The guard records nothing
  while the registry is off: a re-pair made then is recognised only at the first guard run after switch-on (at most
  600 s after it), however long ago it happened — so the runbook's **Back on** verifies the department numbers first,
  which records what Evolution reports for every instance (docs/66 §R).
- **An `@lid` owner** — whether Evolution ever reports an instance's owner that way is not established. If it does,
  that instance has no phone number: it can never be verified, and a number verified on it stops (unverified; a
  department, a gap) until the phone is paired again and verified.
- **A department moved to another instance** silences every salesperson number at once (a gap). The departments go on
  and recognise each other only by the numbers recorded or reported.
- **Legacy WASender paths** (`wa_webhook.php`) are outside the guard. They send only from the department lines, which
  every salesperson number refuses.
- **With the registry off**, one department answering another is as it was before this fix: there are no salesperson
  numbers then.
- **A test limit, not a product one:** the fake Evolution's servers in different sandboxes share a marker and a port
  range. Two test suites run **at once** can borrow each other's server; run each counted pass alone. (Measured 08 Oct:
  38 false failures on the release copy while the development suite ran; 91/0 alone.)

### AD.6 The independent review (08 Oct), and what changed after it

A review in four parts (safety, regression, robustness, the tests), each finding checked by a separate skeptic, read the
built diff. One finding was refuted (the department assistant switch — a documented, unreachable state, recorded above
in AD.3). Every other one was confirmed or partly confirmed, and each was fixed in code, in a test or in this record:

| finding | change |
|---|---|
| re-pairing a verified instance is never detected; the runbook said otherwise (MAJOR) | Evolution's reported numbers: membership for every instance; a mismatch makes the department a gap and a salesperson number unverified; the runbook corrected |
| a department moved after the switch-on: nothing warns (MAJOR → MINOR) | the screen says verify again; a loud log line while a gap remains |
| the scan reopens what the run closes, every cycle, on channels the registry does not route (MAJOR) | the scan's channel condition is an allow-list |
| numbers saved through Settings live in the store, which the policy did not read (MAJOR) | the store's `kyc_config` fills what the files lack |
| a department number held by a covered department or a retired line could never be verified (MAJOR) | that row gives the number up, on the record |
| `no_instance` deferred a department's follow-up for ever | final, as the sender always treated it |
| held drafts filled the sender's batch of ten | left out of the sender's query |
| owners and alert numbers refused on notification threads and website chats | salesperson numbers only |
| `tools/channels.php` spoke of department numbers in South Sudan | Uganda only |
| the media worker took an unreadable phone for a DishNet number | retried; no phone on record has its own reason |
| eleven test gaps: the Sunday skip, three unreached branches, a log-only weakened copy, the shared-id order, an assertion that could not fail, no department-lead check, no planted unreadable registry, no media control, the scan's staff places, set_config's "nothing saved" | each covered by a test above, with its weakened copy where one applies |

### AD.7 The second review (08 Oct), of the changes after AD.6

A second review, run the same way, read only what changed after the first. Its one MAJOR finding and two partial ones
were real; one finding was refuted. Each confirmed finding was fixed, and each fix is pinned by a test, and by a
weakened copy where one applies (AD.4, copies 43–55):

| finding | change |
|---|---|
| **re-pair detection depended on the order of instance names** (MAJOR). One phone can be linked to two instances. A salesperson's instance paired with the sales desk's phone, both reporting one number, was recorded once, under the instance whose name sorted last. If the salesperson's name sorted first, its re-pair went unseen and it answered the desk's customers from the desk's number; the mirror case left a department re-paired onto a salesperson's phone counted as verified | one record **per instance**; each instance is compared with its own row. Tested both ways round in process, with the re-paired instance's name sorting first; through the real guard, the salesperson direction (Alpha's instance on the sales desk's phone) — the sandbox has no salesperson instance that sorts after a department's |
| Verify number accepted an `@lid` owner's digits as a phone number, closing a department's gap (partly: the read predates 5.18.90, and Evolution reporting an `@lid` owner is not established) | Verify number — a department's and a salesperson's — and the card's "now reports another number" flag read only the phone number Evolution reports. An `@lid` owner has none: refused, and the gap stays open |
| a salesperson number switched on while a follow-up run was going closed its due follow-ups as `no_instance` (partly: the next scan reopens them; the reason was wrong) | `no_instance` is a department's only, and final; a salesperson number missing from an older read is `instance_changed`, which waits |
| (refuted) the "verify again" note on the WhatsApp AI screen with the registry off | intended — the departments are verified before the switch. Only its word "again" was wrong for a number never verified; reworded |
| seven test gaps: the verification's own refresh unpinned; only the releasing half of a department verification tested, never the refusing half or the "moved" clause; an assertion element that could not fail; weakened copies skipped quietly in the Sunday window; the guard's registry gate never run off; the media worker's read failure untested; the form's note and the log line unpinned | each tested, with its weakened copy where one applies (the unreadable-run department check got its copy only after the third review, AD.8; the Sunday window is a harness rule, not a copy); a weakened copy that cannot run in the Sunday window is a failure now, never a skip |

### AD.8 The third review (08 Oct), of the round-two changes

A third review, run the same way, read what changed after AD.7. It found no MAJOR. Five MINOR findings were each
confirmed by a skeptic; each was fixed, and each fix is pinned by a test, and by a weakened copy where one applies
(AD.4, copies 56–63):

| finding | change |
|---|---|
| the card's gate and note, P3 (`tools/channels.php`) and `set_config` counted only the verification, so a re-pair the policy already enforced showed as "all verified" — failing closed, but the status the runbook reads was wrong | all three count the gaps as the policy does (`InternalNumbers::gapsWithReported`), a recorded re-pair included |
| a verified number whose instance was re-paired onto an `@lid` owner was flagged nowhere: the instance was not recorded at all, and the card's flag read only phone numbers — failing open, if Evolution ever reports an `@lid` owner (not established) | an `@lid` owner is recorded as reporting no phone number, which matches no recorded number: the row counts as unverified, a department as a gap, and the card flags both rows. Its Verify number says plainly that such an instance cannot be verified |
| the media worker (development tree) asked the policy without the store when a retried file skipped the sender check, so a hand-over line could leave on a re-paired number | the worker gives its client the store when it builds it |
| the test that the guard alone clears a re-pair had stopped proving it: a verification before it had already rewritten the record | a new assertion after the one-phone-two-instances case, where nothing but the guard can clear it — an assertion only: no weakened copy reaches it alone, because the guard replaces its whole record each run and a copy that kept old records would still be overwritten per instance |
| §AD.7 claimed more coverage than exists (a copy for every fix; both directions through the real guard) | reworded; the unreadable-run department check now has its copy |

### AD.9 The fourth check (08 Oct), of the round-three changes

A smaller check — two readers, each finding challenged by a skeptic — read what changed after AD.8. It found no MAJOR
and nothing that lets an automated message out; six MINOR findings, all of them failing closed or in the record:

| finding | change |
|---|---|
| **Switch on** read only the verification, so a number whose own phone the guard had recorded as re-paired (or as an `@lid` owner) was reported "switched on" while the policy refused it | **Switch on** refuses it, saying why, exactly as the policy reads it; verified again, it switches on (copy 64) |
| for an `@lid` owner the card still said "Evolution now reports another number" | the card says the owner has no phone number, and to pair the phone again (copy 65) |
| a gap only the store shows (a recorded re-pair) was enforced but never logged by the processes built as the webhook, the AI worker, the media worker and the follow-up sender | the line is logged when the store is given, once a process (copy 66) |
| a re-pair made while the registry is off is recognised only after switch-on — AD.5 dated the window from the re-pair | AD.5 and the runbook say so; **Back on** after R-3 now verifies the department numbers first, which records what Evolution reports for every instance |
| AD.8 claimed a weakened copy for every fix | reworded: the guard-alone assertion has none, and why |
| the `reported_back` assertion's message credited the guard with what a verification had done | relabelled |
