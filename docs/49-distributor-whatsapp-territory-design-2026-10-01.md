# 49 — Distributor WhatsApp, AI routing & territory management: discovery and design

1 October 2026. **Phase 0 — discovery and design only. Nothing here is built, deployed, or wired to
any real number.** No uCRM record, no partner record, no distributor WhatsApp number, no message, no
migration and no production configuration is created or changed by this document. Implementation and
every production-affecting action **wait for explicit approval**, as the assignment requires.

> **Approval boundary (quoted from the assignment).** *"Perform discovery and produce the design and
> implementation plan only. Do not modify production data, connect real distributor numbers, send real
> messages, create uCRM records, deploy code or change production configuration. Ask for explicit
> approval before beginning implementation or any production-affecting action."* This document is that
> design and plan, and it stops there.

> **What stays untouched.** Uganda's live behaviour, South Sudan's byte-for-byte behaviour, and the
> Domain-B MikroTik control plane (`dishnet-mikrotik-control-plane/`, the subject of `CLAUDE.md`) are
> **out of scope** and are not modified by anything proposed here. Every change below is additive,
> behind an off-by-default switch, and independently releasable.

> **The one decision that governs the design.** The assignment names a critical technical choice:
> *"Claude must inspect the actual deployment and determine whether to extend the existing uCRM/Evolution
> integration or use the official Cloud API for this use case. It should not assume these are
> interchangeable or create a second, conflicting WhatsApp integration."* §3 answers it from the code,
> on evidence, and its conclusion shapes everything after it.

---

## 0. How to read this, and what it builds on

This is the design layer **above** the distribution foundation already audited in root **`docs/47`**
(distribution-management audit, 30 Sep). `docs/47` settled the commercial/organisational model — the
`dist_partners` entity (a uCRM **company client**, deduped by uCRM id / TIN, **never by phone**),
`dist_agreements`, `dist_outlets`, `dist_regions`, `stock_locations`, server-set `partner_id` scoping
with composite keys, a **separate partner portal** on its own hostname with its own code+TOTP login
(never the staff login, never uCRM's), the PD-1…PD-9 security floor, decisions D-1…D-15, and the ruling
that distribution is **"Not Domain B."** This document **references** that foundation and does not
re-decide it.

`docs/49` adds the **customer-facing operational layer** that `docs/47` did not cover:

1. per-distributor WhatsApp presence and whether/how the AI answers on it;
2. territory → lead/customer **attribution** (who a customer "belongs to");
3. **distributor notifications** (telling a distributor when something happens to one of their
   customers);
4. a distributor's **visibility boundary** (seeing only their own customers/leads);
5. the WhatsApp-transport decision (§3) that all of the above depend on.

Labels used throughout: **[CODE]** = read directly in source at the cited `file:line`; **[TEST]** = a
named suite test asserts it; **[PROD?]** = depends on a server/config value this session cannot read and
must be confirmed by the operator. The evidence was gathered by three read-only code maps over
`dishnet-hybrid-sudan/` on 1 Oct 2026; nothing was executed and nothing was changed.

---

## 1. Current architecture and integration map  *(deliverable 1)*

### 1.1 The messaging spine, as it runs today

```
                    (unofficial WhatsApp bridge — Evolution API v2, Baileys under the hood)
 customer WhatsApp ─┐
                    ├─► Evolution instance ──► POST ?page=evo_webhook ──► EvoWebhookGuard (shared-secret
 one of THREE fixed │      (sales/support/      (public.php:829)           token, 900s replay, dedup claim)
 DishNet numbers ───┘       account)                    │
                                                         ▼
                                      EventBus.emit('ai.reply', …)  ──►  AiReplyWorker
                                      (lib/EventBus.php)                 (workers/AiReplyWorker.php)
                                                                               │
                             buildContext() ── identify customer by phone ─────┤  (single uCRM)
                             loadCatalogue() ── live uCRM products ────────────┤
                                                                               ▼
                                                                        DishNetAiBrain
                                                                        (Claude/OpenAI, prompt rules)
                                                                               │
                                                        ReplyPrivacyGuard.check()  (cross-customer /
                                                        (workers/AiReplyWorker.php:685)  price firewall)
                                                                               │
                                           EvolutionApiService.sendText(channel, phone, reply) ──► customer
```

Key properties, all **[CODE]**:

- **The transport is Evolution API**, an *unofficial* WhatsApp gateway (community Baileys/WhatsApp-Web
  bridge), not Meta's official WhatsApp Cloud API. One endpoint + one key: `evo_api_url`, `evo_api_key`
  (`lib/EvolutionApiService.php:113-114`). It is reached only through the plugin's single public file,
  as `?page=evo_webhook` (`public.php:829-831`).
- **Three fixed channels**: `CHANNEL_SALES`/`CHANNEL_SUPPORT`/`CHANNEL_ACCOUNT`
  (`lib/EvolutionApiService.php:37-39`), bound to three Evolution instances via config keys
  `evo_instance_sales` / `_support` / `_account` (`:119-123`). An inbound message is routed solely by
  `payload['instance']` → `channelFor()` (`evo_webhook.php:84`, `lib/EvolutionApiService.php:205-208`);
  **an unmapped instance is rejected, never defaulted** (`evo_webhook.php:96-101`), with the explicit
  reason "guessing here would route one number's customers into another number's business context."
- **No HMAC on inbound.** Evolution v2 does not sign payloads; the webhook is authenticated by a shared
  secret presented as a header or `?token=` and compared with `hash_equals`
  (`lib/EvoWebhookGuard.php:107-127`), fail-closed if unset (`:109-111`).
- **A second, legacy inbound path still exists and is still routable**: the WASender/pusher path
  `?page=wa_webhook` → `WaInbound`/`WaMessageProcessor`/`WaAutoReplyService` (`public.php:746-748`,
  `wa_webhook.php`), a *synchronous* reply path rather than the EventBus queue. Whether it is still fed
  in production is **[PROD?]**. Both paths write the same `wa_conversations`/`wa_messages`.
- **The AI brain is grounded and firewalled.** Prices/plans come only from live uCRM
  (`lib/DishNetTools.php:373,414`; `AiReplyWorker.php:645-665`), never invented
  (`lib/DishNetAiBrain.php:276-306`); `ReplyPrivacyGuard` replaces the whole reply with a safe fallback
  and escalates to a human on any cross-customer / secret / foreign-money hit
  (`lib/ReplyPrivacyGuard.php:35-41,260-262`). [TEST] `test_prices_are_grounded.php`,
  `test_reply_privacy_guard.php`, `test_brain_context.php`.

### 1.2 Customer / lead model

- **Two lead stores plus uCRM as the customer master.** The plugin's own pipeline is `leads.json`
  (`lib/AiLeadService.php:216-237`); a lead reaches uCRM as a **client row** `clientType=1,isLead=true`
  (`lib/UcrmLeadSync.php:303-314`) — there is no separate uCRM "leads" resource. uCRM is authoritative
  for clients, invoices, payments and quotes; the plugin mirrors a `client_search_index` for fast
  matching (`lib/ClientSearchIndex.php`). **[CODE]**
- **uCRM access is one generic REST wrapper**, `lib/CrmApiClient.php`, auto-configured from `ucrm.json`
  (`:50-80`); ~100+ callers hit `clients`, `billing/invoices`, `billing/payments`, `billing/quotes`,
  with `ticketing/tickets` barely wired (2 call sites). **[CODE]**
- **Two phone matchers with different rules.** `CustomerIdentity` (strict: exact last-9 + country code,
  returns `AMBIGUOUS` on ≥2, never first-match-wins — `lib/CustomerIdentity.php:80-195`) guards WhatsApp
  customer resolution. `LeadMatcher` (loose: last-9, **first-match-wins**, no ambiguity handling —
  `lib/LeadMatcher.php:38-63`) is still used for lead dedup. The strict one carries a docblock recording
  the wrong-customer-disclosure bug it replaced. **[CODE]**

### 1.3 Notification engine

- **One engine**: `lib/NotificationService.php`, ~60 typed customer builders + `sendAdmin()`, over
  Evolution (preferred) or legacy WASender. **No SMS anywhere in this plugin.** **[CODE]**
- **Exactly-once is "claim before send"** on `notification_dedup` (`dedupMark()` = `INSERT OR IGNORE`,
  `:3042`), caller-supplied keys (`INV<n>`, `PAY<id>`, `NEARDUE_…`, …). It **fails open** on store
  error (`:3052`). **[CODE]** [TEST] `test_notify_event_once.php`, `test_receipts_once.php`.
- **Retry is deliberately conservative**: `lib/NotificationRetry.php` auto-retries **only messages that
  stay true** (receipts, welcomes, quotations — `:26-33`) and **only failures that certainly did not
  reach the customer** — `MAYBE_SENT` is never auto-resent (`:59-69`). **[CODE]**
- **Consent**: `lib/ContactOptOut.php` — `CLASS_STAFF` is never blocked (`:94`); a plain "STOP"
  (scope=proactive) blocks only messages we start, **not** transactional ones (`:88-101`); enforced in
  `sendVia()` with default class transactional (`NotificationService.php:2107,2120`). **[CODE]**
- **Recipient resolution lives in the CALLER, not the engine** — the engine sends to whatever phone it
  is handed. For CRM customer-lifecycle events, the handlers resolve **only the customer's own phone +
  one global admin number** (`webhook.php:1137-1143` and siblings); **no customer-ownership field is
  ever read to pick a recipient.** The strongest existing "notify the owner of entity X" pattern is
  `JobNotifier` → `StaffDirectory::byUcrmUser()` via a **verified** uCRM-user link (`lib/JobNotifier.php:398`,
  `lib/StaffDirectory.php:59-100`). **[CODE]**
- **No WhatsApp 24-hour-window or Meta-template logic exists anywhere** — because Evolution/Baileys does
  not enforce Meta's messaging policy. This absence is load-bearing for §3. **[CODE]**

### 1.4 Staff access & isolation

- Two overlapping systems: `RetailerAuth` (accounts/sessions/Bearer tokens in `retailers.json`,
  `lib/RetailerAuth.php:289-307`) and `RbacService` (roles→permissions in SQLite,
  `lib/RbacService.php`). The **"Dealer" role is a person-with-a-wallet, not a company** (`:114`),
  identical permissions to `sales_staff` but `is_staff=0`. **[CODE]**
- **Tabs are gated server-side** (`public.php:2938-2958`); **POST/action handlers gate ad-hoc per
  handler**; there is **no central per-action RBAC gate and no row-level ownership boundary.** Leads are
  explicitly **all-visible to all sales staff** (`tabs/sales/leads.php:4`); per-user views are
  presentation-layer `array_filter` on `retailer_id`, re-implemented per file, which admins bypass.
  **[CODE]**

### 1.5 What "distributor" means in the code today

Exactly one thing: a **recruitment intake form**. `dist_partner_applications` (migration 077),
`DistributorApplicationService`, and the public `?page=distributor_apply` endpoint capture an expression
of interest and nothing more — the service's own docblock: *"It never creates a uCRM client, a partner
record, a service … never calls uCRM at all"* (`lib/DistributorApplicationService.php:5-16`). It sends
**no notification** on submission (`distributor_apply.php` ends at a JSON `ok`). There is **no appointed
distributor entity, no distributor WhatsApp number, no distributor↔customer link** anywhere. **[CODE]**

---

## 2. Capabilities vs gaps (with file:line evidence)  *(deliverable 2)*

| # | The distributor use case needs… | Today | Evidence |
|---|---|---|---|
| C1 | An **appointed-distributor entity** (company, with sub-accounts) | **Missing.** Only an intake form + the person-with-wallet `dealer` role | `dist_partner_applications` (mig 077); `RbacService.php:114`; `docs/47` designs `dist_partners` but it is **not built** |
| C2 | **Territory / coverage** as data that scopes leads/customers | **Missing entirely.** `grep territory\|catchment\|region_id` over `lib/ tabs/ includes/ migrations/` = **zero** matches. The apply form stores free-text `city` + a `coverage` JSON blob that nothing consumes | agent map §5(b); `docs/47` designs `dist_regions`/`dist_outlets` (unbuilt) |
| C3 | **Customer/lead → distributor attribution** (structured owner) | **Partial / wrong granularity.** Leads carry `retailer_id`/`assigned_to` (a *person* id; AI leads set `retailer_id=0`). uCRM clients carry only a **free-text "Sales Person" custom attribute**, which may be remapped/dropped on the Uganda install | `lib/AiLeadService.php:230`; `lib/KycService.php:1402-1429`; `lib/UcrmClientTarget.php:15,35,70,104` **[PROD?]** |
| C4 | **Recipient resolution** → a distributor's registered number | **Missing for customer events.** Handlers resolve only the customer's own phone + one global admin number; no ownership field is read | `webhook.php:1137-1143`; agent map §7 |
| C5 | A distributor's **own WhatsApp number** with isolated conversations | **Missing.** 3 fixed channels; one Evolution key; one webhook secret; `wa_conversations` keyed `(phone,channel)` with **no tenant column**; `whatsapp_instance` is carried then **dropped by `BrainContext` NEVER_PRESENT** | `EvolutionApiService.php:37-42`; `migrations/017…:40-41`; `lib/BrainContext.php:82-102` |
| C6 | **Per-distributor data isolation** (sees only their own) | **Missing.** No RLS, no central row scope; leads all-visible; mobile-API owner checks are keyed to `retailer_id`, not a distributor, and not on list endpoints | `tabs/sales/leads.php:4`; `api/index.php:515,881-883` |
| C7 | **Distributor notifications** (consent-aware, deduped, retried) | **Engine exists and is reusable**, but no distributor caller, no distributor consent record, no distributor recipient lookup | `NotificationService.php`; `ContactOptOut.php`; `StaffDirectory` verified-link model `JobNotifier.php:398` |
| C8 | **Appointment flow** (application → active distributor) | **Missing.** The review tab is read-only; nothing turns a `DNP-…` row into an account/partner/uCRM client | `tabs/admin/partner_applications.php:16,88-89` |
| C9 | **Business-initiated messaging outside a 24h window** (the heart of "notify a distributor") | **No policy awareness at all** — works today only because Evolution ignores Meta's rules; a sanctioned path would need templates | agent map §4 (no window/template logic) |

**Reusable assets (build on these, don't rebuild):** the grounded+firewalled AI brain (§1.1), the
`notification_dedup` claim-before-send guard, `NotificationRetry`'s "only messages that stay true /
never MAYBE_SENT" rule, `ContactOptOut`'s class model, and the **`StaffDirectory` verified-link**
pattern — which is the correct shape for "notify the owner of entity X."

---

## 3. The critical decision: Evolution API vs WhatsApp Cloud API  *(deliverable 6, pulled forward)*

The assignment is explicit that these **are not interchangeable** and that a second, conflicting
integration must not be created. The code confirms why they differ at the root.

### 3.1 They are different messaging *models*, not two drivers for one thing

| | **Evolution API (what runs today)** | **Official WhatsApp Cloud API** |
|---|---|---|
| Nature | Unofficial bridge (Baileys / WhatsApp-Web session) | Meta-sanctioned Business Platform |
| A "number" is… | a **live WhatsApp Web session** QR-linked to a real WhatsApp account/phone, kept online | a number **registered to a WABA**, `phone_number_id`, no phone needs to stay online |
| Business-initiated message outside 24h | **just send free text** (no rule enforced) | **only a pre-approved template**, inside Meta policy; 24h "customer service window" otherwise |
| Inbound auth | shared-secret token, **no signature** (`EvoWebhookGuard.php:107-127`) | **HMAC `X-Hub-Signature-256`** on every webhook |
| Delivery receipts | dropped today (`notification_audit_log.success` = *accepted*, not delivered) | sent/delivered/read status callbacks |
| ToS / ban risk | **against WhatsApp ToS; numbers can be banned**, especially for automation/volume | sanctioned; built for automation |
| Multi-number scale | each number = another live session to keep alive | clean: many `phone_number_id`s under one or many WABAs |
| Cost | Evolution server only | Meta per-conversation pricing + a WABA per brand |
| Onboarding a distributor's number | QR-link *their* WhatsApp (keeps their number/app) | **migrate their number to Cloud API** (it can no longer be a normal WhatsApp app) + Business verification |
| Fit with existing code | **total** — brain, guards, dedup, retry, store all assume this | **new adapter**: signature check, template catalogue, number onboarding, window tracking |

The decisive asymmetry for *this* use case is the business-initiated path. "Notify a distributor when a
customer in their territory does X" is, by definition, **a message the business starts** — often hours
or days after the last inbound. On Evolution that is a free-text send that **works but risks the
number**. On Cloud API that is **exactly a template message** — the sanctioned mechanism for precisely
this. The current codebase has **no template or window logic at all** (agent map §4), so the Cloud-API
model is not a config flip; it is a capability the plugin does not yet have.

### 3.2 Why I am **not** recommending a live transport choice for the pilot

Two independent reasons, both evidence-based:

1. **The transport is the riskiest and most decision-dependent piece — and it is *not* the missing
   piece.** Every genuinely absent capability (C1–C8 in §2) — distributor entity, territory,
   attribution, recipient resolution, isolation, appointment — must be built **regardless** of which
   WhatsApp model is eventually chosen, and **all of it is buildable and fully testable without
   connecting any real distributor number.** Choosing a transport now buys nothing the pilot needs and
   imports the one risk (a banned number, or a premature Meta commitment) that would most embarrass a
   pilot.

2. **The choice depends on inputs only the business holds** (see §12): is there already a Cloud-API
   effort (the "CloudBSS" work referenced in planning — **no repository by that name is visible to this
   session**; candidates `starlink-backend`, `isp-portal`, `starlink_isp_app`, `OperationsHub` are
   unconfirmed)? Is DishNet's Meta Business verified? Is DishNet willing to migrate a distributor's
   number to Cloud API (losing its use as a normal WhatsApp app)? What is the per-conversation budget?
   Making the transport decision before these are answered is exactly the "assume they're
   interchangeable" error the assignment warns against.

### 3.3 The recommendation: a provider port, one adapter live at a time, pilot on the DishNet-controlled path

- **Introduce a thin provider boundary** — a `WhatsAppChannel` port (design-level name) with the
  existing `EvolutionApiService` as one adapter and a future `CloudApiChannel` as another. The
  distributor messaging layer is written against the **port**, never against Evolution directly. This is
  the one piece of transport work worth doing now, because it is what prevents a "second conflicting
  integration": there is always exactly **one** adapter bound live for a given number, selected by
  config, never two.
- **For the pilot, bind no new live WhatsApp integration at all.** Distributor notifications in the
  pilot go out through the **existing** `NotificationService` from a **DishNet-controlled** number
  (`sales`/`support`), or — safer still — in **draft→approve** mode where a human releases each
  distributor message (mirroring the existing human-approved follow-up path,
  `cron/followup_send.php`). This proves attribution → recipient resolution → consent → dedup end to
  end with **zero** new ban/verification risk.
- **Defer "each distributor answers on their own number with the AI" to its own explicitly-decided
  step**, after the transport decision. When it comes, the AI brain, guards and conversation store are
  reused behind the same port; what changes is the instance→**distributor** map (replacing the fixed
  3-channel map) and carrying a `distributor_id` tenant key end to end (the ten single-tenant points in
  §4.3).

This keeps the pilot small, safe and independently releasable, and it leaves the strategic transport
choice fully open for Bhavin to make on business grounds (§12, decision **B-1**).

---

## 4. Target architecture and message flow  *(deliverable 3)*

### 4.1 The layer being added (everything new sits beside the live path, never inside it)

```
          ┌─────────────────────────────── EXISTING, UNCHANGED ───────────────────────────────┐
 customer │  evo_webhook → EventBus → AiReplyWorker → DishNetAiBrain → ReplyPrivacyGuard        │
 WhatsApp │  uCRM (clients/invoices/payments) · NotificationService · ContactOptOut             │
          └───────────────────────────────────────────────────────────────────────────────────┘
                                   │  (reads only; never modified)
                                   ▼
          ┌─────────────────────────────── NEW, ADDITIVE, OFF BY DEFAULT ─────────────────────┐
          │  Distributor registry  ──┐                                                         │
          │  (dist_partners, from     │   Attribution resolver  ──►  Recipient resolver         │
          │   docs/47)                │   (territory + explicit     (distributor verified        │
          │  Territory (dist_regions, │    assignment → owner)       number, StaffDirectory      │
          │   dist_outlets)  ─────────┘                              model)                      │
          │                                   │                           │                      │
          │                                   ▼                           ▼                      │
          │                      DistributorNotifier  ──►  WhatsAppChannel PORT                  │
          │                      (dedup + consent +        ├── EvolutionAdapter (exists)         │
          │                       draft/approve)            └── CloudApiAdapter (future, §3)      │
          │                                                     one bound live at a time         │
          │  Distributor visibility boundary (central row scope) ── partner portal (docs/47)     │
          └───────────────────────────────────────────────────────────────────────────────────┘
```

### 4.2 Message flows

**Flow A — a customer event notifies the owning distributor (the pilot's core flow):**

```
uCRM webhook (payment.add / invoice.add / service.*) ─► webhook.php handler (UNCHANGED resolves customer)
   └─► NEW: AttributionResolver.ownerOf(customer_id)        ─► distributor_id | none
         └─► if distributor pilot-enabled for that distributor:
               RecipientResolver.numberFor(distributor_id)  ─► verified distributor number
                 └─► DistributorNotifier.notify(distributor_id, event, customer)
                       ├─ ContactOptOut class = CLASS_DISTRIBUTOR (new, never customer-opt-out-suppressed,
                       │    but has its own consent switch)
                       ├─ notification_dedup key = DIST:<distributor_id>:<event>:<entity_id>
                       └─ WhatsAppChannel.send(...)  OR  draft→approve queue   (pilot: DishNet number)
```
The customer's own receipt path is **untouched**; the distributor copy is a *new, separate* send with
its own recipient, class, consent and dedup key.

**Flow B — a new lead is attributed to a distributor by territory:** at lead creation
(`AiLeadService::newLead`, `includes/post/post_leads.php`, `api/index.php`) a new,
nullable `distributor_id` is derived from territory (§6) and stored; it does not change existing
`retailer_id`/`assigned_to` behaviour, it sits beside it.

**Flow C — (deferred, post-transport-decision) a customer messages a distributor's own number:**
inbound carries `payload['instance']`; a new instance→**distributor** map resolves `distributor_id`;
the existing brain/guard pipeline runs with `distributor_id` carried end to end and enforced in
`BrainContext`. Not in the pilot.

### 4.3 The ten single-tenant points that Flow C (not the pilot) would have to change

Enumerated from code so the deferred work is bounded, not hand-waved: (1) fixed channels
`EvolutionApiService.php:37-42`; (2) one Evolution key `:113-114`; (3) one webhook secret
`EvoWebhookGuard.php:44-49`; (4) `wa_conversations` UNIQUE `(phone,channel)` with no tenant column
`migrations/017…:40-41`; (5) single uCRM `CrmApiClient.php:59-70`; (6) single `leads.json`
`AiLeadService.php:126`; (7) one AI identity/knowledge `DishNetAiBrain.php:49-64,264`; (8)
`whatsapp_instance` dropped by `BrainContext` NEVER_PRESENT `:91`; (9) one flat `kyc_config.json`
read install-wide; (10) scheduler iterates the three mapped channels only
(`cron/wa_webhook_guard.php`). **[CODE]** The pilot touches **none** of these; Flow A and B need none
of them.

---

## 5. Data model and migration plan  *(deliverable 4 — references docs/47)*

**The entity, agreement, outlet, region and stock tables are `docs/47`'s** (`dist_partners`,
`dist_agreements`, `dist_outlets`, `dist_regions`, `stock_locations`). This document does **not**
re-specify them and does not change `docs/47`'s rulings (uCRM company client; dedupe by uCRM id / TIN,
**never phone**; server-set `partner_id`; composite keys; "Not Domain B"). What `docs/49` adds are the
**attribution, contact and notification** tables that the operational layer needs. All are **additive
SQLite migrations**, numbered after the current head (077), each creating its own table; **no existing
table or column is altered**, preserving Uganda/South Sudan behaviour.

| New table (proposed) | Purpose | Shape notes / evidence-driven constraints |
|---|---|---|
| `dist_customer_links` | the **structured** customer/lead → distributor owner link (fills C3) | one row per `(scope, entity_id)` where scope ∈ {`ucrm_client`,`lead`}; `distributor_id`, `assigned_via` (`territory`\|`manual`\|`application`), `assigned_by`, `assigned_at`, `source`. **Never inferred from a phone number** (the `LeadMatcher`/`LeadMatcher`-warning rule, `docs/47` D-rule). A link is written once, with provenance — the `StaffDirectory` verified-link discipline (`lib/StaffDirectory.php:59-68`). |
| `dist_territory_map` | which **territory** a lead/customer falls in → candidate distributor | derived from `docs/47`'s `dist_regions`/`dist_outlets`; resolves a *candidate*, which a human confirms into `dist_customer_links` for the pilot (no silent auto-assignment of real customers). |
| `dist_contacts` | a distributor's **verified** notification number(s) | `distributor_id`, `phone` (international, normalised), `role` (owner/ops), `verified_at`, `verified_by` — the **verified-link** model, not a free-text field; >1 match refused as ambiguous exactly as `JobNotifier` does (`:405`). |
| `dist_notify_consent` | per-distributor consent / channel preference | class `CLASS_DISTRIBUTOR`; mirrors `contact_optouts` (lift-not-delete, `migrations/069`); a distributor can mute their own alerts without a customer's opt-out ever suppressing them (`ContactOptOut.php:94`). |
| `dist_notify_log` | distributor-notification audit + dedup provenance | reuses the `notification_dedup`/`notification_audit_log` discipline; key `DIST:<distributor_id>:<event>:<entity_id>`. |
| `dist_appointment` | the **application → active distributor** bridge (fills C8) | links a `dist_partner_applications.id` (`DNP-…`) to the created `dist_partner`; records who appointed, when; **still a deliberate staff action**, never automatic (`partner_applications.php:88-89`). |

**Migration safety rules (from this project's own evidence):**
- Additive only; each migration one table; no `ALTER` on a live table used by Uganda/South Sudan.
- Every new write path is behind an **off-by-default** config flag, in the Uganda-gated style of the
  5.18.54 fixes (`NotifyGate`), so South Sudan runs the old code verbatim and Uganda is unchanged until
  the flag is turned on.
- Attribution/contact tables carry **provenance columns** (`*_by`, `*_at`, `assigned_via`) because, as
  `docs/47` and the identity work insist, *who/when/how* a link was made is the auditable fact.
- A distributor contact number is **verified before use**, never trusted from an application form.

---

## 6. Territory and customer-attribution rules  *(deliverable 5)*

Territory does not exist in code today (C2). The rules below define it; they are deliberately
**conservative for the pilot** (no silent reassignment of real customers).

1. **A territory is `docs/47` geography** (`dist_regions` / `dist_outlets`), not free text. The apply
   form's `city`/`coverage` blob is an input to drawing a territory, never the territory itself.
2. **Attribution is explicit and stored once, with provenance** — `dist_customer_links`. Three legal
   sources: (a) **territory** (a customer/lead whose location falls in a distributor's region), (b)
   **manual** (staff assign), (c) **application/appointment**. Each records `assigned_via`,
   `assigned_by`, `assigned_at`.
3. **Never attribute by phone number.** The project's own `LeadMatcher` warning and `docs/47`'s
   dedupe-never-by-phone rule both forbid it — loose phone matching "can identify the WRONG customer and
   disclose their balance." Phone stays an OTP/identity key only.
4. **Territory resolves a *candidate*, a human confirms the *link* — for the pilot.** Auto-writing a
   `distributor_id` onto a real customer silently is out of scope for a one-distributor pilot; the
   resolver proposes, staff confirm. (Full auto-attribution is a later, separately-approved step once
   territories are trusted.)
5. **One owner at a time** (mirrors the notification engine's "one owner per event"). A customer's
   active link is single; **relinking is audited** (previous + new + reason), never an overwrite without
   a trail — the `docs/47`/identity discipline.
6. **Attribution is additive to existing per-person fields.** `retailer_id`/`assigned_to` on leads keep
   their current meaning and behaviour (`lib/AiLeadService.php:230`); `distributor_id` is a new,
   independent dimension. No existing routing changes.
7. **Ambiguity fails safe.** If territory yields ≥2 candidate distributors, the resolver returns
   `ambiguous` and assigns nobody — the same discipline as `CustomerIdentity`/`JobNotifier`
   (`CustomerIdentity.php:117-124`, `JobNotifier.php:405`).

---

## 7. Notification event matrix  *(deliverable 7)*

Events a distributor could be told about, each mapped to its existing source, the recipient-resolution
step, and the consent/dedup discipline. **Pilot scope = the three bold rows**; the rest are designed but
deferred. Every distributor send is `CLASS_DISTRIBUTOR` (new) — never suppressed by a *customer's*
opt-out, but honouring the *distributor's* own `dist_notify_consent`.

| Event | Source (existing) | Owner resolved via | Dedup key | In pilot? |
|---|---|---|---|---|
| **New lead attributed to distributor** | `AiLeadService`/lead create | territory → confirmed link | `DIST:<d>:lead:<lead_id>` | **Yes** |
| **Customer payment received** | uCRM `payment.add` (re-verified, `webhook.php:1130`) | `dist_customer_links` | `DIST:<d>:pay:<payment_id>` | **Yes** |
| **New customer activated in territory** | KYC/service create | link | `DIST:<d>:activate:<client_id>` | **Yes** |
| Invoice created for their customer | uCRM `invoice.add` | link | `DIST:<d>:inv:<invoice_id>` | No |
| Customer overdue / near-due | existing ladder | link | `DIST:<d>:overdue:<id>_<date>` | No |
| Service suspended/resumed/ended | uCRM service events | link | `DIST:<d>:svc:<id>` | No |
| Support ticket opened by their customer | `ticketing/tickets` (barely wired, 2 sites) | link | `DIST:<d>:ticket:<id>` | No |
| Stock / commercial (margins, settlements) | `docs/47` commercial layer | — | — | No — commercial boundary, `docs/47` |

**Rules the matrix inherits from the engine (all [CODE]):** claim-before-send on `notification_dedup`
(fail-open, `:3042,3052`); **never auto-resend a MAYBE_SENT** distributor message
(`NotificationRetry.php:59-69`); **a distributor alert must never carry another customer's data** — the
`ReplyPrivacyGuard` allow-list discipline applies to any templated distributor copy
(`ReplyPrivacyGuard.php:13-28`); **money/receipt events fire only off a uCRM-re-verified payment**, never
a webhook body (`webhook.php:586-592,1130`). **Commercial figures (margins, settlements, balances) are a
`docs/47` boundary and are out of this notification layer.**

---

## 8. Role and permission matrix  *(deliverable 8)*

Built on the existing `RbacService` module:action model (`lib/RbacService.php`), **additively** — new
permissions, no change to existing grants. The hard constraint from `docs/47`: a **distributor is a
company client with portal users**, served by a **separate partner portal** (own hostname, code+TOTP),
**never** the staff login and **never** a uCRM login (the PD-2…PD-9 reasons). So two permission planes:

**DishNet staff plane (existing RBAC, new permissions):**

| Permission (new) | Who | What |
|---|---|---|
| `distributor:appoint` | admin | turn a `DNP-…` application into an active `dist_partner` (the deliberate action, `partner_applications.php`) |
| `distributor:attribute` | admin, sales_leader | create/relink `dist_customer_links` (audited) |
| `distributor:territory` | admin | define `dist_regions`/`dist_outlets` coverage |
| `distributor:contact_verify` | admin | verify a `dist_contacts` number (the verified-link gate) |
| `distributor:notify_manage` | admin | enable/disable the pilot per distributor; draft/approve queue |
| `distributor:view` | admin, sales_leader | see distributor rosters & attribution (read) |

**Distributor plane (partner portal, `docs/47` — NOT the staff RBAC):** a distributor owner/ops user
sees **only their own** attributed customers/leads and their own notification settings — enforced by the
portal's **server-side** `partner_id` scope with composite keys (`docs/47` §10), **not** by the plugin's
presentation-layer `array_filter` (which admins bypass and which gives no real boundary,
`tabs/sales/leads.php:4`). **Sending WhatsApp is never grantable to non-admins** in the staff plane —
`WhatsAppAccess` enforces it server-side (`lib/WhatsAppAccess.php:37-53`) — and nothing here weakens
that.

---

## 9. Distributor onboarding SOP  *(deliverable 9)*

The operational runbook from expression-of-interest to live-in-pilot. Each step is a **deliberate staff
action**; nothing is automatic.

1. **Application received** — `?page=distributor_apply` writes `dist_partner_applications` (`DNP-…`),
   already live (5.18.59). An application **is not an approval**.
2. **Review & due diligence** — staff review in the admin tab; verify business, TIN, coverage.
3. **Appoint** (`distributor:appoint`) — create the `dist_partner` **in uCRM as a company client**
   (dedupe by uCRM id / TIN, never phone — `docs/47`), record `dist_appointment` linking the `DNP-…`
   row to the partner. This is the only step that creates a uCRM record, and it is explicit.
4. **Define territory** (`distributor:territory`) — draw the distributor's `dist_regions`/`dist_outlets`.
5. **Verify contact number** (`distributor:contact_verify`) — confirm the distributor's WhatsApp number
   into `dist_contacts` by a **verification handshake**, never from the form. (A send-a-code or
   call-back step; the verified-link discipline.)
6. **Attribute customers/leads** (`distributor:attribute`) — territory proposes candidates; staff
   confirm `dist_customer_links` (audited). For the pilot this is a small, reviewed set.
7. **Enable the pilot for this distributor** (`distributor:notify_manage`) — flip the per-distributor
   off-by-default switch. Notifications now flow (Flow A/B), in **draft→approve** mode if chosen.
8. **Partner portal access** (`docs/47`) — issue the distributor's portal login (code + TOTP, separate
   hostname). Read-only visibility of their own customers/leads.
9. **Pilot review** — run the acceptance checklist (§15.2); decide go/no-go on widening.

**What this SOP deliberately does NOT do in the pilot:** connect the distributor's own WhatsApp number
to the AI (Flow C, deferred to the transport decision), auto-attribute real customers without review, or
send any commercial/settlement data (a `docs/47` boundary).

---

## 10. Phased implementation plan, estimates and dependencies  *(deliverable 10)*

Estimates are **rough engineering effort** for one developer, in the small-and-independently-releasable
style this repo uses; each phase ships behind an off-by-default flag and is reversible. Phase 0 is this
document.

| Phase | Deliverable | Depends on | Rough effort |
|---|---|---|---|
| **0. Discovery & design** | this doc (`docs/49`) + `docs/47` foundation | — | **done** |
| **1. Distributor entity + appointment** | `dist_partners` (build `docs/47`'s design) + `dist_appointment`; appoint flow turns `DNP-…` into a uCRM company client | `docs/47` approved; **B-2, B-3** (§12) | ~1–1.5 wk |
| **2. Territory + attribution** | `dist_regions`/`dist_outlets` + `dist_customer_links` + `dist_territory_map`; the attribution resolver (candidate → confirmed link) | Phase 1 | ~1–1.5 wk |
| **3. Distributor notifications (pilot core)** | `dist_contacts` (verified) + `dist_notify_consent` + `DistributorNotifier` + the `WhatsAppChannel` **port** with the **Evolution adapter only**; Flow A/B via the **DishNet number** or draft→approve | Phase 2; **B-1** direction (not final transport) | ~1.5–2 wk |
| **4. Partner portal visibility** | distributor read-only view of own customers/leads, server-side `partner_id` scope (`docs/47` §10) | Phase 2; `docs/47` portal | ~1.5–2 wk (portal is a `docs/47` build) |
| **5. (Deferred) per-distributor own number + AI** | instance→distributor map; `distributor_id` carried end to end (the 10 points, §4.3); **Cloud-API adapter if chosen** | **B-1 decided**; transport chosen; Phases 1–3 | ~3–5 wk+ (new transport = more) |

**Critical path:** B-1 direction (§12) → Phase 1 → 2 → 3 = the pilot. Phase 5 is **gated on the
transport decision** and is not part of the pilot.

---

## 11. Security risks and mitigations  *(deliverable 11)*

| Risk | Why it matters here | Mitigation (design) |
|---|---|---|
| **Cross-distributor data leak** | a distributor seeing another's customers/leads; the plugin has **no row-level boundary** today (`tabs/sales/leads.php:4`) | server-side `partner_id` scope with composite keys in the **partner portal** (`docs/47` §10, PD-series), **never** presentation-layer `array_filter`; a distributor never gets a staff/uCRM login |
| **Wrong-customer disclosure in a notification** | a templated distributor alert carrying another customer's balance/data — the exact class of bug the strict matcher replaced | reuse `ReplyPrivacyGuard` allow-list discipline on any distributor copy (`ReplyPrivacyGuard.php:13-28`); attribution **never by phone** (§6.3); money events only off re-verified uCRM payments (`webhook.php:1130`) |
| **Banned distributor number** | pointing automation at an unofficial Evolution/Baileys session, esp. for business-initiated sends | pilot sends from a **DishNet-controlled** number or draft→approve; the distributor's own number is **not** connected until the transport decision (§3); a provider **port** so a sanctioned Cloud-API adapter can replace Evolution for this traffic without a second integration |
| **Spoofed / unsigned inbound** (Flow C, deferred) | Evolution webhooks are **unsigned** (`EvoWebhookGuard.php`); a per-distributor path multiplies the surface | keep per-distributor **webhook secrets** distinct; prefer Cloud-API's **HMAC** if Flow C is built; unmapped instance **rejected not defaulted** (the existing rule, `evo_webhook.php:96-101`) |
| **Consent confusion** | a customer "STOP" must not mute a distributor's legitimate ops alert, yet a distributor must be able to mute their own | separate `CLASS_DISTRIBUTOR` + `dist_notify_consent`; `CLASS_STAFF`-style "never suppressed by the customer" with its own control (`ContactOptOut.php:94`) |
| **Double-notify on retries/webhook replays** | engine dedup **fails open** on store error (`:3052`) | per-distributor dedup key `DIST:<d>:<event>:<id>`; never auto-resend `MAYBE_SENT` (`NotificationRetry.php:59-69`); accept the documented fail-open as a known, logged limitation |
| **Appointment creating uCRM records prematurely** | the assignment forbids creating uCRM records in Phase 0 | appointment is a Phase-1, **explicitly-approved** staff action; nothing in Phase 0 touches uCRM |
| **Scope creep into Domain B / commercial** | distribution is "**Not Domain B**" (`docs/47`); margins/settlements are a commercial boundary | this layer reads customer events and sends alerts; it does **not** enter the MikroTik control plane or send commercial figures |

---

## 12. Business decisions required from Bhavin  *(deliverable 12)*

These block implementation and are **not** mine to assume.

- **B-1 — WhatsApp transport direction (the §3 decision).** Is there an existing Cloud-API effort
  ("CloudBSS" — **no repo by that name is visible to this session**; confirm which of
  `starlink-backend`/`isp-portal`/`starlink_isp_app`/`OperationsHub`, if any, or grant access)? Is
  DishNet's **Meta Business verified**? Is DishNet willing to **migrate a distributor's number to Cloud
  API** (it then can't be a normal WhatsApp app)? What is the **per-conversation budget**? *My
  recommendation: don't bind a live transport for the pilot (§3.2–3.3); decide B-1 before Phase 5.*
- **B-2 — Build `docs/47`'s `dist_partners` now?** The distributor entity is `docs/47`'s design and is
  unbuilt. Phase 1 depends on approving that build.
- **B-3 — Appointment creates a uCRM company client — confirm the dedupe key** (uCRM id / TIN, never
  phone, per `docs/47`) and who may appoint.
- **B-4 — Draft→approve vs auto-send for distributor notifications in the pilot?** Draft→approve is
  safest (human releases each); auto-send is lighter-touch. *Recommend draft→approve for the pilot.*
- **B-5 — Which events in the pilot?** §7 proposes three (new lead, payment received, new activation).
  Confirm or trim.
- **B-6 — Territory auto-attribution of real customers: pilot = human-confirmed only?** *Recommend yes*
  (§6.4); full auto-attribution is a later, separately-approved step.
- **B-7 — Partner portal in the pilot, or staff-visible only first?** The portal is a `docs/47` build
  (Phase 4). The pilot can prove Flow A/B (Phases 1–3) before the portal exists.

---

## 13. Test plan and acceptance  *(deliverable 13)*

Mirrors this repo's discipline: a named suite test per behaviour, **controls on the controls**, and
proof that a guard fails when its subject is removed. No production data; synthetic fixtures only.

**Unit / service (new suites):**
- `test_dist_attribution.php` — territory → candidate; ambiguity returns `ambiguous` and assigns nobody;
  **never matches by phone** (control: a phone-only input attributes nothing); relink is audited with
  previous+new+reason.
- `test_dist_recipient_resolve.php` — resolves only a **verified** `dist_contacts` number; an unverified
  number resolves **nothing**; >1 verified → ambiguous/refused (the `JobNotifier` discipline).
- `test_dist_notify_consent.php` — a **customer** opt-out never suppresses a `CLASS_DISTRIBUTOR` alert;
  a **distributor** mute does; control: flipping the class back proves the suppression was real.
- `test_dist_notify_once.php` — `DIST:<d>:<event>:<id>` dedup claim-before-send; a webhook replay sends
  once; a `MAYBE_SENT` is **not** auto-resent.
- `test_dist_privacy.php` — a distributor alert built from a customer event carries **no other
  customer's** identifiers/money (the `ReplyPrivacyGuard` allow-list), proven by a planted foreign value
  being stripped.
- `test_dist_isolation.php` — the partner-portal scope returns only the distributor's own
  customers/leads; control: a second distributor's rows are absent **and** a deliberately-widened scope
  makes the test fail (proving the boundary is real, not vacuous).
- `test_dist_preserve.php` — with the pilot flag **off**, Uganda and South Sudan notification/lead
  behaviour is **byte-for-byte unchanged** (the existing suites still pass unmodified).

**Integration (synthetic, fake uCRM + fake channel):** a seeded customer attributed to a seeded
distributor → a re-verified `payment.add` → exactly one distributor alert to the verified number, one
`dist_notify_log` row, zero customer-facing change.

**Acceptance gates (must all hold before the pilot widens):** full existing suite green and unchanged
for South Sudan; the seven new suites green; a dry-run (draft mode) shows correct recipient + content +
dedup with **no** real send; the privacy and isolation controls demonstrably fail when their guard is
removed.

---

## 14. Rollback and release strategy  *(deliverable 14)*

- **Every phase is a pinned, rehearsed deploy** in this repo's established pattern (`deploy-5.18.NN.sh`
  + a rehearsal harness that runs the script end-to-end against a fake docker/web and a real clone, as
  `scripts/harness/deploy-5.18.60/` does). The harness asserts before-evidence, the deploy, the switch
  states, and a **mutant/teeth** check (a control proven to fail when its subject is broken).
- **Off by default, per distributor.** The master switch and each distributor's pilot flag default
  **off**; turning them on is a deliberate, logged config change (the `NotifyGate`/5.18.54 style).
  Turning the flag off is a complete, immediate rollback of behaviour with no data migration.
- **Additive migrations only** (§5): a rollback drops *new* tables; **no existing table or column is
  altered**, so South Sudan and Uganda baselines are recoverable by disabling the flag alone.
- **Never hand over a deploy command and its rollback in one copyable block** — pasted together the
  shell runs both (this repo's own 27-Sep incident). Deploy and rollback go in separate, clearly-labelled
  steps, and the operator runs them.
- **Draft→approve is itself a rollback posture for the messaging risk:** if any distributor send looks
  wrong, the human simply does not approve it, and nothing reaches a real number.
- This session **cannot deploy** (no SSH, egress 403); every production step is handed to the operator
  as a pinned command + independent verification, exactly as the 5.18.x and Domain-B staging deploys
  were.

---

## 15. Recommendation — the smallest safe pilot, and the decisions it waits on

### 15.1 The smallest safe pilot

**One distributor, one Ugandan territory, three inbound-triggered notifications, sent from a
DishNet-controlled number in draft→approve mode — and no new live WhatsApp integration.**

Concretely, the pilot is **Phases 1–3**:

1. Appoint one real distributor as a `dist_partner` (uCRM company client, `docs/47` rules) — the one
   deliberate uCRM write, explicitly approved.
2. Draw their **one territory** and **confirm** a small, reviewed set of attributed customers/leads
   (`dist_customer_links`, human-confirmed — no silent auto-attribution).
3. Verify **one** distributor WhatsApp number into `dist_contacts`.
4. Enable the pilot flag for that distributor; the three events (**new lead attributed, customer payment
   received, new customer activated**) produce **draft** distributor alerts that a staff member releases.

This proves the entire missing spine — entity → territory → attribution → recipient resolution → consent
→ dedup → delivery — **with zero ban/verification risk and zero change to Uganda, South Sudan, or Domain
B.** It deliberately does **not** connect the distributor's own number to the AI (Flow C), which waits on
the transport decision.

### 15.2 Pilot acceptance checklist (10 items)

1. Exactly one `dist_partner` appointed, linked to its `DNP-…` application, as a uCRM company client
   deduped by uCRM id/TIN (not phone).
2. One territory defined; the attribution resolver proposes candidates and a human confirmed the links
   (provenance recorded).
3. The distributor's number is **verified** (handshake), not taken from the form.
4. A seeded/real customer **payment** produces **one** correctly-addressed distributor draft; dedup
   blocks a replay.
5. A **new lead** in the territory produces **one** attributed draft.
6. A **new activation** produces **one** draft.
7. **No** distributor alert ever contains another customer's data (privacy control green, and proven to
   fail when removed).
8. A **customer** "STOP" does **not** suppress the distributor alert; the **distributor** can mute their
   own.
9. South Sudan behaviour **unchanged**; Uganda customer-facing behaviour **unchanged** with the flag on
   (only the new distributor copy is added).
10. The flag turned **off** fully restores pre-pilot behaviour with no residue.

### 15.3 Decisions required before the pilot can begin

- **B-2** (approve building `docs/47`'s `dist_partners`) and **B-3** (appointment dedupe key + who may
  appoint) — both block Phase 1.
- **B-4** (draft→approve vs auto-send — recommend draft→approve), **B-5** (confirm the three events),
  **B-6** (human-confirmed attribution for the pilot — recommend yes).
- **B-1** (the Evolution-vs-Cloud-API direction) is **not** required to start the pilot — the pilot
  rides the DishNet number — but it **is** required before Phase 5 (per-distributor own number + AI), and
  the sooner the business settles it, the sooner the provider port can be pointed at the right adapter.
  It needs the CloudBSS/Meta inputs in §12.

### 15.4 One input that sharpens §3 and §12

There is **no repository named "CloudBSS"** visible to this session. If the official Cloud-API work lives
in one of the candidate repos (`starlink-backend`, `isp-portal`, `starlink_isp_app`, `OperationsHub`) or
elsewhere, naming it (or granting access) lets me inspect it and turn B-1 from a business question into a
concrete "extend X" plan. Until then, §3's recommendation holds on the evidence available: **build the
missing attribution/notification spine now behind a provider port, run the pilot on the DishNet number,
and keep the transport decision open.**

---

*References: root `docs/47` (distribution foundation — entity, territory, portal, isolation, Not-Domain-B,
D-1…D-15), `docs/45`/`docs/46` (notification engine + reliability fixes), `docs/48` (the live recruitment
page). Evidence gathered by read-only code maps over `dishnet-hybrid-sudan/` on 1 Oct 2026; nothing was
executed or changed. This is Phase 0 — design only; implementation and every production-affecting action
await explicit approval.*
