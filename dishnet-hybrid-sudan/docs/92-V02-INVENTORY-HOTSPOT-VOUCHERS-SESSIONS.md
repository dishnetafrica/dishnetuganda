# 92 — v0.2 inventory: what exists for HotSpot, Vouchers and Sessions

**Status:** INVENTORY ONLY. **Nothing implemented.** No code, schema, migration,
route, projection or UI changed. Suite unchanged at **1,446 assertions green**.

Requested before coding: which pieces already exist, and which are genuinely
new. Everything below was read from the running schema and the source, not from
memory.

---

## 1. Summary — the three screens against reality

| Screen | Buildable now | Blocked |
|---|---|---|
| **HotSpot** | plans, voucher counts by state, site/customer context, RADIUS as `NO SIGNAL` | **HotSpot service state has no Admin read path** (§3.1) |
| **Vouchers** | list, batch view, plan/site/status filters, counts | **voucher detail projection does not exist**; **the code is withheld by design**; **two of four lifecycle states are unreachable** (§4) |
| **Sessions** | estate-wide list, customer context, RADIUS as source, router attribution unavailable | **no username**; **site only derivable via the voucher** (§5) |

**Three of these are not UI work.** They are a missing projection, a missing
CHECK value, and a missing writer — each of which touches an approved boundary
or an open decision.

---

## 2. What already exists

### 2.1 Admin API — 13 GET routes, 7 declared-unbound POSTs

```
GET  /health              /network-signals
GET  /customers           /customers/{id}
GET  /sites               /routers          /routers/{id}
GET  /plans               /vouchers         /voucher-batches
GET  /sessions            /intents          /audit

POST /routers  /routers/{id}/assign  /routers/{id}/actions  /sites
POST /plans    /voucher-batches      /sessions/{id}/disconnect     ← all answer 501
```

### 2.2 Eleven projections

`mt_admin_audit`, `customer(uuid)`, `customers`, `intents`, `plans`,
`router(uuid)`, `routers`, `sessions`, `sites`, `voucher_batches`, `vouchers`.

### 2.3 Domain services

| Service | Surface |
|---|---|
| `VoucherService` | `issueBatch`, `list`, `find`, `revoke`, ~~`redeem`~~, ~~`radiusUsername`~~ |
| `PlanRepository` | `create`, `all`, `find`, `update`, `retire` |
| `SessionService` | `live`, `all`, `find`, `usage` |
| `IntentQueue` | `enqueue`, claim/complete via `mt_intent_*` |
| `DeviceRegistry` | `register`, `assign`, `transition`, `setWanInterface`, `setCredentials`, `setDesired` |
| `AccountingIngest` | `record`, `reap` |
| `UplinkRepository` | `record`, `recent` |

`redeem` and `radiusUsername` are struck through because `docs/87` marks both
for **deletion**: the first has no caller, the second contradicts Decision 3.

**So most of the v0.2 business logic already exists.** What is missing is
mostly *read exposure to Admin*, not domain capability.

---

## 3. HotSpot screen

### 3.1 The one genuine blocker: no Admin read path for a service

`mt_services` carries `id, customer_id, kind, status, started_at, ended_at` —
`kind = 'mikrotik_hotspot'` and a `status`. That is exactly the "HotSpot ●
Configured" the brief asks for.

**There is no `mt_admin_services()` projection.** Eleven exist; services is not
among them. The customer API exposes `/me/services`, but that is tenant-scoped
and the Admin panel is not a tenant.

| Option | Consequence |
|---|---|
| **Add a twelfth projection** | A migration extending the **approved read boundary** (D-1/D-2 settled eleven). Small, low-risk, but it is a boundary change and should be approved as one |
| **Show HotSpot service as `NO SIGNAL`** | Honest but wrong: Domain B *does* hold the fact. This would be the mirror of the uplink error — claiming absence where the data exists |
| **Derive it from the router's lifecycle state** | **Rejected.** `provisioned` means the control plane pushed a configuration, not that a HotSpot server is running. That is exactly the configuration-intent-as-liveness mistake the signal discipline exists to prevent |

**Recommendation: add the projection, as an explicitly approved twelfth.** It is
one `SELECT` over a table the Admin already reads the tenants of, and the
alternative is a screen that lies in one direction or the other.

### 3.2 What needs nothing new

| Element | Source |
|---|---|
| Plans with prices | `mt_admin_plans()` — carries `name, duration_s, price_minor, currency, active` |
| Voucher counts by lifecycle | `mt_admin_vouchers()` — carries `state`; counted client-side |
| Site / customer context | `mt_admin_sites()`, `mt_admin_customers()` |
| RADIUS state | already `NO SIGNAL` in `SignalReport` |
| Active users | already **"not attributable"** per router; estate-wide from `mt_admin_sessions()` |
| `[Generate vouchers]` | `POST /voucher-batches` exists and answers **501** — renders inert with that reason |

---

## 4. Voucher workflow

### 4.1 The lifecycle in the brief cannot be represented

Brief: `unused → activating → active → expired/revoked`.

```
mt_vouchers_state_check: state IN ('unused','active','expired','revoked')
```

| Arrow | Mechanism | State |
|---|---|---|
| issue → `unused` | `VoucherService::issueBatch` | **works** |
| `unused` → `revoked` | `VoucherService::revoke`, bound to the customer API | **works** |
| `unused` → **`activating`** | — | **the state does not exist in the CHECK.** `docs/87` S-1 lists adding it as part of the F-7 remediation, which is **not authorized** |
| `activating` → `active` | the AAA publisher | **not built** (Decision 7) |
| `unused` → `active` | `mt_voucher_redeem` | exists, **has no caller**, and is marked for deletion (F-7) |
| `*` → `expired` | — | **nothing writes it.** Measured: the only `mt_vouchers … state =` write in the codebase is `revoke`. `mt_intents` has an expiry sweep; vouchers have none |

**Measured in the simulated estate: 17 vouchers, all `unused`.** Not a seeding
shortcoming — no other state is currently reachable without the F-7 work.

**So a voucher lifecycle screen can show two states honestly, and must mark the
other two as not-yet-reachable.** Inventing `activating` or a fake `expired`
would be exactly the fabrication the signal discipline forbids, one layer up.

### 4.2 The voucher code is withheld, by design

The brief's mockup lists vouchers by a code-like identifier (`SIM-VCH-00001`).
**`code` is in no Admin projection** — deliberately, because a voucher code is a
bearer credential and `docs/67` §5 keeps it out of every path but redemption.

Admin can therefore identify a voucher only by **uuid**, plus plan, site, state
and timestamps. A code-shaped column on an Admin screen would need a decision to
expose the code, and that decision runs against Decision 3.

### 4.3 Voucher detail does not exist

`mt_admin_vouchers()` is a list; there is no `mt_admin_voucher(uuid)`. Router
and customer both have a single-row projection; vouchers do not. **A voucher
detail page needs a twelfth/thirteenth projection** — same boundary question as
§3.1.

### 4.4 What needs nothing new

Voucher list, batch view, plan and site selection, and status filtering all work
from `mt_admin_vouchers()` + `mt_admin_voucher_batches()` + `mt_admin_plans()` +
`mt_admin_sites()`, filtered client-side.

---

## 5. Sessions

### 5.1 Two fields the brief wants that Admin cannot see

| Wanted | Reality |
|---|---|
| **Username** | `mt_admin_sessions()` returns no username. `radius_username` is withheld from every Admin projection — it is the AAA identity. Showing it would put a credential identifier on an Admin screen |
| **Site** | `mt_sessions` has **no `site_id`**. It carries `voucher_id`, and the voucher carries `site_id`, so site is **derivable client-side** by joining the two lists already exposed. Honest, and needs nothing new |

### 5.2 Already settled and unchanged

- **Router attribution unavailable** — `mt_session_account` never sets
  `device_id`. Keep "not attributable"; never a per-router number.
- **Accounting source is RADIUS** — already stated.
- Plan is reachable via `voucher_id → plan_id`, same client-side join as site.

---

## 6. The genuinely new work, separated by kind

### 6.1 UI only — no boundary change, safe to build

- HotSpot screen: plans, voucher counts, context, inert `[Generate vouchers]`.
- Voucher list, batch view, filters, lifecycle counts with unreachable states
  marked.
- Sessions list with site and plan derived client-side, router attribution
  marked unavailable.
- Router Detail: move the `Needs:` evidence text out to Diagnostics and leave
  concise operator states (the requested UX split).
- Rename `WAN interface — MEASURED` to **`WAN interface assignment — RECORDED`**,
  and label `WAN established by` as staging metadata.

### 6.2 Needs an approved boundary change

| Item | What it is |
|---|---|
| `mt_admin_services()` | a **twelfth projection**, for HotSpot service state (§3.1) |
| `mt_admin_voucher(uuid)` | a **single-row voucher projection**, for voucher detail (§4.3) |

Both are migrations that extend the read boundary D-1/D-2 fixed at eleven.
Neither exposes anything new in kind — services and vouchers are already read as
lists or by tenants — but both should be approved rather than assumed.

### 6.3 Blocked behind existing gates — must NOT be built

| Item | Gate |
|---|---|
| `activating` voucher state | F-7 remediation (`docs/87` S-1), not authorized |
| voucher expiry writer | same; no sweep exists |
| real redemption / AAA publication | Decisions 1, 3, 7; F6-B |
| exposing the voucher code to Admin | runs against Decision 3 |
| uplink telemetry to Admin | **D-4, open** |
| per-router session counts | needs a NAS-identifier → device mapping that does not exist |
| every real-network action | F6-B |

---

## 7. Recommended v0.2 scope

Build §6.1 in full. It is real product surface and needs no new privilege.

For §6.2, two small projections would make HotSpot and Voucher Detail truthful
rather than artificially hollow. **They need explicit approval**, so they are
recorded here as a question rather than taken.

Everything in §6.3 stays unavailable and says so.

---

## 8. What this inventory did not do

No code, schema, migration, route, projection, role, privilege or UI change. No
production contact, no Domain A contact. No gate moved. Suite unchanged at
**1,446 assertions green**.
