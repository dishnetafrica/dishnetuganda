# 42 — Domain B Management UX / Information Architecture Specification

**Status:** **APPROVED BASELINE** (2026-09-19). Specification only — no code, no
migrations, no tables. The Intent model (§0) is specifically approved and is retained
regardless of the B1 outcome.
**Scope:** Domain B only — the new DishNet-managed Zero-Touch MikroTik + HotSpot platform.
**Boundary:** docs/41 is binding. Domain A (existing Starlink system) is **not represented
anywhere in these screens**.
**Date:** 2026-09-19

**Reference patterns — product class only.** MikroTicket, Mikhmon / Mikhmon Online,
MikroRadius, Powerlynx and MikroTik User Manager are referenced **as a category of
product**, for the patterns that category has settled on: voucher-batch generation,
print/share workflows, operator-first business dashboards.

> **No screen of any of these products was studied.** The Play Store listing could not be
> opened from this environment. Nothing in this document reproduces, adapts or responds to
> MikroTicket's actual UI, and no claim to the contrary should be read into it. If its
> specific screens are to inform the design, screenshots are required first.

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

**The approved intent lifecycle:**

```
Queued  →  Sent  →  Confirmed
   │         │
   │         └──→  Failed  ──[retry]──→  Queued
   │
   └──────────────→  Expired        (not delivered within its validity window)
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

> **Scope of this vocabulary.** These terms are **Domain B terminology**. They describe
> how the Zero-Touch MikroTik platform models its world. They are **not** a claim about
> DishNet's universal customer model, and they do not redefine "customer" anywhere else in
> the business or in Domain A.

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

## 11. Tenant dashboard (the Dashboard in tenant scope)

> **REVISED per approved decision 2.** Since Reseller is a **role of a Tenant**, not a
> separate entity, this is **not a separate screen**. It is the Dashboard (§5) rendered
> with tenant scope and the operational bands removed. One screen, two scopes — which
> removes a screen from the inventory and keeps the two views from drifting apart.

A **business** view. No estate health, no provisioning, no RouterOS.

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

## 19. MVP — approved screen set and order

**Approved implementation priority:**

| # | Screen | Gate | Buildable now? |
|---|---|---|---|
| 1 | **Dashboard** | — | **Yes** (see note below) |
| 2 | **Routers** | — | **Yes** |
| 3 | **Router Details** | partial | **Yes** — Overview, Provisioning, Events tabs |
| 4 | **Plans** | partial | **Yes** — commercial fields only |
| 5 | **Vouchers** | — | **Yes** |
| 6 | **Voucher Batch** | — | **Yes** |
| 7 | **Sessions** | **STEP 0** | Shell only |
| 8 | **Operations / Intents** | **B1** | Shell only |
| 9 | **Reports / Revenue** | — | **Yes** |

**Deferred past MVP, as approved:** Administration (Tenants, Users, Roles), Audit Log,
advanced reporting, Profiles, Sites as a separate screen, Usage, Reseller Balances.

### Three build-order facts worth stating before anyone starts

**1. The Dashboard needs an alert source even though the Alerts screen is deferred.**
Band 1 of §5 is *Needs Attention* — it is the reason the Dashboard exists. It reads from
the alert framework. So the **alert engine is MVP** (at minimum the voucher-exhaustion
rule, which needs no router); only the dedicated Alerts *management screen* is deferred.
Building the Dashboard without any alert source produces an empty top band and a product
that answers four of the five questions in §1.

**2. Screens 7 and 8 can be built as shells but not completed.** Sessions needs RADIUS
accounting from a real router (Step 0). The Intents screen is structurally ready, but how
busy it is — and therefore how it should be laid out and sorted — depends on B1. Build the
**intent model** from day one, as approved; the **screen** finishes after B1.

**3. Screens 5 and 6 are one piece of work.** Voucher search is only useful once vouchers
exist, so batch generation has to function before the Vouchers screen has anything to show.
They are listed separately because they are separate destinations, not separate milestones.

### What can start immediately, with no gate

Screens 1–6 and 9, plus the alert engine and the intent model. That is the **entire revenue
path** — a tenant can be created, routers registered, plans defined, vouchers generated,
printed, sold and reported on, against routers provisioned by hand. None of it contacts
Domain A, and none of it assumes push or poll.

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

## 21. Approved product decisions

Recorded as **decisions**, not assumptions. Each was answered by the business; none was
inferred here.

| # | Decision | Consequence in this specification |
|---|---|---|
| **1** | **Tenant** = the business operating a HotSpot service through DishNet. **Guest** = the end user who connects and consumes a voucher. "Customer" stays out of Domain B navigation | §2 glossary is binding for Domain B, and explicitly **not** a claim about DishNet's universal customer model |
| **2** | **Reseller is a role of a Tenant**, not a separate entity. Tenant user roles: Owner, Admin, Operator, Sales Agent | §11 becomes the Dashboard in tenant scope, not a separate screen. **No Reseller entity is created** |
| **3** | **One router belongs to one Tenant.** Shared-router tenancy is not designed | Clean isolation throughout. If it is ever needed it becomes an explicit architecture decision, never an accidental UI feature |
| **4** | **A voucher belongs to a Tenant** and is redeemable only on that Tenant's routers | Vouchers are never globally interchangeable between tenants |
| **5** | **Reporting is tenant-scoped.** Super Admin aggregates across tenants | Every list, total and export carries the tenant filter as a server-side constraint |
| **6** | **The Guest/customer app is not designed here.** It follows once the management model is frozen | Out of scope for this document |

> **These are UX and domain assumptions for the MVP.** They remain subject to the actual
> commercial model and to technical validation. **They must not become database migrations
> yet.** No table is proposed, created or implied by this document.

---

## 22. MVP domain model

Entities and the relationships between them. **Conceptual only — no schema, no tables, no
migrations.**

```
                    ┌──────────┐
                    │  TENANT  │  the business operating hotspots
                    └────┬─────┘
          ┌──────────────┼──────────────┬──────────────┐
          │              │              │              │
   ┌──────▼─────┐  ┌─────▼────┐  ┌──────▼───┐  ┌───────▼──────┐
   │TENANT USER │  │  ROUTER  │  │   PLAN   │  │   VOUCHER    │
   │ Owner      │  │ 1 tenant │  │ duration │  │ belongs to   │
   │ Admin      │  │ only     │  │ data     │  │ 1 tenant     │
   │ Operator   │  └────┬─────┘  │ speed    │  │ 1 plan       │
   │ Sales Agent│       │        │ price    │  └───┬──────┬───┘
   └────────────┘       │        └────┬─────┘      │      │
                        │             │            │      │
                   ┌────▼─────┐       │      ┌─────▼──┐ ┌─▼──────┐
                   │  INTENT  │       │      │ SESSION│ │  SALE  │
                   │ queued   │       │      │ 1 guest│ │ revenue│
                   │ action   │       │      │ 1 rtr  │ └────────┘
                   └──────────┘       │      └────┬───┘
                                      └───────────┘
                    ┌──────────┐  ┌─────────────┐
                    │  ALERT   │  │ AUDIT EVENT │
                    │ about a  │  │ who did what│
                    │ router / │  │ when, result│
                    │ batch    │  └─────────────┘
                    └──────────┘
```

### Entity definitions

| Entity | Is | Owned by | Notes |
|---|---|---|---|
| **Tenant** | A business operating hotspots through DishNet | — | The isolation boundary for everything below |
| **Tenant User** | A person acting for a tenant | Tenant | Roles: Owner, Admin, Operator, Sales Agent |
| **Guest** | An end user who redeems a voucher | *not owned* | Not an account. Exists only as sessions and redemptions |
| **Router** | One DishNet-managed MikroTik | **Exactly one Tenant** | Carries provisioning state; never shared |
| **Plan** | Commercial product: duration, data, speed, price | DishNet (assignable to tenants) | Maps to a technical profile |
| **Voucher** | One redeemable credential | **Tenant** | Redeemable only on that tenant's routers |
| **Batch** | A set of vouchers generated together | Tenant | The primary working object for operators |
| **Session** | One guest's authenticated connection | Tenant, via router | **PENDING STEP 0** |
| **Sale** | A voucher transaction and its revenue | Tenant | No router dependency |
| **Intent** | A queued remote action with a lifecycle | Router → Tenant | §0. Model built day one |
| **Alert** | A condition needing attention | Router or Batch → Tenant | Engine is MVP; screen deferred |
| **Audit Event** | A privileged action record | Tenant (actor) | Append-only |

### Ownership rules — the ones that matter

1. **Tenant is the isolation boundary.** Every query is tenant-scoped server-side. The UI is
   never the only thing enforcing it.
2. **A router has exactly one tenant.** No shared tenancy is designed (decision 3).
3. **A voucher is redeemable only on its tenant's routers** (decision 4).
4. **A guest is not an account.** There is no guest record to manage, only sessions and
   redemptions — which is why there is no "Customers" screen.
5. **Super Admin aggregates; tenants never do** (decision 5).

---

## 23. Screen-to-entity map

| # | Screen | Primary entity | Also reads | Writes |
|---|---|---|---|---|
| 1 | Dashboard | — (aggregate) | Router, Session, Sale, Voucher, Alert | none |
| 2 | Routers | **Router** | Tenant, Site | Router (register, edit) |
| 3 | Router Details | **Router** | Intent, Session, Voucher, Audit Event | Intent |
| 4 | Plans | **Plan** | Tenant | Plan |
| 5 | Vouchers | **Voucher** | Batch, Plan, Tenant | Voucher (disable) |
| 6 | Voucher Batch | **Batch** | Plan, Voucher, Tenant | Batch, Voucher (generate) |
| 7 | Sessions | **Session** | Router, Voucher, Plan | Intent (queue disconnect) |
| 8 | Operations / Intents | **Intent** | Router, Tenant User | Intent (retry, cancel) |
| 9 | Reports / Revenue | **Sale** | Voucher, Plan, Tenant | none |

**Every screen that changes a router writes an Intent, never a router command.** That is
§0 expressed as a data rule: the only write path to hardware is the intent queue.

---

## 24. Technical-gate matrix

| Capability | Gate | Until it passes |
|---|---|---|
| MikroTik model and RouterOS version | **STEP 0** | Not displayed as verified anywhere |
| WireGuard peer syntax (`endpoint-address`, `endpoint-port`, `allowed-address`, `persistent-keepalive`) | **STEP 0** | Provisioning cannot execute |
| REST availability and configuration | **STEP 0** | No REST-dependent feature is specified |
| `reset` / `run-after-reset` | **STEP 0** | **No remote reset/reboot UI exists at all** |
| HotSpot syntax | **STEP 0** | Provisioning step CONFIGURING undefined |
| RADIUS syntax and attributes beyond the three Phase 0 proved | **STEP 0** | Profile fields show *"Pending technical validation"* |
| Live session list | **STEP 0** | Sessions screen is a shell |
| Session disconnect (CoA) | **STEP 0** | Action **hidden**, not greyed |
| **PUSH vs POLL** | **B1** | No UI claims either. Intent states carry timing; no copy asserts immediacy |
| Online/offline detection latency | **B1** | "Last seen" shown as a timestamp, never as a promise |
| Provisioning start timing | **B1** | Stepper shows state, not ETA |
| Intent delivery timing | **B1** | Intents screen finished after B1 |

> **No screen in this document claims that push works, that polling is required, that
> remote disconnect is possible, that CoA is supported, or that any RouterOS endpoint or
> RADIUS attribute exists beyond those Phase 0 actually proved.**

---

## 25. Status

**docs/42 is the approved UX and domain baseline for Domain B.**

Approved: the Intent model — **Queued → Sent → Confirmed → Failed → Expired**, retained
regardless of the B1 outcome; no synchronous router commands; "Queue disconnect" wording;
Operations → Intents as a first-class screen; no remote reboot/reset UI before the gate;
disconnect hidden until CoA is proven; technical-gate labelling; duplicate navigation
removed; no Domain A concepts in Domain B screens; the six product decisions in §21.

**Not done and not to be done yet:** no code, no Figma, no frontend implementation, no
migrations, no tables, no production change, no Domain A change, no Phase 0 change.

**Next gates, in order:** physical MikroTik **Step 0** (independently pending hardware) →
**B1** push/poll → then a decision on whether to move these screens into design or
implementation.
