# 44 — Prototype V1 UI/UX Audit and V2 Change List

**Subject:** `prototype/dishnet-mikrotik-management-prototype.html` (commit `8f9e04a`)
**Audited against:** the six MikroTicket App Store screenshots, the existing DishNet PWA,
and docs/41 / docs/42 / docs/43.
**Status:** Review deliverable. **No code changed.** This is the change list for V2.
**Date:** 2026-09-19

---

## 0. Method, and what evidence actually exists

Six MikroTicket screens were obtained: **New plan**, **Tickets list**, **Create tickets**,
**Printers**, **Routers** (with its Local/Remote bottom sheet), and **Report**. That is the
whole evidence base. MikroTicket's dashboard, login, router detail, sessions and portal
editor **were never seen** — the listing mentions some of them, but a feature bullet is not
a screen.

So the matrix below compares like with like only where a screenshot exists. Everywhere else
it says so.

### Three findings from re-reading my own code

Before critiquing anything, I checked the prototype's actual data structures rather than
trusting my memory of writing it:

1. **`TENANTS` is decorative.** It is referenced in exactly three places: a `tenantName()`
   lookup, one filter dropdown, one registration dropdown. There is no Tenant screen, no
   tenant scoping, no tenant totals. **The reviewer's concern is correct and understated.**
2. **`site` is a string**, not an entity — `'Riverside Hotel — Lobby'`. Sites cannot be
   listed, filtered as a group, or given their own totals.
3. **Bulk operations do not exist.** docs/42 §6.1 listed "Bulk select → queue action" as a
   secondary action. It was never built, and it is not even present as dead UI text. Worth
   stating so it is not assumed present.

---

## 1. Screen-by-screen matrix

### 1.1 MikroTicket screens with direct evidence

| # | MikroTicket screen | What it does | DishNet V1 equivalent | Decision |
|---|---|---|---|---|
| 1 | **New plan** — Simple/Advanced tabs; name, price, **users per ticket**, up/down speed with unit dropdowns, **elapsed vs paused time**, duration as Days/Hours/Minutes | Defines the sellable product | **Plans** + plan modal | **IMPROVE** — see §1.3 |
| 2 | **Tickets list** — cards, dual chips (`Activated`+`Completed`), item count, **explicit date range in header**, filter button, per-row overflow menu | Find and inspect a voucher | **MT Vouchers** table | **KEEP**, add date range |
| 3 | **Create tickets** — server → plan → **output** → **printer** → quantity, one form | Generate *and* print in one action | **Generate modal** | **KEEP** — already copied faithfully |
| 4 | **Printers** — cards with paper width (58/80 mm) and transport (Bluetooth / LAN + address), FAB to add | Manage physical output devices | **Nothing** | **ADD** (small) — §1.4 |
| 5 | **Routers** — green dot, site chip, model, board model, serial, RouterOS version, IP; **Local vs Remote connection** bottom sheet; promo upsell card | Pick a router, then pick how to reach it | **MT Routers** table + detail | **IMPROVE** — §2 |
| 6 | **Report** — start/end date + operator filters; **funnel**: created → activated → completed → deleted; money total | Answer "how much did we sell" | **Reports** funnel | **KEEP** |

### 1.2 DishNet screens with no MikroTicket counterpart

These exist because the architecture requires them, not because a competitor has them.

| Screen | Justification | Decision |
|---|---|---|
| **MT Intents** | Routers behind CGNAT cannot be commanded synchronously | **KEEP — core identity** |
| **Router detail → Provisioning** (stepper + desired vs actual) | No competitor evidences lifecycle beyond online/offline | **KEEP — core identity** |
| **Audit Log** | Every router change routes through intents, so the log is complete | **KEEP** |
| **Users & Roles** | Multi-tenant isolation | **KEEP**, needs tenant scope (§3) |
| **Sales** | Reconciliation, distinct from Reports' funnel | **KEEP** |
| **Captive Portal editor** | MikroTicket and Powerlynx both ship one | **DEMOTE** — §1.5 |
| **Reports → Router activity** | Duplicates the Routers list with fewer columns | **REMOVE** |
| **Sales stat tiles** | Duplicate the Dashboard's business tiles | **REMOVE** |

### 1.3 Plan fields — three real gaps against the screenshot

MikroTicket's New plan screen carries fields the prototype does not, and two of them are
genuine hotspot concepts rather than clutter:

| Field | In prototype? | Verdict |
|---|---|---|
| **Users per ticket** | **No** | **ADD.** How many devices one voucher admits is a core commercial lever — a family buys one voucher for four phones. Its absence forces one-device-per-sale |
| **Elapsed vs paused time** | **No** | **ADD as a plan type.** "Paused time" means the clock stops when the guest disconnects. For a hostel selling a 1-week plan this is the difference between a fair product and an unfair one |
| Speed with unit dropdown (Mb/Kb) | Partially — fixed Mbps | **IMPROVE** — minor |
| Simple / Advanced tabs | No | **ADD later.** Good pattern for hiding the two fields above from routine use |

> These are the clearest case in the whole audit of the screenshots teaching something the
> marketing copy did not. Neither field appears in any feature list I found.

### 1.4 Printers

MikroTicket gives printers their own screen because paper width changes the voucher layout
and a counter may have several. The prototype hardcodes two options in a dropdown.

**ADD** a minimal Printers screen under Administration — name, width, transport, address.
Small, and it makes the print preview honest.

### 1.5 Captive Portal — demote

It is a full editor in V1, sitting in Administration with a live preview. But it is
**Step 0 gated**, it is the one screen copied wholesale from competitors, and it competes
for attention with the router lifecycle. §5 of the brief warns precisely about this.

**Decision: DEMOTE.** Keep the screen, move it behind Settings, remove it from the primary
nav until Step 0 proves HotSpot configuration.

---

## 2. Routers must become the centre of the product

**This is the most important change in the audit, and the reviewer is right.**

### The problem, stated precisely

DishNet's differentiator is not voucher generation — docs/43 §5 established that voucher
commerce is table stakes and four capabilities are genuinely differentiating, all of them
descending from *operating a fleet behind CGNAT*.

But V1 reads as a voucher product with a router list attached:

- The Dashboard opens with **Needs Attention**, then business tiles. Fleet state is a
  single tile: "Routers online 6/9".
- **Routers** is the second nav item, styled identically to Plans and Sales.
- Fleet-wide connectivity has **no view at all**. WireGuard health exists only per-router,
  inside a tab, on a detail page.

An operator cannot currently answer *"what is the state of my estate?"* without opening
routers one at a time.

### V2 changes

| # | Change | Why |
|---|---|---|
| R1 | **Routers becomes the default landing view**, not Dashboard | The first question is fleet state |
| R2 | **Fleet health strip** above the router list: online / offline / never-connected / drifted / provisioning / failed — each a one-click filter | The estate at a glance, actionable |
| R3 | **"Never connected" as a first-class cohort** | A router registered but never dialled home is an *installation* problem, not a *network* problem. V1 buries it in `status='registered'` |
| R4 | **Handshake age as a column**, not just a detail field | For a CGNAT fleet, "last handshake" is the vital sign. V1 shows it as a sub-line only when `wg==='up'` |
| R5 | **Bulk select → queue intent** | Genuinely absent. Upgrading 40 routers one at a time is not a product |
| R6 | **Site grouping in the router list** (collapsible) | §3 |
| R7 | Move **Reports → Router activity** into this strip and delete the duplicate | One place for fleet state |

---

## 3. Tenant → Site → Router hierarchy

The reviewer is right that this is foundational and currently hidden.

### Current state

```
ROUTERS[] ── tenant: 'T-01'   (id → name lookup only)
           └ site:   'Riverside Hotel — Lobby'   (a string)
```

Nothing aggregates by tenant. Nothing groups by site. A reseller scope cannot be rendered
because there is no object to scope to.

### Target

```
TENANT (Riverside Hotel)
  ├── users / staff           Owner · Admin · Operator · Sales Agent
  ├── SITE (Lobby)
  │     └── ROUTER MT-0001 ── HotSpot ── SESSIONS
  ├── SITE (Pool)
  │     └── ROUTER MT-0002
  ├── PLANS (assigned)
  ├── VOUCHERS / BATCHES
  └── SALES / revenue
```

### V2 changes

| # | Change |
|---|---|
| T1 | **Site becomes an entity** — `{id, tenant, name, town}`; router references `siteId` |
| T2 | **Tenant detail screen**: their sites, routers, plans, batches, sales, users — the reseller view is this screen scoped to one tenant |
| T3 | **Tenant column/filter everywhere** vouchers, sessions, sales and batches are listed |
| T4 | **Breadcrumb** `Tenant › Site › Router` on router detail, each segment a link |
| T5 | Dashboard gains a **tenant selector** for Super Admin; resellers get it pinned to their own |

> This also fixes the reseller gap docs/43 §5 identified as DishNet's one competitive
> deficit. Once Tenant is a real object, the agent/commission model has somewhere to live.

---

## 4. Dashboard — separate NETWORK from BUSINESS

V1's second band interleaves them: *Routers online · Active sessions · Today's revenue ·
Vouchers sold · Failed intents* — three network, two business, one row, no visual division.

### V2 layout

```
┌──────────────────────────────────────────────────────────┐
│  NEEDS ATTENTION                                   (4)   │  unchanged — keep first
├──────────────────────────────────────────────────────────┤
│  NETWORK                                                 │
│  9 routers · 6 online · 1 offline · 1 never connected    │
│  1 drifted · 1 provisioning · 1 failed intent            │
│  ── oldest handshake: 18 h (JIN-NileBreeze-01)           │
├──────────────────────────────────────────────────────────┤
│  BUSINESS                                                │
│  UGX 39,500 today · 7 vouchers · 6 sessions live         │
│  312 vouchers available · 2 batches low                  │
│  ── by counter: Riverside 3 · Kabale 2 · Equator 2       │
└──────────────────────────────────────────────────────────┘
```

Two labelled bands, each internally consistent. **Needs Attention stays first** — it is the
only band that is a call to action, and V1 got that right.

Added because the brief asks for them and V1 lacks them: **configuration drift**,
**waiting for router**, **active vouchers**, **sales by counter/reseller**.

---

## 5. Guard against feature creep

The brief's fifth point is the one most likely to be ignored, so it is recorded as a rule:

> **A screen earns its place by serving the router lifecycle or the revenue path. Nothing
> is added because MikroTicket has it.**

Applying that to V1:

| Feature | Serves? | Verdict |
|---|---|---|
| Captive portal editor | Neither directly | **Demote** (§1.5) |
| Printers screen | Revenue path — a voucher that cannot be printed cannot be sold | **Add, minimal** |
| Users per ticket | Revenue path — a pricing lever | **Add** |
| Paused-time plans | Revenue path — product fairness | **Add** |
| Reports → Router activity | Neither — duplicates Routers | **Remove** |
| Sales stat tiles | Neither — duplicates Dashboard | **Remove** |
| Advertising monetisation | Neither | **Never** (docs/43 §4C) |
| Multi-vendor support | Neither | **Never** (docs/43 §4C) |

---

## 6. Smaller defects found in V1

| # | Defect | Fix |
|---|---|---|
| D1 | `globalSearch()` jumps to a filtered list but shows no result set; typing a partial match silently does nothing | Proper results dropdown, or remove |
| D2 | Voucher list has no date-range header — MikroTicket's does, and it frames what you are looking at | Add `From … To …` |
| D3 | Router status pill and provisioning pill can contradict (`Online` + `Failed`) with no explanation | Correct, but needs a one-line reading aid |
| D4 | Session `Duration` computed from `start` minutes; `Started` shows a clock time — two representations of one fact | Show both explicitly, or drop one |
| D5 | Batch cards show a three-segment bar with no legend | Add inline legend |
| D6 | `Export` buttons toast "mocked" — fine for V1, but there are four of them | Keep one, remove the rest until real |
| D7 | Plan modal has no delete/retire action although `status: retired` exists in data | Add retire |
| D8 | Intent priority (`high`/`normal`/`low`) is displayed but never sortable or filterable | Make it a filter or remove the column |

---

## 7. What V1 got right — do not change in V2

Recorded so V2 does not regress:

1. **Intent model surfaced in the UI**, with "Queue disconnect" wording and no button
   implying immediacy.
2. **Desired vs actual side by side**, drifted values highlighted.
3. **Six-state stepper that fails in place** rather than on a separate track.
4. **Restart / reset / disable HotSpot absent**, with the modal naming the gate.
5. **Report as a funnel**, not a chart wall; revenue recognised on activation.
6. **Generate + output in one flow.**
7. **Three distinct empty states** (nothing yet / nothing matches / nothing right now).
8. **DishNet tokens and type reused exactly** from the PWA.
9. **Technical gates labelled in-product**, not just in documentation.
10. **No Domain A concept anywhere** — verified, only the boundary comment mentions it.

---

## 8. V2 change list, prioritised

### Priority 1 — product identity (do first)

- **R1** Routers becomes the landing view
- **R2** Fleet health strip with one-click filters
- **R3** "Never connected" cohort
- **R4** Handshake age as a column
- **§4** Dashboard split into NETWORK and BUSINESS bands

### Priority 2 — foundational structure

- **T1** Site as an entity
- **T2** Tenant detail screen
- **T3** Tenant filter on vouchers, sessions, sales, batches
- **T4** `Tenant › Site › Router` breadcrumb

### Priority 3 — commercial completeness

- **§1.3** Users per ticket; elapsed vs paused plan types
- **§1.4** Printers screen
- **R5** Bulk select → queue intent

### Priority 4 — subtraction

- Remove Reports → Router activity
- Remove Sales stat tiles
- Demote Captive Portal out of primary nav
- Fix or remove global search (**D1**)
- D2, D5, D7 as time allows

### Explicitly not in V2

Agent/commission model (needs the business answer from docs/43 §12), mobile money,
customer/guest app, Figma, and any production implementation.

---

## 9. Sequence

```
V1 (built)  →  THIS AUDIT  →  V2 prototype  →  review
                                                 ↓
                              approved → Figma / design system → implementation
```

**Step 0 and B1 remain independently pending hardware** and are not blocked by, nor
blocking, prototype work. Nothing in V2 may assume push or poll.

## 10. Constraints honoured

No code changed. No production, Domain A, Phase 0, uCRM, Starlink plugin, RADIUS, MikroTik
or existing-PWA modification. No migrations, no tables, no Figma, no implementation.
