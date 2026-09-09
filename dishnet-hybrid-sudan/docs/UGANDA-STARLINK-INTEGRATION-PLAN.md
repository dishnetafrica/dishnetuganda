# Starlink integration for Uganda — gap analysis and migration plan

Comparison of `dishnet-data-report` v2.8.80 (the fleet plugin) against
`dishnet-hybrid-sudan` as it stands, with a recommendation for Uganda.

No production code has been changed. This is the plan to argue with before
anything is built.

---

## 1. The answer in short

Hybrid stays the only Uganda-facing plugin. It gains its own Starlink
connector rather than a dependency on the fleet plugin, because Uganda needs
perhaps a fifth of what that plugin does and taking all of it would import
two foreign data stores and a postpaid billing model Uganda does not use.

**Decided:** Uganda starts on the same web-session mechanism South Sudan
already runs on. Enterprise API access is being pursued but nothing waits for
it. The mechanism is adapted from the proven implementation and isolated
behind one connector, so the day an API arrives it is one class swapped and
nothing above it changes.

Three findings shaped this plan:

1. **Hybrid already has the store I was about to recommend building.**
   `StockService` tracks serial-numbered units, their movements, their
   location — including `customer` — and purchase receipts linked to the
   cashbook ledger. The `KitRegister` I added earlier duplicates it inside
   the same plugin. That was my mistake: I built a new store without checking
   for the existing one. It is retired in Phase 0, before it holds anything.

2. **The fleet plugin has no API integration at all**, and no Starlink
   password either. Every call is a cookie-authenticated portal request, and
   the cookie is imported by a person from a logged-in browser (see §2).

3. **Hybrid talks to Starlink nowhere.** Everything it "knows" arrives via
   the Finance plugin's file, the fleet plugin's endpoints, or supplier
   emails. There is no existing Starlink client to extend — so the connector
   can be designed properly rather than retrofitted.

---

## 2. Authentication: what is actually there, and what Uganda will use

| Question | Finding |
|---|---|
| Official Starlink API used? | **No.** No OAuth, no client credentials, no bearer token anywhere against Starlink. |
| What `api.starlink.com` is doing | `webagg/v2/accounts/service-lines` and `auth-rp/auth/user` — the portal's own backend on a second host, tried when `starlink.com` fails. Same cookie. |
| **Is a Starlink password stored?** | **No.** Nowhere in `session_manager.php` or `cron_session.php`. This matters more than it first appears. |
| How a session begins | A person signs in to Starlink in a browser and imports the session cookie. The plugin never holds the credential that could change the account. |
| How it stays alive | `smSilentReauth()` against Starlink's own `session/refresh` and `token/refresh` endpoints, alternating per account so neither is hammered; `smHeartbeat()` verifies the refreshed cookie actually works before keeping it. |
| When it dies | The account is flagged `needs_manual_reimport` and a person pastes a fresh cookie. A known, visible failure mode — not a silent outage. |
| Cookie at rest | **Encrypted** (`smEncrypt`/`smDecrypt`, key derived per plugin directory by `smDeriveKey`). |

**What this means for the risk assessment.** My first draft called this a risk
to "the account the whole business runs on". That was overstated. No password
is held, so the worst case is a dead session and a manual re-import — an
interruption, not a loss of control of the account. The real costs are the
portal changing under us, and a person having to re-import periodically.

**Decision.** Uganda uses this mechanism now, adapted from the working
implementation rather than reinvented. The connector interface is written so
`StarlinkApiConnector` can replace `StarlinkPortalConnector` later without
anything above it noticing.

### What gets ported, by name

`session_manager.php` is a well-factored set of procedural `sm*` functions.
These become static methods on `StarlinkPortalConnector` — **not** copied as
globals, because two plugins defining `smCurl()` in one PHP process is a
fatal error waiting for the day both are loaded together.

| Function | Ported? | Why |
|---|---|---|
| `smDeriveKey` / `smEncrypt` / `smDecrypt` | **Yes** | Encrypted cookie at rest. Better than Hybrid's own vault, which is plaintext at restricted permissions — this raises Hybrid's bar rather than lowering it. |
| `smCurl`, `smBuildHeaders`, `smMergeCookies` | **Yes** | The HTTP and cookie-merge layer. `Set-Cookie` merging is fiddly and this version is proven. |
| `smPickFingerprint` | **Yes** | A stable browser fingerprint per session. |
| `smPaceRequest`, `smIsThrottled`, `smRecordThrottle`, `smClearThrottle` | **Yes** | Throttle handling. Skipping this is how an account gets flagged. |
| `smHeartbeat`, `smSilentReauth` | **Yes** | The keep-alive that makes an imported cookie last. |
| `smLoadAccounts`, `smSaveAccounts`, `smUpdateAccountState` | **Yes, Uganda-scoped** | Per-account state and its failure counters. |
| `smPickNextAccount`, `smProcessAccount` | **Not yet** | Multi-account rotation. Uganda has one account; rotation logic for a fleet of one is complexity with no payoff. The shape is preserved so it can return. |
| `smGetSessionSummary`, `smTimeAgo` | Optional | Display helpers for an admin panel. |
| `smMigrateEncryption` | **No** | Migrates the fleet plugin's own legacy plaintext cookies. Uganda has no legacy. |

### Isolation: the mechanism travels, the credentials never do

Uganda must not share a session, cookie, account record or data file with
South Sudan. The design gives this almost for free — `smDeriveKey()` derives
the encryption key from the plugin directory, so Hybrid's key is already a
different key — but it is stated as a rule rather than left to luck:

- Uganda's account state lives in **Hybrid's own data directory**, written
  through `SecureFile`, never in the fleet plugin's `dr_accounts.json`.
- Hybrid **never reads** `dr_accounts.json`, `sl_kits.json` or
  `dr_kit_registry.json`. A test asserts this, so it cannot creep back in.
- The Uganda Starlink account is named in config
  (`starlink_account_email`, `starlink_account_number`), never hardcoded.
- Cookies are copied from a browser **by a person, into Uganda's own store**.
  No cookie is ever moved from the Sudan installation to this one.

---

## 3. Capability comparison

| Capability | Fleet plugin | Hybrid today | Needed in Uganda | Action |
|---|---|---|---|---|
| Starlink orders / shipments | Yes (`cron_orders.php`, hourly) | No | Yes | **Port as `StarlinkOrderSync`** |
| Starlink invoices + line items | Yes (`cron_invoice_details.php`, daily) | No | **Yes — highest value** | **Port as `StarlinkInvoiceSync`** |
| Kit / terminal serials | Yes (`KitRegistryWriter`) | Partly (`StockService` serials; `KitRegister` duplicate) | Yes | **Sync into StockService** |
| Service lines | Yes (`webagg` discovery) | No | Yes | **Port as `StarlinkServiceLineSync`** |
| Activation / service status | Yes | No | Yes | Port with service lines |
| Service plans | Yes (`dr_plan_cache.json`) | Prices live in uCRM | Useful | Port read-only, for reconciliation |
| Usage telemetry | Yes (5-minute keep-alive) | No | Not yet | **Defer to Phase 6** — no value at this scale |
| Kit → customer handover | No (Starlink cannot know) | **Yes — StockService** | Yes | **Hybrid owns. Retire `KitRegister`** |
| Customer / billing relationship | No | Yes (uCRM) | Yes | uCRM stays authoritative |
| WiFi management (SSID/password) | Yes (`dr_wifi_change.php`, gRPC to router) | No | Maybe | **Evaluate later** — needs router reachability |
| Auto-block for non-payment | Yes (`cron_auto_block.php`) | Bridge exists, calls fleet plugin | **No** | **Do not port** — Uganda is prepaid |
| Portal session management | Yes (962 lines) | No | **Yes — from Phase 1** | **Adapt behind the connector** (§2) |
| Google Drive backup | Yes | Yes (already) | Yes | **Do not port** — duplicate |
| Customer-facing Starlink portal | Yes (`client.php`) | Customer app exists | Not yet | Defer |
| Full historical scan | Yes | No | No | Not yet — nothing to scan |

---

## 4. One owner per kind of data

The problem this plan must not repeat is the one the fleet plugin's own
registry was written to end: kit data scattered across four files with no
answer to "which is right". Uganda gets one owner per fact.

| Data | Owner in Uganda | Why |
|---|---|---|
| What Starlink says exists — orders, service lines, kit serials, invoices, plans | **Hybrid `starlink_*` tables**, synced | Uganda will not run the fleet or Finance plugins, so their files will not exist. Hybrid syncs and owns its own copy. |
| The raw Starlink invoice, exactly as received | **Hybrid, stored verbatim** | An audit needs the original, not our interpretation of it. |
| Uganda accounting treatment of that invoice | **Hybrid, separate fields** | Import cost, input VAT, EFRIS. Never overwrites the raw record. |
| Physical unit, movements, who holds it | **Hybrid `StockService`** | Already exists, richer than anything proposed: statuses, movement types, locations including `customer`. |
| Purchase receipt and its ledger entry | **Hybrid `stock_purchases`** → `cb_ledger_id` | Already links a supplier purchase to the cashbook. |
| Customer, service, invoice, payment, suspension | **uCRM** | Unchanged. Single source of truth for the relationship. |
| `dr_kit_registry.json`, `sl_kits.json` | **Not used in Uganda** | Owned by plugins Uganda will not install. If either is ever installed here, read-only, per its stated contract. |

### The `KitRegister` decision

`KitRegister` (added today) and `StockService` answer the same question. Two
tables that both claim to say which kit a customer has will disagree, and the
disagreement will be discovered by a customer.

Recommendation: **retire `KitRegister` before it holds real data.** Its two
genuinely new ideas move into StockService:

- link a unit to a Starlink service line and kit serial, so the physical
  unit and the Starlink record are the same object;
- record a supplier-observed status (`ordered`, `shipped`, `active`) that is
  distinct from our own physical status (`in_stock`, `installed`).

The one row currently in it is the fictional `KIT-0123-4567` from my example
command, so nothing real is lost.

---

## 5. Proposed architecture

```
        Starlink account (UG)                      uCRM
                │                                    │
        StarlinkConnector          ← the only place auth lives
         ├── PortalConnector  (web session — Uganda runs this now)
         └── ApiConnector     (token — swapped in later, same interface)
                │
   ┌────────────┼────────────┬──────────────┐
   │            │            │              │
OrderSync   InvoiceSync   KitSync    ServiceLineSync
   │            │            │              │
   └────────────┴─────┬──────┴──────────────┘
                      │
            Hybrid starlink_* tables        (normalised Starlink truth)
                      │
        ┌─────────────┴─────────────┐
        │                           │
  StockService                UgandaPurchaseLayer
  (physical units,            (import cost, input VAT,
   movements, handover)        EFRIS treatment)
        │                           │
        └───────────┬───────────────┘
                    │
              Cashbook / EFRIS
```

**`StarlinkConnector`** — an interface: authenticate, and return JSON.
Everything about cookies, fingerprints, throttling and retries lives behind
it, and nothing above it knows which mechanism is underneath.
`StarlinkPortalConnector` (adapted from `session_manager.php`) is what Uganda
runs from Phase 1. `StarlinkApiConnector` is the same interface over a token
the day Starlink grants one; the syncs above it do not change, and both can
exist at once with a config key choosing. This is the whole reason to build rather than depend: the fleet
plugin spreads session knowledge across `cron.php`, `dr_wifi_change.php` and
`session_manager.php`, so its auth cannot be replaced without touching all
three.

**The sync classes** are thin: fetch, normalise, upsert, report. Each is
independently switchable, so invoices can run while telemetry stays off.

**Tenancy.** One config key — `starlink_account_email`, plus the existing
country/currency configuration — not a country abstraction. Sudan already
proves the same code serves a second account when the values differ. Building
a multi-tenant layer for one tenant would be inventing a problem.

---

## 5b. South Sudan reference implementation

South Sudan's Starlink integration works. This section records, component by
component, exactly what Uganda takes from it and why — so the audit trail
shows deliberate reuse rather than a second implementation that grew by
accident.

The rule throughout: **the mechanism travels, the data never does.** Every
row below is code. No cookie, session, account number, cached response or
encryption key crosses from Sudan to Uganda.

### Session and transport

| South Sudan component | Uganda Hybrid component | Reuse / Rewrite | Reason |
|---|---|---|---|
| `session_manager.php` `smCurl()` | `StarlinkPortalConnector::request()` | **Reuse, adapted** | Working HTTP layer with the right headers and cookie capture. Becomes a method, not a global. |
| `smBuildHeaders()` | `StarlinkPortalConnector::headers()` | **Reuse** | The header set Starlink accepts. Guessing this again would mean rediscovering it by getting rejected. |
| `smMergeCookies()` | `StarlinkPortalConnector::mergeCookies()` | **Reuse** | `Set-Cookie` merging is fiddly and this version is proven against the live portal. |
| `smPickFingerprint()` | — | **Not ported — corrected** | My first draft said reuse. Reading the source says otherwise: their v2.7.35 note records that rotating user agents and fingerprints was tried as anti-ban protection, broke telemetry calls, and was reverted to a fixed nine-header set that had worked for months. The function survives only in the WiFi manager. Inheriting the fix means not repeating the experiment. |
| `smPaceRequest()`, `smIsThrottled()`, `smRecordThrottle()`, `smClearThrottle()` | `StarlinkPortalConnector` throttle methods | **Reuse** | Skipping throttle handling is how a session gets flagged. Uganda's throttle state file is its own. |
| `smHeartbeat()` | `StarlinkPortalConnector::heartbeat()` | **Reuse** | Verifies a refreshed cookie actually works before it is kept. |
| `smSilentReauth()` | `StarlinkPortalConnector::refresh()` | **Reuse** | Both refresh endpoints and the alternation between them. |
| `smDeriveKey()`, `smEncrypt()`, `smDecrypt()` | `StarlinkSessionStore` | **Reuse** | Encrypted cookie at rest. The key derives from the plugin directory, so Uganda's key differs from Sudan's by construction. |
| `smLoadAccounts()`, `smSaveAccounts()`, `smUpdateAccountState()` | `StarlinkSessionStore` | **Reuse, adapted** | Same state and failure counters — `needs_manual_reimport`, `consecutive_failures`, `cookie_dead_at` — in Hybrid's own store. |
| `smPickNextAccount()`, `smProcessAccount()` | — | **Not ported** | Multi-account rotation. Uganda has one account; the shape is preserved so it can return. |
| `smMigrateEncryption()` | — | **Not ported** | Migrates the fleet plugin's legacy plaintext cookies. Uganda has no legacy. |
| `smGetSessionSummary()`, `smTimeAgo()` | Admin panel, later | **Defer** | Display only. |

### Endpoints — the most valuable thing being reused

These were found by working against the live portal. Rediscovering them means
a sequence of rejected requests against a real account, which is exactly the
behaviour that gets a session flagged.

| Purpose | Endpoint | Used by |
|---|---|---|
| Service lines (account) | `/api/webagg/v2/accounts/{account}/service-lines` | ServiceLineSync |
| Service lines (all) | `/api/webagg/v2/accounts/service-lines` | ServiceLineSync |
| Service line numbers | `/api/accounts/v1/accounts/service-line-numbers` | ServiceLineSync |
| Account contact | `/api/accounts/v3/accounts/contact` | Connector verification |
| Invoice balances | `/api/webagg/v1/public/billing/invoice-balances/{invoice}` | InvoiceSync |
| Data usage | `/api/telemetryagg/v1/data-usage/account/{a}/service-line/{s}/annotated` | Deferred (Phase 6) |
| Session refresh | `/api/auth/v1/session/refresh`, `/api/auth/v1/token/refresh` | Connector |
| Fallback host | `api.starlink.com` for the same paths | Connector |

Kit serials arrive inside the service-line response (`userTerminals[0].serialNumber`)
rather than from a separate endpoint — the discovery that made the fleet
plugin's own registry possible, and the reason KitSync is a consumer of
ServiceLineSync rather than a separate fetch.

### Data shaping

| South Sudan component | Uganda Hybrid component | Reuse / Rewrite | Reason |
|---|---|---|---|
| `cidNormalizeInvoice()` (`cron_invoice_details.php`) | `StarlinkInvoiceSync::normalise()` | **Reference, rewritten** | Its shape is right — raw content in, normalised record out, line items preserved. Rewritten because Uganda's record keeps fields Sudan does not (billing period, tax as shown, service and kit references, document path) and adds the accounting layer beside it. |
| `cidAtomicWriteJson()` | — | **Not ported** | Hybrid stores in SQLite, and `SecureFile` already does atomic writes with correct ownership. |
| `KitRegistryWriter::regenerate()` | `StarlinkKitSync` → `StockService` | **Reference, not ported** | Its merge-order logic is instructive, but it writes a JSON file owned by a plugin Uganda will not run. Uganda's kits land in `StockService`, which already owns physical units. |
| `KitRegistryWriter::newKitRecord()` | — | **Reference** | Useful as a field checklist for what is worth knowing about a kit. |
| `cron_session.php` orchestration | `cron/starlink_sync.php` | **Rewrite** | Sudan's loop paces work across many accounts on a five-minute cycle. Uganda has one account and needs invoices daily. Same connector underneath; a schedule that matches the job. |
| `cron_auto_block.php` | — | **Not ported** | Postpaid suspension. Uganda is prepaid — see §7. |

### What this buys

The parts genuinely expensive to rediscover — the header set, cookie merging,
the refresh endpoints and their alternation, throttle backoff, the fact that
kit serials ride inside the service-line payload — all come across. The parts
that are cheap to write and specific to Uganda — record shape, scheduling,
accounting — are written for Uganda rather than inherited from a country with
different needs.

---

## 6. Starlink invoices and Uganda tax — the highest-value piece

This is the part Sudan never needed and Uganda genuinely does. A Starlink
invoice is a **supplier bill in USD for imported equipment and service**, with
import cost, input VAT and an EFRIS/URA consequence.

Scale is small and that is a gift: **roughly 10–12 invoices exist**. This is
one afternoon's initial sync followed by ongoing detection, not a migration
project. It also means every imported invoice can be checked by eye against
the real one — which will never be true again, so Phase 2 should be verified
now rather than trusted later.

### The record

A Starlink invoice must stay traceable to its source. It is never flattened
into a generic supplier bill with the Starlink reference discarded.

| Field | Source | Note |
|---|---|---|
| `starlink_invoice_number` | Starlink | The natural key. Idempotency depends on it. |
| `invoice_date` | Starlink | As issued, not as synced. |
| `billing_period_start` / `_end` | Starlink | A service invoice covers a period; an equipment one may not. |
| `currency` | Starlink | Recorded, never assumed to be USD. |
| `subtotal`, `tax_amount`, `total` | Starlink | `tax_amount` is whatever Starlink itself shows — not our VAT calculation. |
| `service_reference`, `order_reference` | Starlink | Ties an invoice to what it was for. |
| `service_line_ids`, `kit_serials` | Starlink | Where the invoice names them. This is what links a bill to a physical unit. |
| `raw_json` | Starlink | The response exactly as received. Every derived number must be traceable to it. |
| `document_path` | Starlink | The invoice PDF where one is available. |
| `synced_at`, `sync_status`, `source` | Hybrid | When we saw it, whether the import completed, and by which mechanism. |

### Uganda accounting, kept separate

Beside the record above, never overwriting it:

`ugx_amount`, `fx_rate`, `fx_rate_date`, `import_duty`, `input_vat`,
`efris_treatment`, `posted_to_ledger_id`, `posted_at`, `reviewed_by`.

Rules, unchanged from the rest of this plugin:

1. **The original is immutable.** Re-syncing an invoice may fill gaps in the
   raw record; it may never rewrite an accounting field a person has reviewed.
2. **No silent currency conversion.** A USD invoice is booked in USD with the
   rate and its date recorded. One currency per ledger row.
3. **Never fabricate a tax value.** If input VAT cannot be determined from the
   document, it is absent and flagged for a person — not estimated.
4. **Idempotent by construction.** `starlink_invoice_number` is UNIQUE. A
   re-run updates the raw record and touches nothing else. Importing the same
   invoice twice must be impossible, not merely unlikely.
5. `ssp_rate` on `stock_purchases` is Sudan-specific and wrongly named for a
   second country. It needs a generic `fx_rate` + `fx_currency` pair, with
   `ssp_rate` preserved so the Sudan install stays byte-identical.

### How it reaches the books

`starlink_invoices` → reviewed → `stock_purchases` (supplier, invoice number,
date, cost, currency) → `cb_ledger_id` into the cashbook. The path already
exists; what is new is the Starlink record in front of it and the Uganda tax
fields beside it.

---

## 7. What stays behind, and why

| Not ported | Reason |
|---|---|
| `cron_auto_block.php` | Uganda is prepaid: the model is pause/resume, not suspend-for-arrears. The dunning gate already blocks the postpaid path. Porting this would build the wrong billing model. |
| `cron_session.php` 5-minute keep-alive | Per-cycle telemetry for a handful of service lines is cost without benefit. Revisit when the fleet justifies it. |
| `full_history_scan.php` | Nothing to scan. Useful the day Uganda has years of history. |
| `backup.php` / `cron_backup.php` | Hybrid already backs up to Drive. A second backup system is a second thing to get wrong. |
| `client.php` (portal replica) | Hybrid has a customer app. Two customer-facing surfaces would drift. |
| `dr_wifi_change.php` | 4,551 lines reaching customers' routers over gRPC. Genuinely useful — one avoided site visit pays for it — but it needs router reachability that has not been established in Uganda. Evaluate separately, on evidence. |
| `KitRegistryWriter` | Its output is owned by a plugin Uganda will not run. The idea (one canonical kit view) is kept; the file is not. |

---

## 8. Migration plan

**Phase 0 — clean up first.** Approved, and independent of everything else.
Retire `KitRegister`, folding its two genuinely new ideas into `StockService`:
a link from a unit to its Starlink service line and kit serial, and a
supplier-observed status distinct from our own physical status. Guard the
`StarlinkBlockBridge` call in `webhook.php` by billing model, so a prepaid
Uganda suspension stops reaching for a plugin that is not installed. Leave
`cron_auto_block` off and add no postpaid suspension logic.

**Phase 1 — the connector, read-only.**
`StarlinkConnector` (interface) + `StarlinkPortalConnector` (adapted from
`session_manager.php`, per §2). Cookie import, encrypted storage, heartbeat,
silent re-auth, throttle handling. One command: authenticate and list service
lines. Nothing else depends on it yet, so a failure here costs nothing.

**Phase 2 — invoices, read and store.**
`StarlinkInvoiceSync`: fetch all ~12, store raw plus normalised fields, no
accounting posting. Then check them by eye against the real invoices, which is
only affordable at this size.

**Phase 3 — the Uganda accounting layer.**
Review, then post into `stock_purchases` and the cashbook, with input VAT and
EFRIS treatment. Behind a switch that starts off, because this one writes to
the books.

**Phase 4 — orders, kits, service lines.**
Feeding `StockService`, so an ordered kit becomes a tracked unit and, on
handover, a customer's unit — linked to the Starlink service line that bills
for it.

**Phase 5 — ongoing detection.**
The scheduled run that notices new invoices and orders. Deliberately last:
detection is only useful once import is proven, and a scheduled importer that
is wrong is wrong repeatedly and unattended.

**Phase 6 — evaluate telemetry and WiFi management** on evidence.

Each phase is independently switchable and reversible, and the Sudan install
stays byte-identical throughout — every new behaviour behind config whose
absence is the old behaviour.

---

## 9. What is still open

Answered, and now settled in this plan: Uganda uses the web-session mechanism
now (§2); there are ~10–12 invoices, so the initial sync is small (§6); Phase 0
is approved; `cron_auto_block` stays off and no postpaid suspension logic is
added; the WiFi manager is not a Phase 1 dependency.

Still needed, and each blocks a specific phase rather than the whole plan:

1. **The Uganda Starlink account** — the email it signs in with, its account
   number, and confirmation that it is the account the kits were bought
   under. *Blocks Phase 1.*
2. **A session cookie for that account**, imported the way South Sudan's was.
   Not sent through chat — it goes into Hybrid's own encrypted store on the
   server. *Blocks Phase 1.*
3. **The invoices you already hold** — PDFs or not. Not required to build
   Phase 2, but they are what Phase 2 gets checked against, and that check is
   only cheap while there are twelve of them. *Blocks verification, not code.*
4. **Router reachability** — are customer Starlink routers reachable from the
   server, or only on the customer's own LAN? *Blocks Phase 6 only.*
