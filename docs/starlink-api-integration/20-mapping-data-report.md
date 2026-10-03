# 20 — Function→official-API mapping: `dishnet-data-report` v2.8.80

**Deliverable #2.** The exhaustive, item-by-item mapping from each existing data-report function
to the **official Starlink Public API V2** (catalogue `01`), with a **preservation verdict** for
each. **Read-only / assessment only — recommends, decides nothing, changes nothing.**

**Two anchors, two trust levels.** The *plugin* side is the source inventory in `10` (file:line
into `dishnetafrica/datareport` @ `012810d6`). The *official-API* side is the spec-verified
catalogue in `01` (labelled [V]/[D]/[✗] there). Where the spec did not fully enumerate a
sub-schema, the verdict is **Unknown** and the open field is pushed to `40`.

**Each function block below covers the 10 required columns:** *Current source · Output+purpose ·
Fields used · Official V2 endpoint+fields · Transformation · Preserve exactly? · Missing API
fields · Recommended approach · Tests & regression risks* (plus a short *Note* where the financial
or security caveat bites).

**Verdict legend:** **Yes** = official API carries every field the behaviour needs ·
**Partial** = core carried, some fields lost/reshaped · **No** = no official equivalent ·
**Unknown** = depends on a spec detail not verifiable from the JSON (listed in `40`).

---

## Summary of verdicts (detail follows)

| # | Function area | Current (scraped) | Official V2 target | Verdict |
|---|---|---|---|---|
| ACCT | Account / contact sync | `accounts/v3/accounts/contact` | `GET /account` | **Partial** |
| SL | Service-line + router discovery | `webagg/v2/accounts/service-lines` | `GET /service-lines` + `/user-terminals` + `/addresses/{id}` | **Partial** |
| USE | Usage / data telemetry (GB) | `telemetryagg/v1/…/annotated` | `POST /data-usage/query` | **Partial** |
| INV-L | Invoice list / balances | `webagg/v1/public/billing/invoice-balances` | `GET /billing/invoices` + `/billing/balance` | **Partial** |
| INV-D | Invoice line detail | `webagg/v1/public/invoice/{INV}` | `GET /billing/invoices/{invoiceId}` | **Partial** |
| ORD | Orders / shipment tracking | `webagg/v1/public/orders/customer-account` | — (none) | **No** |
| SUB | Subscription / plan catalog | `subscriptions/v1` (diag) + webagg `subscription.*` | `GET /products` + `ServiceLineResponse.productReferenceId` | **Partial** |
| DEV-ST | Dish-online state / client counts | gRPC `get_status` (1004) | — (reboot only; no telemetry) | **No** |
| DEV-WIFI | Read/write SSID & password | gRPC `wifi_get_config`/`wifi_set_config` | — (router configs ≠ consumer WiFi) | **No** |
| DEV-PAUSE | Per-client pause / test-block / auto-block actuation | gRPC + self-HTTP | — (no per-client pause; reboot only) | **No** |
| AUTH | Cookie vault / refresh / XSRF / throttle | pasted cookie + `auth-rp`/`session/refresh` | OIDC client-credentials | **Yes** (replace; the one clean win) |
| KIT-REG | KIT registry merge | derived from SL/ORD/INV/USE | derived (inherits upstream verdicts) | **Partial** |

**Headline:** the **read/billing/usage** half (ACCT, SL, USE, INV-\*, SUB) is mostly **Partial** —
the official API carries the core but drops a handful of scraped fields and reshapes others; **AUTH**
is the one clean upgrade. The **device-control + orders** half (ORD, DEV-\*) is **No** — the official
V2 API simply does not expose consumer WiFi management, per-client pause, live device telemetry, or
order/shipment data. **The feature DishNet most depends on (auto-block / pause on non-payment) cannot
be actuated through the official API.**

---

## ACCT — Account / contact sync → **Partial**
- **Current source:** `GET accounts/v3/accounts/contact` (`cron_session.php:437`, `cron.php:679/729`).
- **Output + purpose:** account identity + billing posture shown in the admin "session/account" tab; `nextDueDate` drives "bill ending soon" cues. → `dr_accounts.json[acc]`.
- **Fields used (`cron_session.php:450-466`):** `accountName`, `accountType`, `regionCode`, `isBusinessCustomer`, `isBillingSuspended`, `billingDayOfMonth`, `nextDueDate`, `previousDueDate`.
- **Official V2:** `GET /account` → `AccountResponseV2 { accountNumber, regionCode, accountName, activeSuspensions[] }`.
- **Transformation:** map `accountName`/`regionCode` directly; derive a suspension *boolean* from `activeSuspensions[]` non-empty.
- **Preserve exactly?** **Partial.** Carried: name, region, a suspension signal. **Missing API fields:** `isBusinessCustomer`, `billingDayOfMonth`, `nextDueDate`, `previousDueDate`, `accountType` — none are in `AccountResponseV2`. Billing dates would have to be re-derived from `GET /billing/invoices` (`dueDate`) + `/billing/balance`, which is **not** the same as the account's billing-day-of-month.
- **Recommended approach:** take identity + suspension from `/account`; **re-source due dates from `/billing/*`**; drop `billingDayOfMonth`/`isBusinessCustomer` from the migrated path unless `40`-Q confirms another field. Keep the display tolerant of a missing billing day.
- **Tests & regression risks:** no existing tests (`10`§A). Risk: the "bill ending soon" cue changes meaning if sourced from invoice due date vs account billing day. Add a fixture comparing both.

## SL — Service-line + router discovery → **Partial**
- **Current source:** `GET webagg/v2/accounts/service-lines` (`cron.php:230`, `cron_session.php:489`).
- **Output + purpose:** the backbone map SL↔kit↔router↔customer (`wifi_router_map.json`) + per-SL subscription/status cache (`sl_svc_cache.json`); feeds the WiFi tab, auto-block KIT→router resolution, lookups.
- **Fields used:** per SL `serviceLineNumber`, `nickname`, `productReferenceId`, `status`, `subscription.active/endDate/pendingActivation`, **`isPaused`, `isSuspended`, `isStandby`, `canPauseService`, `canResumeService`**, `lastConnected`, `lastDisconnected`, `serviceAddress`; per `userTerminals[]` `userTerminalId`, `kitSerialNumber`; per `routers[]` `routerId`, `hardwareVersion`, `isBypassed`, `directLinkToDish`, `lastConnected`.
- **Official V2:** `GET /service-lines` → `ServiceLineResponse { serviceLineNumber, addressReferenceId, nickname, productReferenceId, delayedProductId, optInProductId, startDate, endDate, publicIp, active, dataPoolId }`; `GET /user-terminals` → `UserTerminalResponseV2 { userTerminalId, kitSerialNumber, dishSerialNumber, serviceLineNumber, routers[] }`; `RouterResponseV2 { routerId, nickname, userTerminalId, configId }`; address via `GET /addresses/{addressReferenceId}`.
- **Transformation:** join `/service-lines` + `/user-terminals` on `serviceLineNumber` to rebuild the SL↔kit↔router map; resolve `addressReferenceId`→address text via `/addresses`.
- **Preserve exactly?** **Partial.** Carried: SL number, nickname, product id, `active`, `endDate`, kit/UT/router ids, address (reshaped). **Missing API fields:** **`isPaused`, `isSuspended`, `isStandby`, `canPauseService`, `canResumeService`** (no granular pause/standby in V2 — only `active`); **`lastConnected`/`lastDisconnected`** (no SL/router connectivity timestamps in V2); router **`hardwareVersion`** (deprecated→null in V2), **`isBypassed`, `directLinkToDish`** (absent). `pendingActivation` ≈ `delayedProductId` (approximate).
- **Recommended approach:** rebuild the map from `/service-lines`+`/user-terminals`; collapse the pause/standby flags to `active` + account `activeSuspensions[]` and **accept the loss of granularity**; keep `lastConnected` only if it can be re-sourced from the device channel (it cannot via V2 — see DEV-ST). Address: store `addressReferenceId` and resolve lazily.
- **Tests & regression risks:** HIGH. The WiFi tab and auto-block rely on this map; losing `canPauseService`/`isPaused` changes what the UI can assert about a line. Build a fixture that diffs the rebuilt map against a captured `wifi_router_map.json` for the 16 known SLs.

## USE — Usage / data telemetry → **Partial**
- **Current source:** `GET telemetryagg/v1/data-usage/account/{acc}/service-line/{sl}/annotated` (`cron.php:1484`); Mini variant on 404.
- **Output + purpose:** the customer-visible usage chart — per-cycle, per-day GB split **Local-Priority (blue)** vs **other (white)**, with allowance and totals. → `sl_usage.json`.
- **Fields used (`cron.php:1735-1811`):** `dataBuckets[].name` (classify priority vs other), `billingCyclesAnnotated[]` (`startDate`, `endDate`, `dailyData[][]`), `dataUsageSummaryLines[]` (`consumedAmountGB`, `usageLimitGB`, `summaryLineType` 4|6), `totalAmountGB`.
- **Official V2:** `POST /data-usage/query` → `ServiceLineDataUsageForBillingCycles { billingCycles[](DataUsageBillingCycleV2{ start/end, dailyDataUsage[]{ priorityGB, optInPriorityGB, standardGB, nonBillableGB }, overageLines[], dataPoolUsage[], totalPriorityGB, totalStandardGB, totalOptInPriorityGB, totalNonBillableGB }), servicePlan(DataServicePlan), lastUpdated }`.
- **Transformation:** map **priority→blue, standard→white**; per-day arrays map to `dailyDataUsage[]`; cycle totals map to `total*GB`. `optInPriorityGB` is a *new, richer* split the current UI collapses.
- **Preserve exactly?** **Partial** (leaning Yes on the numbers). Carried cleanly: per-cycle + per-day GB, priority vs standard, totals — arguably **cleaner** than the scraped `summaryLineType` arithmetic. **Missing / Unknown API fields:** the **allowance** (`usageLimitGB`) — V2 exposes `overageLines[]` + `servicePlan`, but whether `DataServicePlan` carries a plain monthly allowance GB is **not enumerated in the spec JSON** → `40`-Q-USE-1 (**Unknown**). **Freshness:** V2 `lastUpdated` is "typically yesterday"; the scraped `annotated` feed may be fresher → treat V2 usage as **day-lagged** ([D], `01`§D).
- **Recommended approach:** migrate the GB series to `/data-usage/query` (net simplification); **confirm the allowance source** before cutover (if `DataServicePlan` lacks it, derive allowance from `/products` by `productReferenceId`). Document the day-lag to operators.
- **Tests & regression risks:** MEDIUM. Numbers must tie out. Capture one account's `sl_usage.json` and assert the V2-derived series matches per-day within rounding; assert the priority/standard split is preserved.

## INV-L — Invoice list / balances → **Partial**
- **Current source:** `GET webagg/v1/public/billing/invoice-balances/{page}` (`cron.php:2122`). → `dr_invoices.json`.
- **Output + purpose:** per-account invoice list + outstanding balance; feeds the account tab, KIT registry, and the finance plugin's cost/margin view.
- **Fields used (`cron.php:2143-2162`):** `invoiceNumber`, `invoiceDate`, `invoiceTotalAmount`, `paymentAmount`, `paymentDueDate`, `paymentMethod`, `orderNumber`, `invoiceCurrencyCode`, `headerText`, `invoiceType`, `periodStartDate`, `periodEndDate`, `status`(int), `isCancelled`, `balance`, `depositAmount`.
- **Official V2:** `GET /billing/invoices` → `InvoiceSummaryResponse { invoiceId, description, currency, amount, dueAmount, invoiceDate, dueDate, status(PublicInvoiceStatus) }`; `GET /billing/balance` → per-currency `dueAmount`.
- **Transformation:** `invoiceNumber→invoiceId`, `invoiceTotalAmount→amount`, `balance→dueAmount`, `invoiceDate`/`dueDate` direct; **remap `status` int → `PublicInvoiceStatus` enum** (Unknown·Paid·Cancelled·Overdue·DueSoon·Outstanding·Migrated).
- **Preserve exactly?** **Partial.** Carried: id, amount, balance, dates, status (remapped), currency. **Missing API fields:** `paymentAmount`, `paymentMethod`, `depositAmount`, `orderNumber`, `headerText`, `invoiceType`, `periodStartDate/EndDate` — none in `InvoiceSummaryResponse` (period + order move to the *detail* endpoint; `paymentMethod`/`paymentAmount`/`depositAmount` have **no V2 equivalent**).
- **Note (financial caveat):** these invoices are **Starlink billing DishNet** (DishNet's *cost* of service), **not** DishNet's customer invoices. They must never be read as customer payment or recognized revenue. **uCRM/accounting stays authoritative** for customer billing; this feed is cost-side input to finance only (full treatment in `21`).
- **Recommended approach:** migrate id/amount/balance/dates/status to `/billing/*`; **do not fabricate** `paymentAmount`/`paymentMethod` — if finance needs them, keep them as a documented gap (`40`-G-INV). Preserve the int→enum status map in one place.
- **Tests & regression risks:** HIGH (feeds finance). Assert the migrated status map round-trips the historical int values; assert amounts/balances match a captured `dr_invoices.json`.

## INV-D — Invoice line detail → **Partial**
- **Current source:** `GET webagg/v1/public/invoice/{INV}` (`cron_invoice_details.php:280`). → `sl_invoice_lines.json` (**the file the finance plugin consumes**).
- **Output + purpose:** per-invoice line items for finance's cost/margin + reconciliation.
- **Fields used (`cron_invoice_details.php:387-447`):** invoice: `invoiceReferenceId`, `orderReferenceId`, `invoiceDate`, `paymentDueDate`, `isoCurrencyCode`, `invoiceTotalNaturalAmount`, `invoiceType`, `invoiceState`, `taxInclusive`, `regionTaxRate`, `isRefunded`, `isCancelled`; per line: `orderLineId`, `productId`, `description`, `quantity`, `price`, **`msrpPrice`, `adjustment`**, `lineTotal`, `taxAmount`, `invoiceLineType`, `periodStartDate/EndDate`.
- **Official V2:** `GET /billing/invoices/{invoiceId}` → `InvoiceDetailResponse { invoiceId, orderReferenceId, currency, dueAmount, paidAmount, invoiceDate, dueDate, invoiceLines[] }`; `InvoiceLineDetailResponse { productReferenceId, productDescription, serviceName, serviceLineNumbers[], quantity, unitPrice, taxAmount, subTotal, servicePeriodStartDate/EndDate }`.
- **Transformation:** `productId→productReferenceId`, `description→productDescription`, `price→unitPrice`, `lineTotal→subTotal`, `periodStart/End→servicePeriod*`, `taxAmount` direct.
- **Preserve exactly?** **Partial** — with a **gain** and some losses. **Gain:** V2 tags each line with **`serviceLineNumbers[]`** (+ `paidAmount` at invoice level) — materially better for reconciliation than the scraped shape. **Missing API fields:** **`msrpPrice`, `adjustment`** (no MSRP/adjustment in V2 — a real loss for margin-vs-list analysis), `orderLineId`, `invoiceLineType` (so the current "drop `invoiceLineType==2` tax-summary line" rule has no direct equivalent — V2 already models tax per line via `taxAmount`), `invoiceState`/`taxInclusive`/`regionTaxRate`/`isRefunded` (recompute tax posture from per-line `taxAmount`).
- **Note (financial caveat):** same as INV-L — cost-side (Starlink→DishNet). The **`sl_invoice_lines.json` file contract is a hard preservation boundary**: finance reads it. Any reshape must keep finance's expected keys or update finance in lockstep (`21`, `50`, `60`).
- **Recommended approach:** migrate to `/billing/invoices/{id}`; **use `serviceLineNumbers[]` to strengthen reconciliation**; treat `msrpPrice`/`adjustment` as a documented gap finance must tolerate (or source list price from `/products`). **Keep the output file schema stable** for finance.
- **Tests & regression risks:** HIGH (cross-plugin contract). Golden-file test: V2-derived `sl_invoice_lines.json` vs a captured one; assert finance still parses it; assert tax totals tie out without `invoiceLineType`.

## ORD — Orders / shipment tracking → **No**
- **Current source:** `GET webagg/v1/public/orders/customer-account` (`cron_orders.php:198`). → `dr_orders.json`.
- **Output + purpose:** order status + **shipment/fulfillment/tracking** (carrier, tracking number, delivered date) + line assets; feeds KIT registry provenance and the account tab; drives the adaptive orders cadence.
- **Fields used (`cron_orders.php:242-266`):** `orderNumber`, `invoiceNumber`, `orderDate`, `totalAmount`, `taxAmount`, `isoCurrencyCode`, `shippingAddress`, `isShipped`, `estimatedShipAfter/BeforeDate`, `fulfillmentShippedDate`, `orderStatus`, `orderType`, `invoicePaid`, `carrierId`, per-line `assetNumber`, `trackingNumber`, `deliveredDate`, `orderLineStatus`, `productType`.
- **Official V2:** **none.** V2 has no orders/fulfillment/shipment endpoint. The only order reference anywhere is `InvoiceDetailResponse.orderReferenceId` (a bare id, no status/tracking). (`/managed/customers` is deprecated and unrelated.)
- **Transformation:** n/a.
- **Preserve exactly?** **No.** No official source for order status, shipment, tracking, carrier, delivery, or `invoicePaid`.
- **Recommended approach:** **keep the orders subsystem as-is** (scraped) if order/shipment tracking must continue, **or** retire it if orders can be tracked in uCRM/manually. Do **not** claim the official API can replace it. Flag `40`-G-ORD as a hard capability gap.
- **Tests & regression risks:** n/a for migration (nothing migrates); regression risk is if someone *assumes* V2 covers orders and removes the scraper — KIT-registry provenance and the account tab would silently lose order data.

## SUB — Subscription / plan catalog → **Partial**
- **Current source:** `subscriptions/v1` (diagnostic-only, `public.php:1549`); subscription *liveness* from webagg `subscription.active/endDate/pendingActivation` (SL row).
- **Output + purpose:** whether a line's subscription is active/ending; plan id/name for display.
- **Fields used:** `subscription.active`, `endDate`, `pendingActivation`; `productReferenceId`/`productName`.
- **Official V2:** `ServiceLineResponse.active`/`endDate`/`productReferenceId` (per line) + `GET /products` → `SubscriptionProductResponse { productReferenceId, name, price, isoCurrencyCode, isSla, maxNumberOfUserTerminals, dataProducts }` (the catalog, with price).
- **Transformation:** liveness from `ServiceLineResponse.active`+`endDate`; resolve plan name/price by joining `productReferenceId`→`/products`. The diagnostic `subscriptions/v1` probes become **unnecessary**.
- **Preserve exactly?** **Partial** (effectively **Yes** for what's used). Carried: active/endDate/plan id/name, **plus** official plan **price** (new). **Missing:** `pendingActivation` as a distinct flag (≈ `delayedProductId`).
- **Recommended approach:** drop the diagnostic subscription probes; source plan catalog from `/products`. Low risk.
- **Tests & regression risks:** LOW. Assert each SL's active/plan matches the webagg-derived value for the known fleet.

## DEV-ST — Dish-online state / client counts → **No**
- **Current source:** gRPC-Web `POST SpaceX.API.Device.Device/Handle` method `get_status` (1004) (`cron.php:356`). Derives `dish_online_state` (online<4h / recent<12h / offline) and client counts (total/wifi/wired).
- **Official V2:** **none.** No device-telemetry *path* exists in V2 (the `DeviceTelemetry` *permission* exists, but no endpoint in this spec; obstruction/uptime/live status are not present). (`01`§D [✗].)
- **Preserve exactly?** **No.**
- **Recommended approach:** keep the gRPC status read if dish-online/client counts must persist; or source a coarse liveness elsewhere. Do not expect V2 to provide it.
- **Tests & regression risks:** n/a (no migration). Guard against anyone assuming `/service-lines` connectivity timestamps replace this — they don't exist either.

## DEV-WIFI — Read / write SSID & password → **No**
- **Current source:** gRPC `wifi_get_config` (3009) / `wifi_set_config` (3001) — token-gated change-password (`dr_wifi_change.php:2104`).
- **Official V2:** **none.** V2 Routers endpoints manage **router *configs*** (TLS, local-content, sandbox, default config, reboot) — **not** consumer WiFi SSID/password. No SSID/password field anywhere in the spec.
- **Preserve exactly?** **No.**
- **Recommended approach:** the WiFi change plane **must remain on the device channel** (gRPC) regardless of any billing-API migration. Treat as out of scope for the official API. `40`-G-1.
- **Tests & regression risks:** n/a. Critical: do not entangle this plane with the migration.

## DEV-PAUSE — Per-client pause / test-block / auto-block actuation → **No**
- **Current source:** gRPC device control + self-HTTP (`cron_auto_block.php`, `dr_wifi_change.php` pause/test_block). **Trigger = uCRM service status** (4 suspend→block, 1 active→unblock).
- **Official V2:** **none** for per-client pause / SSID-based suspension. V2 has UT/router **reboot** and `DELETE /service-lines/{sl}` (full deactivation) + read-only `activeSuspensions[]` — a sledgehammer, not the reversible per-client pause the plugin performs.
- **Preserve exactly?** **No.**
- **Recommended approach:** **auto-block stays exactly as-is.** Its *decision* already comes from uCRM (unaffected by any Starlink migration); its *actuation* has no official equivalent and must keep using the device channel. Do **not** substitute `DELETE /service-lines` for pause — it is not reversible-equivalent and would change customer-facing behaviour and billing.
- **Tests & regression risks:** HIGHEST operational risk if misunderstood. Explicitly document that the official API cannot perform suspend-on-non-payment.

## AUTH — Session machinery → **Yes** (replace; the one clean win)
- **Current:** pasted browser cookie → AES vault → `auth-rp/auth/user` + `session/refresh`/`token/refresh` + XSRF scrape + per-host throttle + dead-cookie reimport (`10`§C).
- **Official V2:** OIDC **client-credentials** — register an API client, mint a Bearer token from the well-known config, reuse until expiry ([D] `01`§A). Rate limit 250/min per account.
- **Preserve exactly?** **Yes** — and strictly simpler. All the cookie/XSRF/refresh/round-robin/throttle machinery is **replaced by a standard token mint**; no browser cookie, no manual reimport, no vault.
- **Recommended approach:** this is the keystone of any migration — do it **first**, behind a flag, for the read endpoints, before touching the device plane (which keeps its own auth). Store the client secret per the secret rules in `30`.
- **Tests & regression risks:** MEDIUM. New failure modes are token expiry / 401 / 403-`UserLacksRequiredPermission` / 422 envelope / 250-rpm throttle — all must be handled (`30`). Risk: the official account may **lack permissions** for some features (gated `FeatureAccess`) — verify before cutover.

## KIT-REG — KIT registry merge → **Partial** (inherits upstream)
- **Current:** `lib/KitRegistryWriter.php` merges finance `sl_kits` ← `dr_orders` ← `dr_invoices` ← live webagg SLs ← `sl_usage`, keyed by kit serial; "others must not write" contract.
- **Official V2:** the live-SL input maps to `/service-lines`+`/user-terminals` (SL↔kit); invoice/usage inputs map per INV-\*/USE; **the `dr_orders` input has no official source (ORD=No).**
- **Preserve exactly?** **Partial** — the registry can be rebuilt from V2 for the SL/kit/usage/invoice inputs, but **loses the orders-derived provenance** unless orders stay scraped.
- **Recommended approach:** migrate the SL/usage/invoice inputs; keep orders as the one scraped input (or accept reduced provenance). Preserve the output contract and atomic write.
- **Tests & regression risks:** HIGH (downstream contract to Hybrid/portal). Golden-file the registry for the known fleet before/after.

---

## Cross-cutting conclusions (carried to `40`/`50`/`60`)

1. **The official API is a read/billing/service API, not a control plane.** Everything DishNet *reads* (account, SL, usage, invoices, plans) is **Partial-or-better**; everything DishNet *does to a device* (WiFi, pause, block, reboot-for-suspend, live status) is **No**. A migration is therefore **partial by nature** — the device/auto-block plane cannot leave the scraped/gRPC channel.
2. **AUTH is the safe, high-value first step** and is independently valuable (kills the fragile cookie machinery) even if nothing else migrates.
3. **Three hard capability gaps** (`40`): **G-ORD** orders/shipment, **G-1** WiFi + per-client pause, **G-DEV** live device telemetry. None is a spec-reading error — they are confirmed absent (`01`§D).
4. **Field losses to decide on** before any cutover: `billingDayOfMonth`/due-date semantics (ACCT); `isPaused`/`canPause`/`lastConnected` (SL); usage **allowance** source (USE, Unknown); `paymentMethod`/`paymentAmount`/`depositAmount` (INV-L); `msrpPrice`/`adjustment` (INV-D).
5. **The `sl_invoice_lines.json` and `dr_kit_registry.json` file contracts are preservation boundaries** shared with the finance plugin — treat them as frozen interfaces during any migration (`21`, `60`).
6. **Financial caveat, reaffirmed:** the Starlink invoices are **DishNet's cost from Starlink**, never customer payment or recognized revenue; uCRM/accounting stays authoritative. The official API does not change this and must not be used to recompute historical financials.

*Assessment only. The finance-side mapping (`21`), the shared architecture (`30`), the consolidated
gaps/questions (`40`), the regression matrix (`50`), and the phased plan with rollback (`60`)
follow. No code, schema, flag, or deployment is touched.*
