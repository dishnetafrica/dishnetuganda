# 01 — Verified catalogue: Starlink **official** Public API V2

**Deliverable #4** of the read-only integration assessment (operator request, 2026-10-03).
**Source of truth:** the OpenAPI **3.0.4** document the operator supplied verbatim, which is
`https://starlink.com/api/public/swagger/v2/swagger.json` (`info.title` "Starlink Public
API", `info.version` "2"). `starlink.com` is blocked by this session's egress proxy, so
nothing here was fetched live — it is read **from the spec JSON**.

**Evidence labels used throughout:**
- **[V]** VERIFIED — present in the spec JSON (endpoint, field, type, enum, required flag).
- **[D]** DOCUMENTED — stated in Starlink's published docs / the spec's `info.description`
  but **not** machine-checkable in the JSON (e.g. auth flow detail, rate limits).
- **[✗]** NOT IN SPEC — looked for, absent. An explicit "we checked and it is not here."

> This catalogue is the **official** API only. What the two installed plugins actually use
> today is a **different, unofficial system** (scraped web/app endpoints + a session cookie);
> that is inventoried separately (docs `10`/`20`). Do not conflate the two.

---

## A. Mechanics (how you call it)

| Property | Value | Ev |
|---|---|---|
| Spec format | OpenAPI **3.0.4** | [V] |
| Server (spec `servers[0].url`) | `/api` | [V] |
| Effective base URL | `https://starlink.com/api/public/v2` (server `/api` + path prefix `/public/v2`) | [V] |
| Auth scheme | **OIDC client-credentials** (machine-to-machine). Discovery: `/api/auth/.well-known/openid-configuration` (named in `info.description`). Register an API client in the Starlink account → client id/secret → mint a Bearer token → `Authorization: Bearer <token>`. | [D] (description) / [✗] no `securitySchemes` block is present in the supplied JSON `components` |
| Token lifecycle | obtained from the OIDC token endpoint in the well-known config; short-lived; **cache and reuse** (see rate limits) | [D] |
| Rate limits | **250 requests/min per Starlink account**; **1,000 token requests / 15 min per client IP** | [D] (Starlink docs; not in JSON) |
| Webhooks / push | **none** — the spec defines no callbacks/webhooks. Integration is **poll-only**. | [✗] |
| Scale (per spec) | **~49 paths across 12 tags** | [V] |

### A.1 Response envelope — *every* non-trivial response is wrapped
Almost all responses are a `…ServiceResponse` object: **[V]**
```
{ "isValid": bool, "errors": [ValidationResult], "warnings": [ValidationResult],
  "information": [string], "content": <the actual payload> }
ValidationResult = { "memberNames": [string], "errorMessage": string }
```
**Integration rule:** a 2xx is **not** sufficient — you must check `isValid`/`errors`.
Business failures are returned as **HTTP 422** carrying this same envelope (`isValid:false`,
`errors[]`), *not* a bare 4xx. Transport/authorization failures are 400/401/403. **[V]**

### A.2 Pagination — two distinct schemes **[V]**
- **Page-index** (most list endpoints): query `page` (+ sometimes `limit`); response
  `…Paginated { pageIndex, limit, isLastPage, totalCount, results[] }`. Page size is
  typically **100** (data-usage/data-pool-usage default **50**, max **250**/**100**).
- **Cursor** (Managed-Accounts lists + service-line roam-restrictions): query `cursor`;
  response `…KeyPaginatedString { nextKey, results[] }` — pass `nextKey` back as `cursor`;
  `null` nextKey = last page.

### A.3 Permission model **[V]**
Every operation's `description` declares `Required permission: <Feature>, <View|Edit>`. The
API client's granted roles must include it; otherwise **403** returns
`UserLacksRequiredPermission { accountId, requiredPermission{featureAccess,permission},
featureAccessString, permissionString }`.
- `Permission` enum: `View(0) · Edit(1) · Create(2) · Delete(3)`.
- `FeatureAccess` enum (25 + 6 gated): DeviceTelemetry, DeviceCommand, **Financial**,
  UserManagement, **AccountInformation**, OutageHistory, SupportRequest, **DeviceManagement**,
  **ServicePlan**, UserViewership, ServiceAccountManagement, DataSharingPreferenceManagement,
  **DeviceConfigurationAssignment**, AviationFlightStatusManagement, **AdminOnly**,
  ManagedAccountCreation, ManagedAccountsInventoryManagement, ManagedAccountInformation,
  ManagedAccountStructureManagement, ManagedAccountDeletion, ManagedAccountLocationSettings,
  DataUsageNotifications, ManagedAccountBillingInformation, DocumentShare, CustomFields, and
  gated: Installer, LawEnforcementAgency, **StarlinkMobileData**, **ServiceAvailabilityChecker**,
  LIIndependent, MobileLIIndependent.
  > Note: `ServiceAvailabilityChecker` exists as a **gated feature flag** in the enum, but
  > **no availability/serviceability endpoint appears in the V2 paths** — see §D.

### A.4 Identifiers **[V]**
- Account number — `ACC-511274-31364-54`
- Service line number — `SL-DF-511274-31364-54`
- User-terminal id — `00020900-002220cc-225b9199` (**not printed on hardware**)
- Kit serial — `KIT00142069` (on the box) · Dish serial — `2DHT00542069` (on the dish)
- `deviceId` path params accept **UT id, kit serial, OR dish serial** interchangeably
- Address reference — a **UUID**
- Invoice id — `INV-DF-US-1234ABCD` · Product id — e.g. `business-subscription-100`

---

## B. Endpoint catalogue — by tag (all [V])

Permission shown as `Feature/View|Edit`. "Env" = page-paginated (P) / cursor (C) / single.

### Account
| Method · Path | Perm | Purpose | Response content |
|---|---|---|---|
| GET `/account` | AccountInformation/View | account number, region, name, `activeSuspensions[]` | `AccountResponseV2` |
| POST `/data-usage/query` (`page`,`limit≤250`) | ServicePlan/View | per-service-line data usage across billing cycles | `ServiceLineDataUsageForBillingCycles` (P) |
| GET `/products` (`page`, size 100) | ServicePlan/View | subscription products assignable to service lines | `SubscriptionProductResponse` (P) |

### Service Lines (core)
| Method · Path | Perm | Purpose |
|---|---|---|
| GET `/service-lines` (`addressReferenceId`,`searchString`,`dataPoolId`,`page`,`orderByCreatedDateDescending`) | ServicePlan/View | list (P) |
| POST `/service-lines` | ServicePlan/Edit | **create** (needs address + product, optional data blocks) |
| GET `/service-lines/{slNo}` | ServicePlan/View | one |
| DELETE `/service-lines/{slNo}` (`reasonForCancellation`,`endNow`) | ServicePlan/Edit | **deactivate** (now or next bill day) |
| PUT `/service-lines/{slNo}/nickname` | ServicePlan/Edit | set nickname |
| PUT `/service-lines/{slNo}/product` | ServicePlan/Edit | **change plan** (immediate/delayed; optional recurring blocks/pool) |
| PUT `/service-lines/{slNo}/public-ip` | DeviceConfigurationAssignment/Edit | enable/disable **public IP** |
| POST `/service-lines/{slNo}/data/opt-in` · `/opt-out` | ServicePlan/Edit | priority-data overage opt in/out |
| POST `/service-lines/{slNo}/user-terminals` | ServicePlan/Edit | attach a UT (must be on account) |
| DELETE `/service-lines/{slNo}/user-terminals/{deviceId}` | ServicePlan/Edit | detach a UT (clears its L2VPN) |
| PUT `/service-lines/{slNo}/data/recurring` | ServicePlan/Edit | set recurring data blocks / onboard to a pool |
| POST `/service-lines/{slNo}/data/top-up` | ServicePlan/Edit | one-time data block |
| GET `/service-lines/{slNo}/billing-cycles/partial-periods` | ServicePlan/View | proration periods |
| PATCH `/service-lines/{slNo}/consume-from-pool` (`consumeFromPool`) | ServicePlan/Edit | pool consumption toggle (gated) |
| GET `/service-lines/roam-restrictions` (`cursor`) | ServicePlan/View | roam-restricted + pending (C) |

### User Terminals
| Method · Path | Perm | Purpose |
|---|---|---|
| GET `/user-terminals` (`serviceLineNumbers`,`userTerminalIds`,`hasServiceLine`,`searchString`,`page`) | DeviceManagement/View | list (P) |
| POST `/user-terminals` (`DeviceIdRequest`) | DeviceManagement/Edit | add UT to account (no service) |
| DELETE `/user-terminals/{deviceId}` | DeviceManagement/Edit | remove from account (must be off all SLs) |
| POST `/user-terminals/{deviceId}/reboot` | DeviceConfigurationAssignment/Edit | reboot dish |
| PUT `/user-terminals/configs/assign` | DeviceConfigurationAssignment/Edit | assign UT config |
| GET `/user-terminals/l2vpn` · PUT `/user-terminals/{deviceId}/l2vpn` | DeviceConfig…/View·Edit | L2VPN circuits |

### Routers
GET `/routers/{id}`; GET/POST `/routers/configs`; GET/PUT `/routers/configs/{configId}`;
PUT `/routers/configs/assign`; GET/PUT `/routers/configs/default`; GET/POST/DELETE
`/routers/configs/tls`; GET/POST `/routers/local-content`; GET/POST `/routers/sandbox/clients`;
PUT `/routers/sandbox/heartbeat`; POST `/routers/{id}/reboot`. Perms: DeviceManagement/View
(get) and DeviceConfigurationAssignment or DeviceCommand/… for config/assign/reboot. **[V]**

### Addresses
GET `/addresses` (`addressIds`,`metadata`,`page`); POST `/addresses` (create, AccountInformation/Edit);
GET/PUT `/addresses/{addressReferenceId}`. Address = lat/long + lines + free-text `metadata`. **[V]**

### Billing
| Method · Path | Perm | Purpose | Content |
|---|---|---|---|
| GET `/billing/invoices` (`page` 100) | Financial/View | invoice summaries | `InvoiceSummaryResponse` (P) |
| GET `/billing/invoices/{invoiceId}` | Financial/View | invoice **line items** | `InvoiceDetailResponse` |
| GET `/billing/balance` | Financial/View | amount due **per currency** | `AccountBalanceResponse` |

### Data Pools *(gated — "available for select audiences only")*
GET `/data-pools`; GET `/data-pools/usage`; POST `/data-pools/{id}/set-automatic-top-up`. ServicePlan/View·Edit. **[V]**

### Contacts
GET `/contacts` (P); POST `/contacts`; PUT/DELETE `/contacts/{subjectId}`. UserManagement / "Admin Only – API User Management". **[V]**

### Managed Accounts *(reseller/provider hierarchy)*
GET `/managed/accounts/tree`; GET `/managed/accounts` (C); POST `/managed/accounts` (create child);
GET `/managed/accounts/service-lines` (C); GET `/managed/accounts/user-terminals` (C).
Perms: ManagedAccountInformation/ManagedAccountsInventoryManagement/ManagedAccountCreation. **[V]**
(`Managed` tag: POST `/managed/customers` — **deprecated**, use `/managed/accounts`.)

### Mobile *(gated "Starlink Mobile Data")*, Flights *(aviation)*
`/mobile/{radio-access-network|timeseries|map}/{timestamp}` (hourly stats); POST `/flights/status`.
**Not relevant to DishNet.** **[V]**

---

## C. Key response schemas (the fields that matter for mapping) [V]

**AccountResponseV2:** `accountNumber, regionCode, accountName, activeSuspensions[]`.
`activeSuspensions` is a **read-only list of strings** — the only "suspension" signal in V2.

**ServiceLineResponse:** `serviceLineNumber, addressReferenceId(uuid), nickname,
productReferenceId, delayedProductId, optInProductId, startDate, endDate, publicIp(bool),
active(bool), dataPoolId, aviationMetadata, dataBlocks(ServiceLineDataBlocksSummaryResponse)`.
→ **`active` + `endDate` are the lifecycle signals** (there is no "paused" state; a
deactivation sets `endDate`/`active=false`).

**SubscriptionProductResponse** (`/products`): `productReferenceId, name, price, isoCurrencyCode,
isSla(bool), maxNumberOfUserTerminals, dataProducts(DataProductsResponse{ topUpProduct,
dataBlockProducts[] })`. → **plan name + monthly price + currency per product.**

**UserTerminalResponseV2:** `userTerminalId, nickname, kitSerialNumber, dishSerialNumber,
serviceLineNumber, l2VpnCircuits[], routers[](RouterResponseV2)`. **RouterResponseV2:**
`routerId, nickname, userTerminalId, configId` (hardwareVersion/lastBonded **deprecated → null**).

**InvoiceSummaryResponse:** `invoiceId, description, currency, amount, dueAmount, invoiceDate,
dueDate, status(PublicInvoiceStatus)`.
**InvoiceDetailResponse:** `invoiceId, orderReferenceId, currency, dueAmount, paidAmount,
invoiceDate, dueDate, invoiceLines[]`. **InvoiceLineDetailResponse:** `productReferenceId,
productDescription, serviceName, serviceLineNumbers[], quantity, unitPrice, taxAmount,
subTotal, servicePeriodStartDate, servicePeriodEndDate`. → **each invoice line is tagged
with the service-line numbers + service period + tax** (the key to automated reconciliation).
`PublicInvoiceStatus`: Unknown·Paid·Cancelled·Overdue·DueSoon·Outstanding·Migrated.

**AccountBalanceResponse:** `balances[]` of `CurrencyBalanceResponse{ currency(ISO4217),
dueAmount }`. → amount **owed to Starlink**, per currency. (This is *Starlink billing you*,
not *your customer paying you* — see the finance caveat in docs `21`.)

**ServiceLineDataUsageForBillingCycles** (`/data-usage/query`): `accountNumber,
serviceLineNumber, startDate, endDate, billingCycles[](DataUsageBillingCycleV2), servicePlan
(DataServicePlan), lastUpdated`. `DataUsageBillingCycleV2`: start/end, `dailyDataUsage[]`
(`priorityGB, optInPriorityGB, standardGB, nonBillableGB` per day), `overageLines[]`,
`dataPoolUsage[]`, plus cycle totals `totalPriorityGB/totalStandardGB/totalOptInPriorityGB/
totalNonBillableGB`. → **per-service-line, per-cycle, per-day usage in GB**, with priority vs
standard vs overage. `lastUpdated` present only for cached data (freshness marker).

**Addresses / Contacts / Managed / roam / enums:** AddressResponse (lat/long, lines,
regionCode, metadata); UserResponse (`subjectId, email, roles[]`); ManagedAccount* (account
tree + cross-account SL/UT with the same SL/UT field shapes); roam restriction
(`status: Restricted|Pending`, days used/remaining). Enums: DataBlockType, DataBucketType,
DataOverageType, DataProductType(Ocean/Land/Aviation), PublicInvoiceStatus. **[V]**

---

## D. Verified capabilities vs. gaps (what to check before assuming parity)

**Present & actionable [V]:** list/create/deactivate service lines; change plan; attach/detach
UTs; add/remove UTs on account; reboot UT & router; public-IP toggle; data top-up / recurring
blocks / opt-in-out; router configs & sandbox; addresses; contacts; invoices (summary+detail)
& balance; per-SL data usage; managed-account hierarchy.

**Gated — may not be enabled for DishNet's account [V]:** Data Pools and
`consume-from-pool` ("select audiences only"); Mobile data; aviation Flights. Must confirm the
account's granted `FeatureAccess` before relying on these.

**NOT present in V2 — gaps vs. the existing scraped feed [✗]:**
- **No consumer-style pause / unpause.** V2 offers only `DELETE` (deactivate) + the read-only
  `activeSuspensions[]`. The installed system's "pause overlay" / auto-block is **not** an
  official-API capability.
- **No serviceability / availability check endpoint** (despite the `ServiceAvailabilityChecker`
  gated-feature enum existing).
- **No device-level live telemetry / obstruction / uptime** (the scraped
  `SpaceX.API.Device.Device/Handle` path has no V2 equivalent; `DeviceTelemetry` exists as a
  permission but there is no telemetry **path** in this spec — likely a separate API).
- **No "annotated" usage series** equivalent (the scraped `telemetryagg/.../annotated` and
  `usage/.../annotated` return a richer/real-time series; V2 gives billing-cycle/day
  aggregates via `/data-usage/query`).
- **No raw invoice-balance paging** like `webagg/v1/public/billing/invoice-balances`; V2
  billing is the structured `/billing/*` set (which is *better*, but shaped differently).
- **No webhooks** — polling only.

**Freshness [V/D]:** `/data-usage` carries `lastUpdated` for cached rows ("typically yesterday
and later"); treat usage as **not** real-time. Service-line/terminal/billing reads are live.

**Reseller shape [V]:** operate on the single authenticated account via the direct endpoints,
**or** across a child hierarchy via `/managed/accounts/*`. Which one DishNet needs depends on
whether DishNet holds one account with many service lines, or a provider account over many
child accounts — **an open question for the operator** (docs `40`).

---

*Produced read-only from the supplied V2 OpenAPI JSON. No live Starlink call was made; no
secret or customer identifier appears here. Companion docs: `10`/`20` (data-report, from real
source), `11`/`21` (finance, pending its source), `30` architecture, `40` gaps/questions,
`50` regression matrix, `60` migration plan.*
