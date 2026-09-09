# Starlink integration for Uganda — gap analysis and migration plan

Comparison of `dishnet-data-report` v2.8.80 (the fleet plugin) against
`dishnet-hybrid-sudan` as it stands, with a recommendation for Uganda.

No production code has been changed. This is the plan to argue with before
anything is built.

---

## 1. The answer in short

Hybrid stays the only Uganda-facing plugin. It gains its own Starlink
connector rather than a dependency on the fleet plugin, because Uganda needs
perhaps a fifth of what that plugin does and taking all of it would import a
fragile session layer, two foreign data stores and a postpaid billing model
Uganda does not use.

Three findings changed the shape of this plan:

1. **Hybrid already has the store I was about to recommend building.**
   `StockService` tracks serial-numbered units, their movements, their
   location — including `customer` — and purchase receipts linked to the
   cashbook ledger. The `KitRegister` I added earlier today duplicates it
   inside the same plugin. That was my mistake: I built a new store without
   checking for the existing one. It should be folded into StockService
   before it holds anything real.

2. **The fleet plugin has no API integration at all.** Every Starlink call
   is a cookie-authenticated portal request. The `api.starlink.com` URLs are
   the same `webagg/v2` portal backend on a fallback host, not an API.

3. **Hybrid talks to Starlink nowhere.** Everything it "knows" arrives via
   the Finance plugin's file, the fleet plugin's endpoints, or supplier
   emails. There is no existing Starlink client to extend — which means the
   connector can be designed properly rather than retrofitted.

---

## 2. Authentication: what is actually there

| Question | Finding |
|---|---|
| Official Starlink API used? | **No.** No OAuth, no client credentials, no bearer token anywhere against Starlink. |
| What `api.starlink.com` is doing | `webagg/v2/accounts/service-lines` and `auth-rp/auth/user` — the portal's own backend on a second host, tried when `starlink.com` fails. Same cookie. |
| Actual mechanism | A logged-in browser session: cookie rotation, fingerprint pinning, throttling (`session_manager.php`, 962 lines, 242 cookie references). |
| Google OAuth present | Yes — but only for Drive backup, unrelated to Starlink. |

**What this means.** The fleet plugin automates a human login. It breaks when
Starlink changes the portal, and a session flagged as automation risks the
account that the whole business runs on. In Sudan that risk buys fleet-wide
telemetry across many terminals. In Uganda, today, it would buy very little.

**Recommendation.** Ask Starlink for Enterprise/Partner API access for the
Uganda account before building anything session-based. If it is granted, the
connector speaks to a documented API with a token. If it is not, the same
connector interface wraps the portal session — isolated in one class, so the
day an API arrives it is one implementation swapped, not a rewrite.

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
| Usage telemetry | Yes (5-minute keep-alive) | No | Not yet | **Defer** — no value at this scale |
| Kit → customer handover | No (Starlink cannot know) | **Yes — StockService** | Yes | **Hybrid owns. Retire `KitRegister`** |
| Customer / billing relationship | No | Yes (uCRM) | Yes | uCRM stays authoritative |
| WiFi management (SSID/password) | Yes (`dr_wifi_change.php`, gRPC to router) | No | Maybe | **Evaluate later** — needs router reachability |
| Auto-block for non-payment | Yes (`cron_auto_block.php`) | Bridge exists, calls fleet plugin | **No** | **Do not port** — Uganda is prepaid |
| Portal session management | Yes (962 lines) | No | Only if no API | **Isolate behind the connector** |
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

**`StarlinkConnector`** — one class, one job: authenticate and return JSON.
Everything about cookies, rotation, throttling and retries lives behind it,
and nothing above it knows which mechanism is in use. Two implementations
share the interface: `StarlinkApiConnector` (preferred, if access is granted)
and `StarlinkPortalConnector` (adapted from `session_manager.php`). Selected
by config. This is the whole reason to build rather than depend: the fleet
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

## 6. Starlink invoices and Uganda tax — the highest-value piece

This is the part Sudan never needed and Uganda genuinely does.

A Starlink invoice is a **supplier bill in USD for imported equipment and
service**. Uganda needs it for import cost, input VAT and the EFRIS/URA
record. Sudan needed none of that, so there is no existing implementation to
copy — only a spine to build on (`stock_purchases`, already carrying
`supplier`, `invoice_number`, `currency`, `total_cost`, `cb_ledger_id`).

Design rules:

1. **Store the original verbatim.** The raw payload and the invoice PDF where
   available, unmodified. Every derived number must be traceable to it.
2. **Normalise separately.** Uganda fields — invoice date, USD amount, UGX
   amount at the rate on the date, import duty, input VAT, EFRIS treatment —
   live beside the raw record and never overwrite it.
3. **No silent currency conversion.** One currency per ledger row, as
   everywhere else in this plugin. A USD invoice is booked in USD with the
   rate recorded, not quietly turned into UGX.
4. **Never fabricate a tax value.** If input VAT cannot be determined from
   the document, it is absent and flagged, not estimated.
5. `ssp_rate` on `stock_purchases` is Sudan-specific and wrongly named for a
   second country. It needs a generic `fx_rate` + `fx_currency` pair, with
   `ssp_rate` preserved so the Sudan install stays byte-identical.

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

**Phase 0 — clean up first (small, and it prevents a fourth store).**
Retire `KitRegister`; move the service-line link and supplier-status ideas
into `StockService`. Guard the `StarlinkBlockBridge` call in `webhook.php`
by billing model, so a prepaid Uganda suspension stops reaching for a plugin
that is not installed.

**Phase 1 — the connector, read-only.**
`StarlinkConnector` with the API implementation if access is granted, the
portal implementation if not. One command that authenticates and lists
service lines, and nothing else. Proves the account works before anything
depends on it.

**Phase 2 — invoices.**
`StarlinkInvoiceSync`: raw storage, normalised Uganda fields, no accounting
posting yet. Read and verify against invoices you already have.

**Phase 3 — the Uganda accounting layer.**
Posting into `stock_purchases` and the cashbook, input VAT and EFRIS
treatment, behind a switch that starts off.

**Phase 4 — orders, kits, service lines.**
Feeding StockService so an ordered kit becomes a tracked unit and, on
handover, a customer's unit.

**Phase 5 — evaluate telemetry and WiFi management** on evidence, not
enthusiasm.

Each phase is independently switchable and independently reversible, and the
Sudan install must remain byte-identical throughout — every new behaviour
behind config whose absence is the old behaviour.

---

## 9. What I need from you before Phase 1

1. **Starlink API access** — has Uganda been offered Enterprise/Partner API
   access, or is the portal login all we have? This decides the connector.
2. **The Uganda Starlink account email**, and whether it is the same account
   the kits were bought under.
3. **Router reachability** — are customer Starlink routers reachable from the
   server, or only on the customer's own LAN? This decides whether the WiFi
   manager is portable at all.
4. **Invoice history** — how many Starlink invoices exist for Uganda so far,
   and do you have them as PDFs? That sets whether Phase 2 backfills or only
   watches forward.
