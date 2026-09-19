# 42 — Domain B Management UX / Information Architecture Specification

**Status:** DRAFT FOR REVIEW. Specification only — no code, no migrations, no tables.
**Scope:** Domain B only — the new DishNet-managed Zero-Touch MikroTik + HotSpot platform.
**Boundary:** docs/41 is binding. Domain A (existing Starlink system) is **not represented
anywhere in these screens**.
**Date:** 2026-09-19

**Reference patterns.** MikroTicket, Mikhmon / Mikhmon Online, MikroRadius, Powerlynx and
MikroTik User Manager are referenced as a *product class* — voucher-batch generation,
print/share workflows, operator-first business dashboards. **No UI is copied**, and the
Play Store listing could not be opened from this environment, so nothing here claims to
reproduce a specific screen from it. If particular screens matter, screenshots would let
me respond to the actual design rather than the category.

---

## 0. The single most important design decision in this document

**B1 has not been run. It decides whether the control plane can PUSH to an idle router or
must wait for the router to POLL.** (docs/32 `B.idle`, docs/00 §14.)

A naive UI would put a **Disconnect** button on a session and assume it acts immediately.
If B1 returns POLL, that button is a lie: the action cannot happen until the router next
checks in, which could be seconds or minutes.

Rewriting the UI after B1 would be expensive. Designing it twice is unnecessary.

### The design response: every remote action is an INTENT, not a command

No screen in this specification issues a synchronous instruction to a router. Instead:

```
Operator acts  →  intent recorded  →  queued  →  delivered  →  confirmed by device
                       │                                              │
                       └──────── visible state at every step ─────────┘
```

Every remote action therefore has a **state**, not just a result:

| State | Meaning to the operator |
|---|---|
| **Queued** | We have accepted your instruction. Not yet delivered. |
| **Sent** | Delivered to the device. |
| **Confirmed** | The device reported it applied. |
| **Failed** | It did not apply. Reason shown, retry offered. |
| **Expired** | Not delivered within its validity window. |

**This model is correct under both B1 outcomes.** If B1 = PUSH, `Queued → Confirmed`
happens in under a second and the UI simply feels instant. If B1 = POLL, the same states
show honest progress against the check-in interval. **Nothing in the UI needs redesigning
either way** — only the latency changes, and latency is displayed, not assumed.

It also produces a better product under PUSH: a router that is genuinely offline still
accepts intents, which apply when it returns, instead of the operator getting an error
they cannot act on.

**Design rule, applying to every screen below:** no button may imply immediacy. Actions
say what they will do — *"Queue disconnect"* — and the result appears as state.

---

## 1. Product principle — the five questions

The operator must be able to answer these within one screen and no scrolling:

1. What routers do I have?
2. Which are online / offline / problematic?
3. How many customers and sessions are connected?
4. How many vouchers, sales and how much revenue?
5. **What requires my attention?**

Question 5 is the only one that is a *call to action*. It gets the most visual weight.

**The operator must never need to understand RouterOS.** Business and operational
information first; technical detail available on demand, never in the primary path.

---

## 2. Glossary — fixed terminology

Ambiguous words cause the worst UI. These are binding for Domain B.

| Term | Means | Never means |
|---|---|---|
| **Router** | One DishNet-managed MikroTik device | A Domain A customer-premises router |
| **Site** | A physical location that has one or more routers | A web page |
| **Tenant** | A business operating hotspots through DishNet (hotel, café, landlord). DishNet's commercial customer | An end user |
| **Reseller** | A tenant with voucher-selling rights. A *role*, not a separate entity | A distributor of hardware |
| **Guest** | A person who redeems a voucher and gets internet | A DishNet customer |
| **Session** | One guest's authenticated connection | A login to this admin UI |
| **Voucher** | One redeemable credential | A discount coupon |
| **Batch** | A set of vouchers generated together under one plan | A background job |
| **Plan** | Commercial product: duration, data, speed, price | A RADIUS profile |
| **Profile** | Technical enforcement settings a plan maps onto | A user profile |
| **Provisioning** | Getting a registered router to HOTSPOT READY | Manual configuration |
| **Intent** | A queued remote action with a lifecycle | An immediate command |

### Why "Customer" is deliberately not a top-level concept

The proposed navigation had **Customers** and **Services** as top-level items. That is ISP
thinking — and specifically it is *Domain A* thinking, where a customer has a subscription.

In a hotspot business there are **two different parties** and calling both "customer"
guarantees confusion:

- the **tenant** who buys the hotspot service from DishNet, and
- the **guest** who buys a voucher from the tenant.

This specification uses **Tenant** and **Guest**, and there is no screen called
"Customers". Guests appear as **Sessions** (live) and **Voucher redemptions** (historical),
which is what an operator actually looks for.

---

## 3. Information architecture — revised, with rationale

### 3.1 Problems with the proposed structure

| Problem | Evidence | Fix |
|---|---|---|
| **Active Sessions appears twice** — under Customers and under HotSpot | Violates the stated principle "no duplicate navigation" | One location: **HotSpot → Sessions** |
| **Alerts appears twice** — Network → Offline/Alerts, and Operations → Alerts | Same | One location: **Operations → Alerts**, surfaced on the Dashboard |
| **Customers / Services as top-level** | Conflates tenant with guest (§2) | Replaced by **Tenants** under Administration, and **Sites** under Network |
| **Provisioning appears twice** — Network → Provisioning, Operations → Provisioning Queue | Same concept, two names | One: **Operations → Provisioning** |
| **Reports has five children** | Premature for MVP; reporting needs real data shapes first | Single **Reports** section, tabbed |
| **Router Groups as a peer of Routers** | Groups are a property, not a destination | Folded into Routers as a filter/grouping control |

### 3.2 Proposed navigation

```
DASHBOARD

NETWORK
  Routers                 ← the estate; grouping is a filter here
  Sites                   ← physical locations
  Provisioning            ← lifecycle work in progress

HOTSPOT
  Plans                   ← commercial products
  Profiles                ← technical enforcement  [ADMIN ONLY]
  Vouchers                ← individual credentials
  Batches                 ← how vouchers are actually created and managed
  Sessions                ← who is connected right now
  Usage                   ← consumption over time

SALES
  Voucher Sales
  Revenue
  Reseller Balances

OPERATIONS
  Alerts                  ← the single alert destination
  Intents                 ← queued/failed remote actions  ← NEW, see §0
  Installation Queue

REPORTS                   ← one destination, tabbed

ADMINISTRATION
  Tenants
  Users
  Roles
  Settings
  Audit Log
```

### 3.3 One addition: Operations → Intents

This screen does not exist in the proposed structure and is **required** by the design in
§0. Every remote action creates an intent; the operator needs one place to see what is
queued, what failed and why, and to retry.

Under PUSH this screen is mostly empty and reassuring. Under POLL it is the operational
heart of the system. **It must exist before B1, because we do not yet know which.**

---

## 4. Role / permission matrix

| Area | Super Admin | Operations / Technician | Reseller |
|---|---|---|---|
| Dashboard | Full estate | Operational view | Own business only |
| Routers — view | All | All | Assigned only |
| Routers — register/edit | Yes | Yes | No |
| Routers — queue remote action | Yes | Yes | No |
| Sites | Full | Full | Own only, read |
| Provisioning | Full | **Full — primary user** | No |
| Plans — view | Yes | Yes | Assigned only |
| Plans — create/edit | **Yes** | No | No |
| Profiles | **Yes — only role** | Read | No |
| Vouchers — view | All | All | Own batches only |
| Vouchers — generate | Yes | No | **Yes, within quota** |
| Vouchers — disable/expire | Yes | No | Own only |
| Sessions — view | All | All | Own routers only |
| Sessions — queue disconnect | Yes | Yes | Own routers only |
| Sales / Revenue | Full estate | No | **Own only** |
| Reseller Balances | Full | No | Own only |
| Alerts | All | **All — primary user** | Own routers only |
| Intents | All | **All — primary user** | Own, read only |
| Tenants / Users / Roles | **Yes — only role** | No | No |
| Settings | **Yes — only role** | Read | No |
| Audit Log | **Yes — only role** | Own actions | Own actions |

**Tenant isolation is a hard requirement.** A reseller must never see another tenant's
routers, guests, vouchers or revenue. This is enforced server-side; the UI must not be the
only thing preventing it.

---

## 5. Dashboard

**Purpose.** Answer the five questions and route the operator to the one thing that needs
them. **Primary user:** all roles, scoped.

### Layout — three bands, in this order

```
┌────────────────────────────────────────────────────────────┐
│  NEEDS ATTENTION                                    (3)    │  ← BAND 1
│  ● KLA-017 offline 18 min      Riverside Hotel   [View]    │
│  ● JIN-004 provisioning failed  step: HotSpot    [Retry]   │
│  ● Batch UG-2041 92% sold       1 Day plan       [Top up]  │
│                                        ...2 more  [All]    │
├────────────────────────────────────────────────────────────┤
│  ESTATE            GUESTS            TODAY                 │  ← BAND 2
│  48 routers        312 active        142 vouchers sold     │
│  45 online         ▲ 18 vs yest.     UGX 710,000           │
│  3 offline                           ▲ 12%                 │
├────────────────────────────────────────────────────────────┤
│  Recent provisioning        Recent alerts                  │  ← BAND 3
│  (last 5, compact)          (last 5, compact)              │
└────────────────────────────────────────────────────────────┘
```

**Band 1 is the product.** It is first, it is the tallest, and it is the only band with
actions. If nothing needs attention it collapses to a single confirming line —
*"Everything is running. 48 routers online, 312 guests connected."* — which is itself
valuable information.

**Band 2 is nine numbers, not thirty.** Each is a link to the filtered list that produced
it. A number the operator cannot act on does not belong here.

**Band 3 is context**, deliberately last and visually quiet.

**Explicitly excluded:** bandwidth graphs, CPU charts, RouterOS version pie charts,
sparklines with no decision attached. The brief says *"must not become a wall of
statistics"* — the defence is requiring every element to answer "and then what?"

| | |
|---|---|
| **Primary actions** | Act on an attention item |
| **Secondary** | Drill into any metric |
| **Filters** | Date scope (Today / 7d / 30d) affects Band 2 only |
| **Empty state** | New estate: *"No routers yet."* + **Register first router** |
| **Error state** | Per-band degradation. If sales data fails, Bands 1 and 2 still render; the sales tile shows *"Couldn't load — retry"*. **Never blank the whole page for one failed query.** |
| **Permissions** | Reseller sees own scope; revenue hidden without permission |
| **Dependencies** | Router online/offline is **TECHNICAL GATE — PENDING B1**: what "online" means and how fast it is detected depends on push vs poll (§9) |

---

## 6. Routers

### 6.1 Router list

**Purpose.** The estate at a glance, filterable to any operational question.
**Primary user:** Operations.

**Columns:** Status · Name · Site · Tenant · Model · RouterOS · Active guests · Last seen ·
Provisioning state

**Status is one glyph with a consistent vocabulary** — ● online, ○ offline,
◐ provisioning, ▲ needs attention. Colour is never the only signal (accessibility).

| | |
|---|---|
| **Primary actions** | Open router; Register new router |
| **Secondary** | Bulk select → queue action; Export CSV |
| **Filters** | Status, provisioning state, tenant, site, model, RouterOS version, group, last-seen window |
| **Search** | Name, ID, serial, site, tenant — one box, no field picker |
| **Empty state** | *"No routers registered yet."* + **Register a router** + link to the staging guide |
| **Filtered-empty** | *"No routers match these filters."* + **Clear filters** — distinct from the above, never the same message |
| **Error state** | *"Couldn't load routers."* + Retry. Cached list shown greyed with an "as of HH:MM" stamp if available |
| **Permissions** | Reseller: assigned routers only |
| **Dependencies** | "Last seen" semantics — **TECHNICAL GATE — PENDING B1** |

### 6.2 Router detail

**Tabs:** Overview · Guests · Sessions · Vouchers · Network · Provisioning · Events · Audit

**Overview** carries the identity block (name, ID, status, tenant, site, model, RouterOS,
last seen, active guests, provisioning state) plus the **provisioning stepper** (§7) and
any open intents.

**Network** is the one place RouterOS-shaped information is allowed — addresses, interface
state, WireGuard peer status. It is a *tab*, not the landing view, and it is read-only.

| | |
|---|---|
| **Primary actions** | Queue re-provision; Queue config refresh |
| **Secondary** | Rename; Reassign site/tenant; Disable |
| **Empty states** | Per tab — *"No sessions on this router right now."* |
| **Error state** | Tab-level, not page-level |
| **Permissions** | Reseller: read-only, own routers |
| **Dependencies** | **Reboot / reset / factory-reset are NOT specified.** TECHNICAL GATE — PENDING STEP 0. docs/32 §0.4 proves reset/restore on the bench; until that passes, no screen offers remote reset |

---

## 7. Provisioning workflow

**States**, shown as a horizontal stepper with the current step emphasised:

```
REGISTERED → WIREGUARD ONLINE → DISCOVERED → CONFIGURING → HOTSPOT READY → ACTIVE
```

**Failure is a state of a step, not a separate track.** A failed step shows inline:

```
REGISTERED ──✓  WIREGUARD ONLINE ──✓  DISCOVERED ──✓  CONFIGURING ──✗
                                                        │
                                          HotSpot profile rejected
                                          14:22 · attempt 2 of 3
                                          [Retry]  [View log]  [Get help]
```

Rules: the stepper always shows all six states so the operator sees where they are in the
whole journey; a failed step names **what failed, when, which attempt**; every failure
offers an action; "View log" is available but never required to understand the failure.

| | |
|---|---|
| **Purpose** | Make a multi-step remote process legible without RouterOS knowledge |
| **Primary user** | Operations / Technician |
| **Primary actions** | Retry failed step; Queue full re-provision |
| **Secondary** | View raw log; Mark for manual intervention |
| **Filters** | State, age in state, tenant, site, failure reason |
| **Empty state** | *"Nothing provisioning right now."* |
| **Error state** | Distinguish *"we couldn't reach the device"* from *"the device rejected it"* — different problems, different fixes |
| **Permissions** | Operations and Admin only |
| **Dependencies** | **Entire screen: TECHNICAL GATE — PENDING STEP 0/B1.** Step 0 proves the syntax; B1 decides whether CONFIGURING can begin on demand or only at next check-in. Timing copy is written after B1 |

---

## 8. Plans and Profiles

### 8.1 Plans — commercial

| Field | Notes |
|---|---|
| Name | Operator-facing, e.g. "1 Day" |
| Duration | Time validity |
| Data allowance | Optional |
| Speed limit | Down/up |
| Price + currency | UGX default |
| Availability | Routers / groups / tenants |
| Status | Active / retired |
| Profile mapping | Which technical profile enforces it |

Plan editing is **Admin only**. Resellers select plans; they do not define them.

### 8.2 Profiles — technical, separate on purpose

A **plan** is what you sell. A **profile** is how it is enforced. Keeping them separate
means a price change never risks touching enforcement, and the Profiles screen — the only
genuinely technical screen in the product — can be restricted to Admin.

> **TECHNICAL GATE — PENDING STEP 0/B1.** The RADIUS attribute model is **not assumed**.
> Phase 0 proved `Mikrotik-Rate-Limit`, `Session-Timeout` and `Acct-Interim-Interval`
> return in an `Access-Accept` (docs/36 §7), but which attributes a **real router** honours
> is unproven. Every profile field beyond those three is marked
> **"Pending technical validation"** in the UI and cannot be saved until Step 0 confirms it.

| | |
|---|---|
| **Empty state** | *"No plans yet."* + **Create your first plan**, with a worked example |
| **Error state** | Field-level validation; never lose an in-progress form |
| **Dependencies** | Data-cap enforcement, per-plan speed, idle timeout: all **PENDING STEP 0** |

---

## 9. Vouchers and Batches

**Batches are the primary object, not individual vouchers.** Operators think in batches —
generate 100, print them, watch them sell. Individual vouchers matter only when
troubleshooting one guest. The navigation reflects this: **Batches** is where work happens;
**Vouchers** is a search destination.

### 9.1 Batch detail

```
BATCH #UG-2026-0081                                    [Print] [Export] [Share]
Plan 1 Day · UGX 5,000 · created 14 Sep by J. Okello

  100 generated
  ├─ 83 available          ████████████████░░░░
  ├─  9 sold
  ├─  5 active now
  └─  3 expired

  Revenue UGX 45,000
```

One progress bar carries the whole story. Numbers link to the filtered voucher list.

### 9.2 Voucher status vocabulary

| Status | Meaning |
|---|---|
| **Available** | Generated, not issued |
| **Reserved** | Held for a sale in progress |
| **Sold** | Paid for, not yet redeemed |
| **Active** | Redeemed, session running or within validity |
| **Expired** | Validity elapsed |
| **Disabled** | Manually revoked |

These six are binding — no screen may invent a seventh.

| | |
|---|---|
| **Primary actions** | Generate batch; Print; Export; Share |
| **Secondary** | Disable batch; Extend expiry; Duplicate batch settings |
| **Filters** | Plan, status, batch, date, tenant, router |
| **Search** | Voucher code — must be the fastest path in the product; a guest is standing at a counter |
| **Empty state** | *"No batches yet."* + **Generate your first batch** |
| **Error state** | Generation is atomic: partial batches are never created. On failure, nothing is generated and the operator is told so explicitly |
| **Permissions** | Reseller: generate within quota, own batches only |
| **Dependencies** | Generation, print, export, sale: **READY TO DESIGN** — all server-side. Redemption and Active status depend on RADIUS: **PENDING STEP 0** |

**Printing matters more than it looks.** In this product class vouchers are physical slips.
Print output needs: a layout that fits common paper, readable codes, plan and price on each
slip, and a batch identifier. This is a real deliverable, not an afterthought.

---

## 10. Sessions

**Purpose.** Who is connected, on what, for how long. **Primary user:** Operations, reseller.

**Columns:** Guest identifier (voucher code / username) · Router · Site · Start · Duration ·
Usage · Plan · Status

**Available on demand, not by default:** IP, MAC/device identifier. These are technical and
carry privacy weight; they belong behind a disclosure control, and access is logged.

| | |
|---|---|
| **Primary actions** | View session detail |
| **Secondary** | **Queue disconnect** — worded exactly so (§0) |
| **Filters** | Router, site, tenant, plan, duration band, status |
| **Search** | Voucher code, username |
| **Empty state** | *"No active sessions."* — normal at night, not an error. Copy must not imply fault |
| **Error state** | *"Couldn't load sessions."* + Retry + last-known-good with timestamp |
| **Permissions** | Reseller: own routers. MAC/IP requires elevated permission |
| **Dependencies** | **Live session list: PENDING STEP 0** — depends on RADIUS accounting from a real router. **Disconnect: PENDING STEP 0/B1** — CoA/disconnect is unproven; docs/00 §11 records `radius/incoming` as a menu to verify, not a capability confirmed. Until proven, the action is hidden entirely rather than shown failing |

---

## 11. Reseller dashboard

A **business** dashboard. No estate health, no provisioning, no RouterOS.

```
MY BUSINESS                                        Riverside Hotel

  Routers 8 · 7 online · 1 offline   [?]        Active guests  146

  TODAY          UGX 420,000            84 vouchers sold
                 ▲ 8% vs yesterday

  VOUCHERS       312 available          2 batches running low  [Top up]

  [Generate vouchers]  [View sales]  [Sessions]
```

The offline router shows a `[?]` that explains in business terms —
*"Guests at this location can't connect. DishNet has been notified."* — not a technical
diagnosis, and not silence.

| | |
|---|---|
| **Primary actions** | Generate vouchers; View sales |
| **Secondary** | Sessions; Batch history |
| **Empty state** | *"No sales yet today."* with yesterday's figure for context |
| **Error state** | Degrade per tile |
| **Permissions** | Own tenant only, enforced server-side |
| **Dependencies** | Sales/vouchers **READY**; active guests **PENDING STEP 0** |

---

## 12. Alerts

Every alert answers five things: **what, when, which device, why it matters, what to do.**

```
● ROUTER OFFLINE                                        18 minutes
  KLA-017 · Riverside Hotel · Kampala
  Guests at this site cannot connect.
  [View router]  [Check history]  [Notify tenant]
```

| Alert | Why it matters | Status |
|---|---|---|
| Router offline | Guests cannot connect | **PENDING B1** — detection latency depends on push/poll |
| WireGuard offline | Router unreachable for management | PENDING B1 |
| Provisioning failure | Router will not serve guests | PENDING STEP 0 |
| RADIUS failure | Authentication down; no one can log in | PENDING STEP 0 |
| HotSpot unavailable | Portal not serving | PENDING STEP 0 |
| High session count | Capacity risk | PENDING STEP 0 |
| Voucher exhaustion | Sales will stop | **READY** |
| Configuration failure | Applied config rejected | PENDING STEP 0 |

**Only "voucher exhaustion" is fully designable today.** The rest depend on signals we
cannot yet produce. They are specified so the UI has a place for them, and marked so nobody
builds a detector that assumes a capability.

**Alert fatigue is a design risk.** Rules: deduplicate by device and type; a flapping router
produces one alert with a flap count, not forty; every alert is acknowledgeable; acknowledged
alerts leave the Dashboard but stay in Operations → Alerts.

---

## 13. Operations → Intents

**Purpose.** One place to see every queued remote action, its state, and why it failed.
**Primary user:** Operations.

**Columns:** Action · Target router · Requested by · Requested at · State · Attempts · Last error

| | |
|---|---|
| **Primary actions** | Retry; Cancel queued intent |
| **Secondary** | View payload (technical, collapsed by default) |
| **Filters** | State, action type, router, tenant, age |
| **Empty state** | *"Nothing queued."* — a good sign, and the copy should say so |
| **Error state** | Distinguish delivery failure from device rejection |
| **Permissions** | Admin and Operations write; reseller read-only, own routers |
| **Dependencies** | **The screen is READY TO DESIGN. Its content is PENDING B1** — under PUSH it is near-empty; under POLL it is the busiest screen in the product |

---

## 14. Reports, Audit, Sales

**Reports** — one destination, tabs: Revenue · Usage · Sessions · Voucher performance ·
Router performance. Deliberately deferred: report design needs real data shapes, and
inventing chart specs before the data exists produces charts nobody uses. **NOT YET
DEFINED** beyond the tab set.

**Audit Log** — every privileged action: who, what, when, from where, result. Read-only,
never editable, never deletable. Filters: actor, action type, target, date. **READY TO
DESIGN** — it records UI actions, independent of router behaviour.

**Sales** — Voucher Sales (transaction list), Revenue (totals by period, plan, tenant,
router), Reseller Balances (owed/settled). **READY TO DESIGN** — server-side, no router
dependency. Payment integration is out of scope for this specification.

---

## 15. Cross-cutting states

### 15.1 Empty states — three different situations, never one message

| Situation | Message pattern |
|---|---|
| **Nothing yet** (new estate) | Explain + primary action + link to guidance |
| **Nothing matches** (filters) | *"No results for these filters"* + **Clear filters** |
| **Nothing right now** (genuinely normal) | Reassure: *"No active sessions."* Never styled as an error |

Conflating these is the most common empty-state mistake: a new operator is told they have a
problem, and an operator with an over-narrow filter is told the data doesn't exist.

### 15.2 Error states

- **Scope errors to the failing component.** One failed query must not blank a page.
- **Say what failed and what to do.** Never a bare code.
- **Distinguish** *can't reach the device* from *the device refused* — different causes,
  different fixes.
- **Never lose operator input.** A form that fails to save keeps its contents.
- **Show staleness rather than nothing**: cached data with "as of HH:MM" beats a blank.

### 15.3 Loading states

- Skeletons matching final layout, not spinners — prevents layout shift.
- Under 300 ms: no indicator at all.
- Over ~3 s: say what is happening (*"Loading 48 routers…"*).
- Per-component, so fast sections render immediately.
- **Queued intents never block the UI.** The action returns instantly with state "Queued".

---

## 16. Mobile and responsive

**This product will be used on phones**, by technicians at installations and by resellers
checking sales. Mobile is not a scaled-down desktop.

| Screen | Mobile priority | Behaviour |
|---|---|---|
| Dashboard | **Critical** | Bands stack; Needs Attention first and full-width |
| Alerts | **Critical** | Native list |
| Router detail | **Critical** | Tabs → accordion; Overview open |
| Provisioning | **Critical** | Stepper goes vertical |
| Reseller dashboard | **Critical** | Designed mobile-first |
| Voucher batches | High | Generate and share must work one-handed |
| Sessions | Medium | Cards, not a table |
| Router list | Medium | Cards with status, name, site, guests |
| Plans / Profiles | Low | Desktop-first, usable on mobile |
| Reports | Low | Desktop |

**Tables become cards below ~768px** — horizontally scrolling tables on phones are a known
failure. Primary actions stay reachable in the thumb zone. Voucher **print** is
desktop-only and should say so rather than offering a broken flow.

---

## 17. Search and filter strategy

**One search box per screen, no field selector.** Search spans the fields that screen's
user would plausibly type. Voucher-code search is the single most latency-sensitive path in
the product — a guest is waiting at a counter.

**Filters are visible, not hidden behind a menu.** Active filters show as removable chips
with a **Clear all**. Filter state belongs in the URL so a view can be shared with a
colleague — which also makes every filtered list linkable from the Dashboard.

**Global search** (later): routers, vouchers, sessions, tenants. **NOT YET DEFINED.**

---

## 18. Feature readiness

### READY TO DESIGN AND BUILD — no technical gate

Dashboard shell · Router list and registration · Sites · Tenants, Users, Roles · Plans
(commercial fields) · Voucher generation, batches, print, export, share · Voucher status
lifecycle up to *Sold* · Sales, Revenue, Reseller Balances · Reseller dashboard shell ·
Audit Log · Alerts framework + voucher-exhaustion alert · Intents screen · All empty/error/
loading patterns · Search and filtering · Mobile layouts

### DEPENDENT ON STEP 0 — hardware syntax and capability

Provisioning execution · Profiles beyond the three proven attributes · Live sessions
(RADIUS accounting from a real router) · Session disconnect (CoA) · Router health signals ·
Remote reboot/reset — **not specified at all until docs/32 §0.4 passes** · RouterOS version
and model reporting

### DEPENDENT ON B1 — push vs poll

Online/offline **detection latency** and what "last seen" means · Intent delivery timing and
all copy about it · Whether provisioning starts on demand or at next check-in · Alert
responsiveness · Real-time expectations throughout

### NOT YET DEFINED

Report designs · Payment integration · Customer mobile app (explicitly out of scope) ·
Global search · Notification channels (email/SMS/push) · Multi-currency beyond UGX

---

## 19. Recommended MVP

**Principle: ship the part that works without a router.** Everything in READY TO DESIGN can
be built, tested and used before Step 0 completes — vouchers can be generated and sold
against routers provisioned by hand.

### MVP — 9 screens

1. **Dashboard** — Needs Attention + estate/guest/today bands
2. **Routers** — list + register
3. **Router detail** — Overview, Provisioning, Events tabs only
4. **Plans** — commercial fields; profiles deferred
5. **Batches** — generate, view, print, export
6. **Vouchers** — search and status
7. **Reseller dashboard**
8. **Alerts** — framework + voucher exhaustion
9. **Audit Log**

### Explicitly deferred past MVP

Sessions (needs Step 0) · Profiles (needs Step 0) · Intents screen — *build the intent
model from day one, but the management screen can wait for B1 to tell us how busy it will
be* · Reports · Sites as a separate screen (a field on Router until there are enough) ·
Usage · Reseller Balances

### Build order

```
1. Tenants, Users, Roles, Audit    ← everything else needs identity
2. Routers + register + Sites-as-field
3. Plans → Batches → Vouchers      ← the revenue path; works without a router
4. Reseller dashboard              ← makes the above sellable
5. Dashboard                       ← needs the data the above produces
6. Alerts framework
   ─── STEP 0 GATE ───
7. Provisioning execution, Profiles
   ─── B1 GATE ───
8. Sessions, Intents screen, real-time behaviour
```

**Steps 1–6 can start now.** They contain no router dependency, no Domain A contact, and
no assumption about push or poll.

---

## 20. Boundary compliance

Verified against docs/41 §4.1. This specification contains **no** reference to
`sl_kits.json`, `wifi_router_map.json`, `dr_accounts.json`, Starlink session cookies,
Starlink router IDs, `dr_wifi_*`, `hotspot_paid_access`, `hotspot_session_log`,
`hotspot_seen_devices` or `ca_hotspot_authz_router()`.

Domain B entities carry the `mt_` convention from docs/41 §4.4 when they reach
implementation. **No tables are proposed or created by this document.**

The word "MikroTik" here means **Domain B only** — the DishNet-managed estate. It does not
refer to the customer-premises MikroTik routers inside the existing Starlink system
(docs/41 §4.1a).

---

## 21. What I need from review

1. **Is Tenant/Guest right?** It removes "Customers" from the navigation. If DishNet
   genuinely sells hotspot service direct to end users as well as to venues, this changes.
2. **Is the reseller a tenant with a role, or a separate entity?** Affects the whole
   permission model.
3. **Is voucher printing physical slips?** It shapes a real deliverable.
4. **Does a router serve one tenant, or can it be shared?** Affects isolation throughout.
5. **Is UGX the only currency for MVP?**
6. **MikroTicket screenshots** would let me respond to the actual reference rather than the
   product category.

No code written. No production, Domain A or Phase 0 changes. No migrations, no tables.
