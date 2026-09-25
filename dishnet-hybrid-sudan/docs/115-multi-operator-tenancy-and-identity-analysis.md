# 115 — Multi-Operator Domain-B Tenancy & Identity Analysis

**Status: ANALYSIS. No schema change, no migration, no code.** Requested
2026-09-23, interrupting the docs/114 D-AUTH build before a line of it was
written (D-AUTH-1…7 are **suspended**, docs/114 §M). The input was two rendered
pages of an Xceednet-style ISP portal branded DishNet — an ISP-level dashboard
and a Location-level dashboard. They are used for the **operating model only**:
no table, column, screen or workflow is copied from them, and the personal
contact details they carry are not reproduced anywhere in this repository.

Every statement about Domain B below is **measured** on the development
database (`dnb_sim`, migration level 25, 2026-09-23) or read from the
repository at `29d507d`. Estate counts are from the **synthetic** simulator
estate and are illustrations, never production facts.

> **The finding in one line.** `mt_customers` already *is* the Operator — the
> repository has called it "the Domain-B operator" since `docs/110`, nothing
> exists or is needed above it, and everything the target hierarchy places
> under an Operator already hangs off it. The safe change is **vocabulary at
> the API and UI**, not a new tenant layer. A table above `mt_customers`
> would re-key 18 tables, 18 policies, 32 functions and the session model to
> represent an entity the product does not have.

---

## A. Reference portal observations

What the two pages show, tier by tier. "Observed" means present in the pages;
"not observed" means the pages did not show it, which is not evidence of
absence.

| Tier | Navigation observed | What it implies |
|---|---|---|
| **ISP** (the platform, branded DishNet) | Admin → Users · Online payments *from Operators* / *from Locations* / *from Subscribers* · Billing profiles · Ticket templates · ISP packages · Import subscribers / packages · Settings. ISP navigation → Dashboard · **Operators** · **Operator packages** · **Locations** · Devices → NAS traffic · **Location packages** · Subscribers · Access request log · Reports · Tickets · Leads · Invoices (operator) · Payments (operator) · Zones · API docs | ISP staff operate **across** operators. The ISP sells *operator packages* to operators and invoices them. Money can enter at all three lower tiers |
| **Operator** | Listed and packaged at ISP level; a Location page links to "ISP Location" | A **commercial grouping of locations**. No operator-level user list was observed on these two pages |
| **Location** (brand = the location's own name) | Admin → **Users** (location users) · Subscriber alerts · Privacy & refund · Ticket templates · **NAS devices** · Additional fields · IP pools · Import location packages · Settings. Location navigation → Dashboard · **Subscribers** · Online subscribers · Access request log · Devices (NAS traffic, OLT/CPE) · Reports · Tickets · Leads · Package sales · Invoices (subscriber) · Payments (subscriber) · Inventory · Zones · Nodes · **Packages** · **Vouchers** · **Voucher batches** | The Location is the **operational unit**: it owns its users, devices, packages, vouchers, subscribers, sales and settings. Locations are found "by subdomain or NAS IP", so each carries its own portal identity |
| **Subscriber** | Lifecycle states (created → approved → package assigned / changed / renewed → FUP → topped up → disabled / enabled → expired → cancelled → terminated), expiry, advance renewal, fixed IP, balance due, invoices, payments | A **subscriber-account** model (PPPoE/HotSpot accounts with renewals), with vouchers alongside it |

Dashboard cards, for the same reading: ISP — *Operators/Locations*,
*Registrations*, *Subscribers*, *Devices of all locations* ("2 / 12 online
subscribers"), *Active tickets*, *Active leads*, *Billing* (operator invoices
and payments). Location — *Subscribers* (total / online / active / expired /
expiring), *Registrations*, *Data usage by day*, *Devices* (NAS, OLT/CPE,
ONU), *Top-5 data usage*, *Subscriber billing*, *Package sales*, *Total sale
per package*, *Max login requests*.

**Nine observations that matter for Domain B:**

1. **Four tiers**: ISP → Operator → Location → Subscriber.
2. **The Location, not the Operator, is the operational boundary** in the
   reference — it has its own users, devices, packages, vouchers and reports.
3. **The Operator is commercial**: operator packages, operator invoices,
   operator payments, a grouping of locations.
4. **Users exist at the ISP tier and at the Location tier.** An Operator-tier
   user list was not observed.
5. **Subscribers are accounts with a lifecycle.** DishNet's HotSpot product
   has **no** subscriber accounts by frozen decision (F11: *a voucher is a
   RADIUS user; a HotSpot user is not a DishNet account*; `docs/89`: no guest
   actor). This is a product-shape difference, not a gap to close.
6. **Devices are per location** and identified as NAS; the reference also
   inventories OLT/CPE/ONU — fibre equipment outside this product.
7. **An Access Request Log** exists at both ISP and Location tiers — an
   authentication-attempt log, which is what `docs/89` designed as the
   attempt store, plus RADIUS authentication events.
8. **Reports, tickets, leads, inventory, zones and nodes** are CRM and
   operations modules that Domain B does not hold and `docs/110` places
   outside it.
9. **Not taken from the reference:** its database, its subscriber model as a
   requirement, its billing tiers as a requirement, its UI. Taken: *ISP staff
   operate across operators; operators own locations; locations own devices,
   packages, vouchers and sessions.*

---

## B. Current DishNet Domain-B model — measured

The twelve questions, each answered by a measurement.

### B.1 What represents the commercial customer?

`mt_customers(id, ucrm_client_id integer NULLABLE UNIQUE, name, status,
created_at, radius_ref)`. The **commercial** relationship — the party DishNet
bills — is **external** (a uCRM/Splynx client) and appears in Domain B only as
the nullable link column, which `Projection.php` withholds from the customer
plane as *"internal billing linkage"*. `docs/110` already names the row
**"the Domain-B operator (the RLS authorization boundary)"** and the external
record "the uCRM commercial customer/service". **The commercial customer is not
a Domain-B entity; `mt_customers` is the tenant.**

### B.2 What represents the operator?

No table is named for it. The tenant `mt_customers` plays the role, and the
word appears exactly once in the schema: `mt_principals.kind CHECK (kind IN
('owner','operator'))` — a **person** kind, with no column comment and nothing
in code branching on it (C6/C16, open). Historically `docs/42` §4 carried a
**Reseller** role ("a tenant with voucher-selling rights"); `docs/81` §9
removed it as *"not a tenant… a future commercial / organizational model
requiring its own decision, with evidence"*. `docs/47` C10 (ARCH, open) asks
whether that Reseller is the same person as the Customer PWA user. **This
document is the evidence `docs/81` §9.1 asked for.**

### B.3 Can one customer have multiple operators?

Two readings, both measured:

| Reading | Measurement | Answer |
|---|---|---|
| operator = the tenant | `mt_customers` has **no** foreign key to anything (`FKs FROM mt_customers: NONE`); nothing sits above it | identity — one row is one operator |
| operator = `mt_principals.kind = 'operator'` | `mt_principals.customer_id NOT NULL`; `phone` UNIQUE globally (`mt_principals_phone_uq WHERE phone IS NOT NULL`); synthetic estate 1 principal per customer | one tenant → many people; **one phone → one tenant** (P-B closed, C10 open) |

### B.4 Can one operator have multiple locations?

`mt_sites.customer_id NOT NULL` → `mt_customers`; `mt_sites.service_id NOT
NULL` → `mt_services`; `mt_services.customer_id NOT NULL`. So **customer 1:N
services 1:N sites**. Synthetic estate: 2 / 1 / 2 sites across three
customers. **Yes.** (`mt_services.kind` permits exactly `mikrotik_hotspot`;
`mt_sites_id_customer_key UNIQUE (id, customer_id)` exists for W-2; the O-1
composite FK service↔site is still pending the census.)

### B.5 Can one operator have multiple routers?

`mt_devices.customer_id` **nullable** (`NULL` = unclaimed inventory),
`site_id` nullable, `mt_devices_site_customer_fkey (site_id, customer_id) →
mt_sites (id, customer_id)` plus `mt_devices_site_needs_customer` (W-2).
Synthetic estate: 2 / 1 / 1 devices assigned, **1 of 5 unclaimed**. **Yes** —
and unclaimed stock has no operator, which is equipment-first (`docs/101`).

### B.6 Can one router belong to multiple operators?

A single `customer_id` column; `serial`, `wg_pubkey` and `tunnel_ip` each
`UNIQUE`; the only way ownership changes is `mt_device_assign()` (audited,
re-stamps `claimed_at`). **Never simultaneously; sequentially only, with the
history in `mt_audit_log`.**

### B.7 Are vouchers scoped to customer or site?

`mt_vouchers.customer_id NOT NULL`; `site_id` **nullable** with `ON DELETE SET
NULL`; `mt_voucher_batches` the same shape. Decision 2b decided `site_id NOT
NULL` and it is **not migrated** (census-gated). Synthetic estate: 0 of 17
with a NULL site. **Customer-scoped by constraint; site-bound by decision,
not yet by constraint.** `code` is globally UNIQUE; `sold_by` → a principal
(`SET NULL`).

### B.8 Are plans operator-specific or global?

`mt_plans.customer_id NOT NULL` → **per operator**; the Admin projection also
carries an optional `site_id`. `mt_profiles` has **no `customer_id`** and no
RLS (`false/false`) — **global reference data**, the deduplicated rate
profiles. So: plans are the operator's retail policy (F9); profiles are
platform-wide.

### B.9 Can sessions be attributed to an operator?

`mt_sessions.customer_id NOT NULL`, set inside `mt_session_account()` from
`mt_hotspot_users.customer_id` by RADIUS username; `voucher_id` nullable;
`nas_identifier` present; `device_id` nullable and **never written**
(`docs/91`). **Operator: yes. Site: through the voucher. Router: no** —
unchanged and still honestly labelled "not attributable".

### B.10 Can audit identify both actor and scope?

`mt_audit_log(customer_id NULLABLE — NULL = system event, actor text NOT NULL,
actor_kind CHECK IN (principal, staff, system), …)`. Synthetic estate:
`principal` 6 rows (0 with NULL scope), `staff` 29 rows (5 with NULL scope —
device registration before any assignment). **Both dimensions are present.**
An operator's own staff are `principal`; DishNet staff are `staff`; the
platform is `system`. No new actor kind is needed for the four-plane model.

### B.11 Can the existing RLS support operator isolation?

21 `mt_*` tables; **19 with RLS, all 19 FORCED**; the two without are
`mt_profiles` (global) and `mt_migrations`. **18 isolation policies on 18
tables**, every one `customer_id = mt_current_customer()`, and
`mt_current_customer()` is `current_setting('app.customer_id', true)` — set by
`TenantContext` with `set_config(…, true)` (transaction-local) from the
**session's** customer, never from a request. Widening policies per definer
role: `dnb_def_admin` 10, `dnb_def_prov` 10, `dnb_def_auth` 8, `dnb_def_work`
8, `dnb_def_net` 6, `dnb_def_audit` 1. The suite proves the isolation is
substantive (84 assertions fail when it is widened). **Operator isolation is
customer isolation, and it already exists.**

### B.12 Do the Admin projections expose enough for a multi-operator estate?

Thirteen projections; **every estate projection carries `customer_id`**, and
`mt_admin_customers` (`id, name, ucrm_client_id, status, created_at`) *is* the
operator list. Missing for the target: an **operator-staff projection** (no
`mt_admin_principals`), **per-operator and per-location aggregates**, an
**operator detail** composed server-side (the panel composes it client-side
today), an **access-request log** (the attempt store is designed, not built),
and any **operator commercial record** (external by decision).

### B.13 The blast radius of a tenant-key change — measured

| What references the tenant key | Count |
|---|---|
| tables carrying `customer_id` | 18 (14 `NOT NULL`, 4 nullable) |
| foreign keys to `mt_customers` | 18 |
| RLS policies keyed on it | 18 |
| SQL functions whose body references it | 32 |
| migration files | 21 |
| PHP source files | 15 |
| test files | 19 |
| `mt_auth_sessions.customer_id` | `NOT NULL` — the session carries its tenant |
| frozen items touched | **F4** (authorization is server-derived; no endpoint accepts a customer id), **F5** (three experiences), `docs/81` §9 (one tenant hierarchy) |

---

## C. Gap analysis

| Reference concept | Domain B today | Class |
|---|---|---|
| ISP | DishNet — the platform; staff identity **designed** (`docs/114`, suspended) | identity, planned |
| ISP users | `mt_staff` — designed, not built | identity, planned |
| Operators | `mt_customers` — exists; `mt_customer_create()` has **no production caller** | **vocabulary** + missing caller |
| Operator packages / invoices / payments | none; external by `docs/110` | out of scope by decision (T-5) |
| Operator users | `mt_principals` — exists (phone OTP, PWA); `kind` stored, never branched; **no production writer** | missing writer + **capability model undefined** (C6/C16) |
| Locations | `mt_sites` via `mt_services` — exists; **no production writer**; O-1 first | missing writer (spine) |
| Location users | none — principals are operator-level | open decision (T-6), not a gap today |
| NAS devices | `mt_devices` — seven W-1 functions, no route bound | missing route binding |
| Location packages | `mt_plans` — per operator, optional site | exists |
| Subscribers | **none, by F11** — guests are vouchers and sessions | not a gap; product shape (T-3) |
| Online subscribers | `mt_sessions` — per operator, per site via voucher, **not per router** | exists, attribution limit recorded |
| Vouchers / batches | exist; operator-scoped, site-bound by decision | pending 2b migration |
| Access request log | attempt store designed (`docs/89`), not built; RADIUS *authentication* events not ingested (only accounting) | missing store + missing ingestion (T-11) |
| Reports | none; D-4 (telemetry exposure) open | missing projections (T-10) |
| Tickets · leads · inventory · zones · nodes | none | out of scope (CRM / Domain A) |
| Payments from subscribers / locations / operators | none; a voucher sale is the **operator's** revenue (`docs/110`) | out of scope by decision |

---

## D. Proposed Operator hierarchy

### D.1 The mapping, and the four conditions that make it safe

**`mt_customers` = Operator.** This is a **vocabulary decision**, not a schema
change, and it is safe if and only if the following hold. Each was measured.

| # | Condition | Evidence |
|---|---|---|
| 1 | **Nothing exists, or is required, above the tenant** | `mt_customers` references nothing (B.3). The reference's Operator → Locations maps to customer → sites (B.4). The only candidates for "above" are DishNet itself — not a tenant; its staff are global by capability (`docs/81` §8.3) — and C10, a *person* holding two operators, which is a login question (`docs/106` §P-B), not a hierarchy |
| 2 | **Nothing below the tenant needs its own isolation boundary today** | The reference's Location has users; DishNet's `mt_sites` has none, and Operator Staff *"belongs to exactly one Operator initially"* (this request). Location-scoped staff, if ever wanted, is a **scope on principals** inside the tenant — never a second tenant key |
| 3 | **The subscriber tier is not required** | F11 and `docs/89`: guests have no account and no actor kind. A subscriber-account product, if ever chosen, is a **child** of the operator (`customer_id`) — still below |
| 4 | **The commercial customer stays external** | `docs/110`: uCRM/Splynx is never a dependency; the link is optional and withheld from the operator plane. After this document, "customer" in Domain B's vocabulary should mean nothing but "operator" |

### D.2 The hierarchy, table by table

```
DISHNET                              (not a row; the platform)
  ├── DishNet Staff                  mt_staff            designed (docs/114), suspended
  │     Admin · NOC · Sales · Support
  └── Operators                      mt_customers        EXISTS — the RLS tenant
        ├── Operator Staff           mt_principals       EXISTS — customer_id NOT NULL, phone-OTP, PWA
        ├── Services                 mt_services         EXISTS — 1:N, kind = mikrotik_hotspot
        │     └── Locations / Sites  mt_sites            EXISTS — 1:N per service
        │           ├── Routers      mt_devices          EXISTS — NULL customer until assigned
        │           ├── Plans        mt_plans            EXISTS — per operator, optional site
        │           ├── Vouchers     mt_vouchers         EXISTS — operator-scoped, site-bound (2b)
        │           └── Sessions     mt_sessions         EXISTS — per operator; site via voucher
        ├── Guests                   (no row) — a voucher, then a session
        ├── Sales                    mt_voucher_batches + voucher price at issue
        └── Reports                  (none yet; projections to design)
```

### D.3 What was considered and rejected — with the reason

**`mt_operators` above `mt_customers`** (the sketch in the request). Rejected
on four measured grounds:

1. It would make `mt_customers` mean **subscriber accounts** — an entity the
   product does not have (F11) and has no writer, reader, or screen for.
2. It would re-key the tenant through **18 tables, 18 policies, 32 functions,
   21 migrations, 15 PHP files, 19 test files and `mt_auth_sessions`**
   (B.13) — the entire security model, rebuilt to add a level nothing
   references.
3. It contradicts **F4**, **F5** and `docs/81` §9 ("one tenant hierarchy"),
   so it needs a `docs/53` §5 amendment before a line could be written.
4. It buys nothing: every operator → locations → devices/plans/vouchers/sessions
   relationship in the target already exists under `mt_customers`.

**Renaming the table to `mt_operators`** — considered as T-1's second option.
Same blast radius as above for zero security gain; recorded, not recommended.

---

## E. Identity hierarchy

| Plane | Identity | Store | Exists? | Binds to |
|---|---|---|---|---|
| **1 DishNet Staff** | a person employed by DishNet | `mt_staff` (+ sessions) | designed, suspended | global capabilities by role; **may operate across operators** by naming the target |
| **2 Operator** | not an identity — the **boundary** | `mt_customers` | yes | RLS tenant; every operator-plane row carries its id |
| **3 Operator Staff** | a person of one operator | `mt_principals` | yes — phone OTP, PWA | exactly one operator (`customer_id NOT NULL`); one phone → one operator (P-B); disable, never reassign (P-C); `kind owner\|operator` stored, **not branched** (C6) |
| **4 Guest** | none | none — a voucher, then a session | yes | no portal access, no actor kind (`docs/89`) |

Three separations already measured, to keep:

- **A DishNet staff identity is never an operator identity.** Different tables,
  different providers, different credentials (a `dnb_staff_session` cookie vs
  the PWA's bearer token), different connection roles (`dnb_adminapi` /
  `dnb_adminwrite` vs `dnb_app`), and `test_admin_api` asserts the Admin
  surface cannot reach `mt_auth_*` or `TenantContext`.
- **Operator staff cannot reach the Admin API**, and DishNet staff do not
  impersonate operators: they name a **target operator** as a validated
  parameter (`docs/114` D-AUTH-3, reworded to *operator*).
- **A guest never becomes an account.** F11 stands.

---

## F. Authorization model

| Plane | Where authority comes from | Scope | State today |
|---|---|---|---|
| DishNet Staff | `StaffRole` → capabilities (`Capability`, 21 today + `staff.manage`) checked by `guard()` on every Admin route | all operators; a mutation names its target operator explicitly; `mt_current_customer()` is **never set** on the Admin plane | capability model built; provider suspended |
| Operator Staff | the OTP session's `customer_id` → `app.customer_id` → RLS; **plus** an intra-operator capability — **undefined today** | its own operator only | every principal can do everything the operator plane allows (plans, vouchers, revoke, disconnect). `kind` is a stored label |
| Guest | none | a voucher at its site's authorized NAS set (2a/2b) | redemption path unbuilt |

**The gap is the second row.** `docs/47` C6 offered *A* two roles (owner /
operator) · *B* single login · *C* configurable per customer; `docs/105`
retired P-A into C6/C16 and ruled that a writer may **store** `kind` but not
**branch** on it until C6 closes. The reference's Location users and the
request's "permissions/capabilities within that Operator" both point at
answer **A** with a capability list — the Admin plane's shape, one level down.
It stays **open** here (T-2), because it is a product decision.

---

## G. RLS implications

Under D.1: **none.** The 18 isolation policies stay as they are; the operator
boundary is the customer boundary; `TenantContext` is unchanged; the
84-assertion isolation proof still stands.

Two additions the target eventually needs, both **below** or **beside** the
tenant, never above it:

1. `mt_staff*` — **non-tenant** tables with no RLS and function-only access
   (`docs/114` §D), owned by a definer role, created under `SET LOCAL ROLE` so
   migration 015's default privileges cannot reach them.
2. If location-scoped operator staff are ever chosen (T-6): a **second
   predicate inside the tenant** (`site_id ∈ the principal's allowed set`) on
   the tables that carry `site_id` — an extension of the existing policies,
   designed then, with the same evidence discipline.

Under the rejected alternative: every policy re-keyed, every definer role's
widening policies re-examined, `mt_auth_sessions` re-shaped — B.13.

---

## H. Voucher / site / router ownership implications

| Object | Owner | Binding | Change implied by D |
|---|---|---|---|
| Voucher | the operator (`customer_id NOT NULL`) | its issuing location (2b; `site_id NOT NULL` pending the census) | none |
| Batch | the operator | optional location | none |
| Router | **DishNet inventory** while `customer_id IS NULL`; then one operator; optionally one of that operator's locations (W-2) | reassignment is an audited administrative act; never two operators | none |
| Location | one operator, through one service | O-1 composite FK pending | none |
| Plan | one operator | optional location | none |
| Session | one operator, via the HotSpot user | location via voucher; **router: not attributable** | none |
| Guest | nobody | one voucher | none |

Admin-plane writes on any of these name the **target operator** explicitly
and derive everything else (`mt_site_create` derives the operator from the
service; `mt_device_assign` takes operator and site and the composite FK
refuses a mismatch).

---

## I. Reporting implications

What the reference reports per location — subscribers online/active/expired,
data usage per day, package sales per period, sales per package, devices
online — maps to Domain B as follows:

| Report | Source in Domain B | Available? |
|---|---|---|
| vouchers by state per operator / location | `mt_vouchers` | yes, via projection |
| sales per plan per period | voucher rows carry `price_minor`, `currency`, `created_at`, `plan_id`, `site_id` — issue **is** the sale event | computable; no projection yet |
| sessions and usage per location per day | `mt_sessions` (`bytes_in/out`, Gigawords honoured) joined through the voucher | computable; not per router |
| online guests per operator | `mt_sessions.state` | yes, estate-wide today |
| devices online | **no source** — `last_seen_at` is never written (`docs/90`) | not until hardware contact exists |
| access request log | attempt store (designed) + RADIUS authentication events (not ingested; only accounting reaches Domain B) | not built (T-11) |
| uplink | `mt_uplink_samples` — D-4 open | not Admin-readable by decision |
| invoices, payments, tickets, leads | external | never in Domain B |

Reports are **read-only projections**: Admin ones cross-operator through
`dnb_def_admin`, operator-plane ones RLS-bound through `/me/*` (`/me/usage`
already exists). Nothing here needs a schema change; it needs definitions
(T-10) and, for anything per router, hardware.

---

## J. Migration impact

| | Option 1 — `mt_customers` = Operator (vocabulary) | Option 2 — `mt_operators` above |
|---|---|---|
| tenancy migrations | **zero** | re-key 18 tables + policies + 32 functions + sessions |
| pending, unrelated | O-1 composite FK · 2b `site_id NOT NULL` · migration 026 staff identity · principal writer · C6 | the same, plus all of the above |
| freeze | untouched | F4, F5, `docs/81` §9 — amendment required |
| API | JSON keys `customer` → `operator`, paths `/customers` → `/operators`; the panel is the **only consumer** and RC1 is installed nowhere, so a straight rename with a manifest update is safe | every route |
| PWA | untouched | every `/me` route |

---

## K. Compatibility impact on the current PWA

**None under Option 1.** `/me/*` keeps its shape; the bearer token still
resolves to `customer_id`; `TenantContext` still sets `app.customer_id`; F4
stands. The Customer PWA simply *is* the **Operator portal** — which is what
`docs/46` §6 already describes ("WHO: Riverside Hotel — *how is my service
doing?*"). Under Option 2, every `/me` route and `mt_auth_sessions` would need
an operator context: breaking.

---

## L. Compatibility impact on the current Admin UI

Option 1 changes **labels and adds screens**, nothing structural:

- *Customers & sites* → **Operators & locations**; the list is
  `mt_admin_customers` renamed in the API.
- **Operator detail** — a server-side composition of projections that the
  panel today assembles client-side (sites, routers, plans, vouchers,
  sessions, audit for one operator).
- **Operator staff** — a new projection `mt_admin_principals` (id, operator,
  display_name, kind, status, created_at, last_login_at); **`phone` is the
  authentication key** (`docs/100`) and is withheld or masked exactly as
  `code` is withheld from vouchers.
- Counts per operator on the dashboard.
- One panel file references `customer_id`; the bundle guards and the manifest
  equality test are updated in the same commit as the rename.

---

## M. What remains unchanged

RLS model and its 18 policies · F1–F13 · Decisions 1, 2a, 2b, 3, 7 · the guest
model (no accounts, no actor kind, the code never enters the AAA path) · the
audit model (actor + scope; NULL scope for system events; no login role can
write it) · A-1 / B-2 closures · W-1 / W-2 / W-3 · the standalone boundary —
**no Splynx, uCRM or UISP dependency, ever, for login or operation** · the
plugin boundary · the `/me` API · `docs/114`'s architecture in full, with one
word changed: D-AUTH-3's *target customer* becomes *target operator*.

---

## N. Open decisions

| # | Decision | Recommendation | Who |
|---|---|---|---|
| **T-1** | vocabulary scope — API/UI rename only, or physical table rename | **API/UI only.** Same meaning, none of the blast radius | operator |
| **T-2** = C6/C16 | operator-staff capability model — what `owner` and `operator` may do | answer C6 = **A** with a capability list, before the principal writer exists | operator (product) |
| **T-3** | subscriber accounts — never, or a later product | **never for HotSpot** (F11); if a PPPoE-style product is ever added, it is a child of the operator and its own decision | operator (product) |
| **T-4** = C10 | one person for several operators | with P-B closed the answer today is **no** — one number, one operator; a principal-selection step would be a new design | operator |
| **T-5** | operator commercial records (operator packages, invoices, payments) in Domain B | **external**, per `docs/110`; revisit only if DishNet bills operators from Domain B | operator (commercial) |
| **T-6** | location-scoped operator staff | not now; representable later as a scope on principals (G.2) | operator (product) |
| **T-7** | zones / grouping of locations | display grouping if ever; never an isolation boundary | later |
| **T-8** | resume D-AUTH-1…7 with D-AUTH-3 reworded to *target operator* | **yes**, after T-1 is accepted | operator |
| **T-9** | DishNet operating its own locations | an ordinary `mt_customers` row named DishNet; **no special case** | recommended |
| **T-10** | report definitions and the first aggregate projections | after T-1; per-router reports wait for hardware | operator |
| **T-11** | access-request log — attempt store + RADIUS authentication ingestion | with the portal work, gated on Decision 5 / `dnb_portal` | gated |
| **T-12** | keep `mt_services` between operator and location | **keep** — it is where U-5 will live if ever needed; the reference has no equivalent and needs none | recommended |

---

## O. Recommended implementation sequence

```
0  accept D.1 (mt_customers = Operator) and T-1 (vocabulary at API/UI)        ← by instruction
1  vocabulary pass: Admin API, projections, panel, docs say Operator          no schema change
2  resume docs/114: D-AUTH-1…7 with "target operator" → G-B                   migration 026
3  decide T-2 (C6/C16) → principal writer (spine step 1)                      operator staff
4  O-1 census → migration → site writer → operator-create caller → 2b         locations
5  projections: operator staff, operator detail, first aggregates (T-10)       reports v1
6  G-C routers → G-C2 operator-plane commercial writes by staff               real operation
7  attempt store + access-request log, with the portal work                    gated
8  own decisions, later, if ever: T-3 subscriber accounts · T-6 location staff
   · T-5 operator ledger
```

*Nothing above is implemented. Migrations end at 025, `DenyAllIdentity`
remains the production binding, the production census remains the handoff
for O-1, and D-AUTH-1…7 stay suspended until T-1 and T-8 are answered.*
