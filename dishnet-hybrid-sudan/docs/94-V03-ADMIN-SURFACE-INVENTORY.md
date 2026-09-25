# 94 — v0.3 Admin surface inventory

**Status:** INVENTORY ONLY. **No code, schema, projection or architecture
decision changed.** Suite unchanged at **1,488 assertions green**.
Baseline: `6e3cc15`.

Fourteen destinations: twelve in the navigation, two reached by clicking
through. Everything below was read from the panel, the routes and the running
schema.

---

## 1. The two findings that matter more than any single screen

### 1.1 Every Admin projection is unbounded

```
mt_admin_customers   mt_admin_sites     mt_admin_routers   mt_admin_plans
mt_admin_vouchers    mt_admin_sessions  mt_admin_intents   mt_admin_audit
mt_admin_services                                     → NO LIMIT, every row
```

**All nine list projections return the entire table.** No `LIMIT`, no
pagination, no server-side filter, no cursor.

The customer-facing services are bounded — `VoucherService::list()` caps at 200,
`SessionService::live()` likewise. **The Admin API is the unbounded one.** At
three customers this is invisible. At a thousand sites and half a million
vouchers, every screen load serialises the estate.

This is the real answer to the HotSpot scalability question: the problem is not
one screen's layout, it is that **no Admin read has a bound anywhere**. Fixing
the HotSpot card stack without fixing this would move the symptom.

**It is also a boundary change** — the projections are the approved contract —
so it needs its own approval, and §6 records it as a question, not a plan.

### 1.2 Seven of fourteen screens are flat unfiltered lists

The shared `list()` helper is four lines: fetch, render every row, no search, no
filter, no sort, no drill-in.

```js
const list = (title, fetch, key, cols, rowFn, noun) => async () => {
  const res = await fetch();
  if (!isOk(res)) return head(title) + stateBlock(res, noun);
  return head(title, `${res.rows.length}`) + table(cols, res.rows, rowFn);
};
```

Only **Routers** and **Overview** have search and filters. Customers, Plans,
Vouchers, Batches, Intents, Audit and Sessions have none.

---

## 2. The fourteen destinations

Legend — **A** functional · **B** UI exists but incomplete · **C** domain/API
exists, no UI · **D** blocked by an architecture decision · **E** blocked by
hardware / F6-B.

| # | Screen | Class | Data source | Evidence class | Safe to build now |
|---|---|---|---|---|---|
| 1 | Overview | **B** | routers, sites, vouchers, intents, health | recorded + derived | yes |
| 2 | Routers | **A** | `mt_admin_routers` + name resolution | recorded | — |
| 3 | Router Detail | **A** | `mt_admin_router` + 6 more | recorded, derived, unavailable | — |
| 4 | HotSpot | **B** | `mt_admin_services`, plans, vouchers | recorded | **yes — see §4** |
| 5 | HotSpot Detail | **C** | all projections exist | recorded | **yes** |
| 6 | Active Sessions | **B** | `mt_admin_sessions` + voucher join | measured (RADIUS) + derived | yes |
| 7 | Provisioning Jobs | **B** | `mt_admin_intents` | recorded | yes |
| 8 | Diagnostics | **A** | `network-signals` + health | evidence | — |
| 9 | Network Health | **A** | `network-signals` | evidence | — |
| 10 | Customers & Sites | **B** | `mt_admin_customers` only | recorded | yes |
| 11 | Customer Detail | **C** | `mt_admin_customer` **built, client method unused** | recorded | **yes** |
| 12 | Plans | **B** | `mt_admin_plans` | recorded | yes |
| 13 | Vouchers | **B** | `mt_admin_vouchers` | recorded | yes |
| 14 | Voucher Detail | **A** | `mt_admin_voucher` | recorded | — |
| 15 | Batches | **B** | `mt_admin_voucher_batches` | recorded | yes |
| 16 | Audit Log | **B** | `mt_admin_audit` | recorded | yes |

*(Sixteen rows for fourteen destinations: HotSpot Detail and Customer Detail do
not exist yet and are listed as the gaps they are.)*

### 2.1 The notable ones

**Customer Detail (C).** `mt_admin_customer(uuid)` exists, and `api.customer(id)`
exists in the panel client — **and is the only client method no view calls.**
The projection and the transport are built; only the screen is missing. This is
the cheapest real gain on the list.

**Customers & Sites (B).** The nav says "Customers & sites". The screen shows
**customers only**. `mt_admin_sites()` exists and is already fetched by four
other views for name resolution. Sites have no screen of their own.

**Provisioning Jobs (B).** Renders kind, state, attempts, target, created. It
does **not** surface `failed` distinctly, has no filter by state, and no retry
affordance — correctly, since retry is a write and no write route is bound.

**Audit Log (B).** A flat list of every row, no filter by actor, action, target
or date. Audit is the table most certain to grow without bound, and it is the
screen with the least ability to narrow.

**Overview (B).** Six tiles and an honest caption. The tiles are derived from
whole-table fetches — four unbounded reads to compute six numbers.

---

## 3. Evidence classification across the surface

The vocabulary now in use, and where each applies:

| Class | Meaning | Where |
|---|---|---|
| **MEASURED** | a value from a row this system wrote | provisioning state, sessions (estate-wide) |
| **RECORDED** | a value a person or process entered; not an observation | WAN interface assignment, service status, plans, vouchers, batches, customers, sites |
| **DERIVED** | computed client-side from two recorded sources | session → site and plan via the voucher; router counts |
| **MEASURED, NOT EXPOSED** | Domain B holds it; the Admin API does not serve it | uplink telemetry (D-4 open) |
| **NOT MEASURED** | no source exists | WAN link, WireGuard, RADIUS, HotSpot service, last contact |
| **NOT ATTRIBUTABLE** | the relationship does not exist in the data | sessions → router |

Most of the Admin surface is **recorded**, not measured. That is the honest
description of a control plane that has never contacted a router.

---

## 4. HotSpot scalability — the specific question

**Yes: it should become an index plus a detail screen.** Confirmed by reading
the code, not by preference.

Today `vHotspot()` renders, for **every** service: a customer heading, a
three-column state row, a full plans table, and a voucher-count strip. Roughly
forty vertical pixels of chrome per customer before any content. Three
customers fill a screen; a hundred is unusable; a thousand is a several-megabyte
document.

It also performs **six whole-table fetches** on load — services, plans,
vouchers, customers, sites, signals — and filters all of them client-side, per
card. Card count scales the rendering; the fetches are already estate-sized
regardless.

**Recommended shape:**

```
HotSpot (index)                         → one row per service
  search customer / site
  filters: all · active · no service · needs attention
  columns: Customer · Site · Service · Plans · Vouchers · Unused

HotSpot Detail (per customer/site)      → today's card content
  service state (recorded) · RADIUS (not measured) · active users (not attributable)
  plans table · voucher counts · voucher lifecycle
```

Two caveats the inventory turned up:

- **"Needs attention" has no honest definition yet.** The signals that would
  populate it — RADIUS health, HotSpot service state — are `NOT MEASURED`. A
  filter of that name today could only mean "has no plans" or "has no
  vouchers", which is a commercial observation, not an operational one. Name it
  for what it actually filters or leave it out.
- **The voucher lifecycle panel belongs on the index, once**, not repeated per
  card. It is a statement about the whole system, not about one customer.

---

## 5. Screen-type classification

| Type | Screens |
|---|---|
| **Index / list** | Routers, HotSpot *(after §4)*, Active Sessions, Provisioning Jobs, Customers, Sites *(missing)*, Plans, Vouchers, Batches, Audit Log |
| **Detail** | Router Detail, Voucher Detail, HotSpot Detail *(missing)*, Customer Detail *(missing)* |
| **Operational workflow** | **none exist.** Every workflow is a write, and no write route is bound |
| **Diagnostic / evidence** | Diagnostics, Network Health |

**There is no operational workflow screen in the product**, and there cannot be
one until a write route is bound. Worth stating plainly: the Admin panel is
today a complete *reading* surface and an empty *acting* one.

---

## 6. What is safe to build now

Ordered by value per unit of risk. All of it is UI on existing projections.

| # | Work | Why |
|---|---|---|
| 1 | **HotSpot index + HotSpot Detail** | the §4 split; the screen that does not scale |
| 2 | **Customer Detail** | projection and client method already exist and are unused |
| 3 | **A Sites screen**, or Customers & Sites made to show both | the nav already promises it |
| 4 | **Search and filter on the seven flat lists** | client-side first; see the caveat below |
| 5 | **Audit filters** (actor, action, target, date) | the fastest-growing table has the least narrowing |
| 6 | **Provisioning Jobs: surface `failed` distinctly**, filter by state | operationally the most useful signal already held |

**The caveat on 4 and 5.** Client-side filtering of an unbounded fetch improves
the *screen* and not the *load*. It is the right first step and the wrong last
one. Doing it does not fix §1.1.

## 7. Needs approval before it can be built

| Item | What it is |
|---|---|
| **Bounding the Admin projections** | `LIMIT` + cursor/offset, or server-side filter arguments. Changes the approved read contract, so it needs approval like 021 did. **Recommended as the next boundary decision after v0.3's UI work.** |
| **A sites index projection** | not needed — `mt_admin_sites()` already exists and is already fetched |

## 8. Blocked — not buildable, and should stay unavailable

| Blocked by | Items |
|---|---|
| **Architecture decision** | `activating` state and the voucher expiry writer (F-7); voucher code exposure (Decision 3); uplink telemetry to Admin (**D-4**); per-router session attribution (no NAS→device mapping); every write route (**W-4** staff identity) |
| **Hardware / F6-B** | RADIUS health, HotSpot service state, WAN link state, WireGuard handshake, last-contact liveness, RouterOS read-back, reboot, reset, reprovision, configuration push |

Six screens carry at least one `NOT MEASURED` signal today. When F6-B opens,
**those are the exact fields that change** — and because the inventory is
declared server-side in `SignalReport`, the change will be one file, not a
search through the UI.

---

## 9. What this inventory did not do

No code, schema, migration, projection, role, privilege or UI change. No new
projection. No architecture decision altered. No production contact, no Domain A
contact. F6-B not started. Suite unchanged at **1,488 assertions green**.
