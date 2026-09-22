# 55 — Domain B Implementation Plan

**Status:** PLAN FOR APPROVAL — no code written
**Governed by:** docs/53 F1–F13 (binding) · docs/30 (architecture) · docs/42 (UX) · docs/45 (identity)
**Changes made:** NONE.

---

## 0. Three findings from docs/30 that shape this plan

Read before planning, per your instruction to treat the architecture documents as source of
truth. All three change the answer.

### 0.1 docs/30 already names C20 as a pre-Phase-1 blocker

docs/30 *"What must be answered before Phase 1"* lists five questions. Two are live:

> **2. Who supplies the routers?** *"If DishNet sells most of them, pre-staging is cheaper,
> more reliable and a better customer experience than field provisioning — and it changes
> the priority of the entire QR flow."*

That is C20, identified as a Phase 1 gate before this session began.

> **5. Where would this run?** *"The plugin lives in the uCRM container. The control plane,
> PostgreSQL, Redis, FreeRADIUS and the concentrator do not fit there, and a WireGuard
> concentrator needs a stable public endpoint."*

**Q5 is still unanswered and it is not a commercial question.** It blocks deployment, not
design — so the plan below proceeds, but §L records it as the first infrastructure decision.

**Your instruction is nonetheless right:** C20 changes *how a router arrives*, not what a
customer, service, site, plan, voucher, session or intent *is*. §L draws that line exactly.

### 0.2 The backend is a separate service — docs/30 is explicit

> *"The network API is **a separate service** on its own host with its own database... The
> plugin may call it. It does not contain it."*

with the reason stated: putting it in `api/v2/router.php` means *"a bug in voucher
generation would then be able to take down invoicing."*

**Consequence for your brief's `mt_*` instruction.** That prefix originated in docs/41 §7 as
protection against Domain A collision *inside the plugin's SQLite*. In a separate PostgreSQL
database the collision cannot occur, so the prefix is redundant — **but I propose keeping it
anyway.** It costs nothing, and it makes `grep mt_` unambiguous across both codebases once
the plugin becomes a read-only client in Phase 4. Flagged so the redundancy is deliberate
rather than accidental.

### 0.3 docs/30's data model predates Site, Service and Intent

docs/30 Artifact 8 models `tenants → devices` directly. The identity chain frozen since then
(docs/45, F5) is:

```
Customer → Service → Site → Router → Voucher → Device → Session
```

**`services` and `sites` do not exist in Artifact 8**, and neither does an `intents` table —
docs/30 has "provisioning state" and a Redis CoA queue, which predate F2's intent model.

This plan extends Artifact 8 rather than replacing it. Money as `price_minor` integers,
voucher codes from a CSPRNG over a 32-symbol alphabet, and the unique-index-not-check rule
are all carried forward unchanged.

### 0.4 A naming collision to resolve now

docs/30 says **tenant**. docs/42 says **Tenant**. docs/45/46/48 say **Customer**. They are
the same thing, and two names for one entity in a multi-tenant system is how isolation bugs
are written.

**Proposed:** the entity is `mt_customers`. **"Tenant" is retired as an entity name** and
survives only as the word for the *isolation key* (`current_tenant` in RLS). One entity, one
name.

---

## A. Backend modules

Separate service. **PHP 8.1, zero external dependencies**, matching the house style and the
team that will maintain it — the control plane is a REST API, a job runner and an HTTP
client, all of which PHP does. FreeRADIUS and WireGuard are daemons, not application code.
*(Stack is a decision, not an assumption — say if you want otherwise before I build.)*

| Module | Responsibility |
|---|---|
| `Http/` | Routing, request/response, `Idempotency-Key` handling |
| `Auth/` | Principal authentication, session tokens, **tenant derivation** |
| `Tenancy/` | RLS session-variable binding; every query runs inside it |
| `Customers/` | Customer, principals, services, entitlements, sites |
| `Devices/` | Registry, lifecycle state machine, desired vs actual |
| `Intents/` | Queue, state machine, idempotency, retry, expiry |
| `Delivery/` | RouterOS REST client over the tunnel; the **only** module that touches a router |
| `Policy/` | Profiles (DishNet) and retail plans (customer), with validity checks |
| `Vouchers/` | Batches, codes, lifecycle, RADIUS user projection |
| `Sessions/` | radacct ingestion, live view, disconnect intents |
| `Telemetry/` | Uplink sampling — **measure only, no enforcement path exists** |
| `Audit/` | Append-only log with the migration-070 delete-protection pattern |
| `Jobs/` | Async workers: intent delivery, bulk voucher generation, session reaping |

**F2 enforced structurally:** only `Delivery/` may open a connection to a router, and it is
callable only by `Jobs/`, never by `Http/`. An HTTP request cannot reach a router in one
process. That is the intent model as a code boundary rather than a convention.

---

## B. Database entities

PostgreSQL. Separate database. RLS on every customer-scoped table.

```sql
-- ── IDENTITY ────────────────────────────────────────────────────────────
mt_customers      id, ucrm_client_id UNIQUE, name, status, created_at
mt_principals     id, customer_id, kind, display_name, phone, email,
                  credential_hash, status, last_login_at
                  -- kind: owner | operator.  C6/C16 OPEN: see §L
mt_sessions_auth  id, principal_id, token_hash UNIQUE, issued_at,
                  expires_at, revoked_at
                  -- the token is the ONLY thing the client holds (F4)

-- ── COMMERCIAL PLANE ────────────────────────────────────────────────────
mt_services       id, customer_id, kind, status, started_at, ended_at
                  -- kind: 'mikrotik_hotspot'.  Reseller capability attaches HERE
mt_entitlements   id, service_id, key, int_value, text_value
                  -- PLATFORM SCOPE ONLY: max_routers, max_sites, max_operators,
                  -- feature flags.  *** NO BANDWIDTH KEYS.  F8. ***
mt_sites          id, customer_id, service_id, name, location, created_at

-- ── DEVICE PLANE ────────────────────────────────────────────────────────
mt_devices        id, customer_id, site_id, serial UNIQUE, model, ros_version,
                  state, tunnel_ip, wg_pubkey, last_seen_at,
                  staged_at, staged_by, claimed_at
mt_device_config  device_id, desired JSONB, actual JSONB, actual_read_at
                  -- divergence is computed, never stored as a third truth

-- ── POLICY PLANE ────────────────────────────────────────────────────────
mt_profiles       id, name, rate_limit_attr, session_timeout_s,
                  shared_users, active
                  -- DishNet-owned enforcement. Customer never sees these rows
mt_plans          id, customer_id, site_id NULL, name, duration_s,
                  rate_down_bps, rate_up_bps, data_cap_bytes NULL,
                  devices_per_voucher, mode, price_minor, currency,
                  profile_id, active, created_by, created_at
                  -- CUSTOMER-owned retail product. Customer sets price. F9

-- ── TRANSACTION PLANE ───────────────────────────────────────────────────
mt_voucher_batches id, customer_id, site_id, plan_id, requested_count,
                   created_by, created_at, job_id
mt_vouchers        id, customer_id, batch_id, plan_id, site_id,
                   code UNIQUE, state, created_at, activated_at, expires_at,
                   price_minor, currency, sold_at, sold_by, revoked_at
mt_hotspot_users   voucher_id, radius_username UNIQUE, created_at
                   -- the projection of a voucher into AAA. F11
mt_sessions        id, customer_id, device_id, voucher_id, radacct_id,
                   mac, ip, started_at, ended_at, bytes_in, bytes_out

-- ── CONTROL PLANE ───────────────────────────────────────────────────────
mt_intents        id, customer_id, actor_principal_id, kind,
                  target_type, target_id, payload JSONB,
                  state, idempotency_key UNIQUE, attempts,
                  created_at, sent_at, confirmed_at, failed_at,
                  expires_at, last_error
                  -- state: queued|sent|confirmed|failed|expired  (docs/42 §0)
mt_idempotency    key PRIMARY KEY, endpoint, principal_id,
                  response_status, response_body, created_at
mt_audit_log      id, actor, action, target_type, target_id, customer_id,
                  at, source, detail JSONB
                  -- append-only; DELETE/UPDATE trigger per migration 070

-- ── TELEMETRY (C18, F13) ────────────────────────────────────────────────
mt_uplink_samples device_id, at, rx_bps, tx_bps, session_count
                  -- read-only observation. No table, column or code path
                  -- exists that turns a sample into a limit. F13
```

**Deliberately absent, and each absence is load-bearing:**

| Not present | Because |
|---|---|
| Any bandwidth entitlement key | F8 — DishNet does not ration the uplink |
| Any Domain A table, FK or view | F1 |
| `hotspot_paid_access` in any form | docs/41 §4.1 |
| A `tenant_id` argument on any endpoint | F4 — derived, never supplied |
| Any BYO/adoption table | docs/53 §4 — not built speculatively |

---

## C. API endpoints

Two surfaces on one service. **No endpoint anywhere accepts a customer id.**

```
── CUSTOMER PWA ────────────────────  principal → customer derived server-side
POST   /api/v1/auth/request-code
POST   /api/v1/auth/verify              → session token
POST   /api/v1/auth/logout

GET    /api/v1/me                       → customer, principal, role
GET    /api/v1/me/services
GET    /api/v1/me/sites
GET    /api/v1/me/sites/{site_id}       → 404 if not derived-reachable
GET    /api/v1/me/access-points         → PROJECTION ONLY (§I)
GET    /api/v1/me/plans
POST   /api/v1/me/plans                 → validity-checked, not ceiling-checked
PATCH  /api/v1/me/plans/{id}
GET    /api/v1/me/vouchers
POST   /api/v1/me/vouchers              → creates an INTENT
POST   /api/v1/me/vouchers/bulk         → job_id; async (docs/30)
POST   /api/v1/me/vouchers/{id}/revoke  → creates an INTENT
GET    /api/v1/me/sessions
POST   /api/v1/me/sessions/{id}/disconnect → creates an INTENT
GET    /api/v1/me/intents               → own only
GET    /api/v1/me/usage
GET    /api/v1/me/uplink                → measurement only (C18)
GET    /api/v1/me/entitlements          → what they bought; read-only

── ADMIN / NOC ─────────────────────  staff principal, estate-wide
GET    /api/v1/admin/customers ... /services ... /entitlements
GET    /api/v1/admin/devices            → full detail incl. serial, wg, ros
POST   /api/v1/admin/devices/{id}/provision      → INTENT
POST   /api/v1/admin/devices/{id}/reprovision    → INTENT
POST   /api/v1/admin/devices/{id}/decommission   → INTENT
GET    /api/v1/admin/profiles           ... POST/PATCH
GET    /api/v1/admin/intents            → all, with failure detail
GET    /api/v1/admin/audit

── INTERNAL ────────────────────────  not public
POST   /internal/radius/accounting      → radacct ingestion
```

**Every state-changing route requires `Idempotency-Key`** and replays the first response
(docs/30 Artifact 9).

**Every POST that would reach hardware returns `202 Accepted` with an intent id** — never
`200 OK` with a result, because the result does not exist yet. F2 in the wire protocol.

---

## D. Authorization model

Four layers, outermost first:

1. **Token → principal.** The client holds an opaque token. It carries no ids.
2. **Principal → customer.** Derived from `mt_principals.customer_id`. Never read from the
   request — not from body, query, header or path.
3. **RLS.** Each request opens a transaction, sets `SET LOCAL app.customer_id = …`, and
   every customer-scoped table has a policy on it. **A missing `WHERE` returns zero rows
   rather than another customer's** (docs/30 Artifact 7, principle 2).
4. **Reachability, not ownership.** A site id in a path filters the already-derived set —
   never a lookup key (docs/45 §3.2a). A foreign id matches nothing.

**404, never 403**, for anything not reachable. 403 confirms existence.

**First test written, before any feature** (docs/30 Artifact 13): two customers, one router
each, every read endpoint called with A's token and B's ids, asserting 404 throughout. That
suite is the gate for the whole phase.

---

## E. Intent model

`Queued → Sent → Confirmed → Failed → Expired` (docs/42 §0, frozen).

| | |
|---|---|
| **Created by** | Any customer or admin action that changes a router |
| **Carries** | customer, actor principal, kind, target, payload, idempotency key |
| **Delivered by** | `Jobs/` → `Delivery/`. Never by an HTTP request |
| **Confirmed by** | Reading back actual state, not by a 200 from the router |
| **Expired by** | A deadline, so a queue never delivers something stale |
| **Retried** | Bounded attempts, exponential backoff, recorded per attempt |

**Language rule carried into the backend:** no API field, enum, message or log line asserts
*when* delivery happens. B1 is unresolved (docs/49). The existing two-sided guard extends to
server strings, so neither push nor poll is implied anywhere.

---

## F. Router lifecycle

```
REGISTERED → STAGED → SHIPPED → CONNECTED → PROVISIONED → ACTIVE
                                     ↓            ↓
                                 ORPHANED     DIVERGED
                                     ↓            ↓
                              DECOMMISSIONED  (reconcile intent)
```

`desired` vs `actual` are both stored; **divergence is computed, never stored** — a third
copy of the truth is a third thing to be wrong. `ORPHANED` (tunnel never appeared after a
bundle push) keeps its own queue, per docs/30.

**Provisionable without hardware; not provable without it.** docs/30 Phase 0 gates proof,
not construction. Built against a RouterOS CHR harness (docs/30 Artifact 13 rule 2 — *a real
CHR container, never a mock*), and marked unproven until real units exist.

---

## G. Voucher / RADIUS flow

```
Customer picks plan + site + count
   → mt_voucher_batches row
   → codes generated: CSPRNG, 32-symbol alphabet, 0/O/1/I removed,
     uniqueness by unique index, never by check-then-insert
   → mt_vouchers rows, state = unused
   → INTENT per batch
   → Delivery writes RADIUS users: username = t{customer_id}-{code}
     (namespaced per docs/30 §370, so one customer's code cannot
      authenticate on another's NAS)
   → mt_hotspot_users links voucher ↔ radius_username
   → confirmed by reading back
```

Guest redeems → HotSpot → RADIUS `Access-Accept` → plan's profile attributes applied →
session starts.

**Concurrent redemption of one code must fail for the second.** Enforced at the database, not
in application logic, and tested explicitly (docs/30 Artifact 13 rule 4).

**Expire, never delete** (docs/43 §C) — a voucher is a revenue record.

---

## H. Session / accounting flow

```
radacct (FreeRADIUS, PostgreSQL) ──► /internal/radius/accounting
                                        → mt_sessions upsert by radacct_id
Start → Interim (octets grow) → Stop
```

Matches what docs/36 already proved. **Stale-session reaping** — sessions with no interim
update past a threshold are closed, because an `Accounting-Stop` lost with the tunnel leaves
the row open (docs/49 §2.2). Needed for honest "online now" counts whether or not any
concurrency limit is ever offered.

---

## I. Customer PWA

Routes mirror the prototype, which stands as the design and needs no rework:

```
/login  /  /services  /wifi  /sites/{id}  /vouchers  /vouchers/new
/devices  /usage  /account  /billing  /support
```

**The projection is the contract.** The API returns *only* these router fields:

| Returned | Withheld |
|---|---|
| friendly name, site, online/offline, session count, last seen | serial, WireGuard IP, endpoint, RouterOS version, provisioning state, desired/actual config, admin credentials, other customers |

Enforced server-side in the serializer, not by the client choosing what to render. The
prototype's leak suite (10 values asserted absent) ports to the API layer as a response
test.

**Three experiences stay separate (F5).** The PWA is not an operator console; the captive
portal is RouterOS HotSpot serving a template (docs/30 Artifact 3b: *"DishNet builds no
captive portal"*), not an application.

---

## J. Admin / customer boundary

| | Admin | Customer |
|---|---|---|
| Surface | `/api/v1/admin/*` | `/api/v1/me/*` |
| Scope | Estate | Derived, single customer |
| Router detail | Full | Projection (§I) |
| Profiles (enforcement) | CRUD | Invisible |
| Retail plans | Read | **CRUD — theirs** |
| Provisioning | Yes | Never |
| Entitlements | Sets | Reads |
| Intents | All | Own |

One permission system, two renderings (C10=A). Same RLS, different serializers.

---

## K. Domain A / B boundary

| | |
|---|---|
| Separate **database**, separate **service**, separate **host** | F1 |
| No FK, view, join or import crosses | F1 |
| Plugin's SQLite untouched; **zero migrations added to it** | docs/30 Artifact 3 |
| `hotspot_paid_access` not read or written | docs/41 §4.1 |
| Plugin becomes a read-only API client — **Phase 4 only** | docs/30 |

A boundary test asserts the Domain B service opens no connection to the plugin's SQLite and
that no `mt_*` identifier appears in Domain A code.

---

## L. Deliberately deferred

### L.1 Open decisions, and the clean boundary each gets

| Open | Extension point — **not** an implementation |
|---|---|
| **C20** hardware | `mt_devices.staged_at/staged_by` already model *who staged it*. A/C/C′ differ only in **who** and **where** — no schema change between them. **BYO builds nothing** (docs/53 §4) |
| **C11** pricing | `mt_entitlements` is a key/value table. The pricing *unit* becomes keys; no schema change when it is chosen |
| **C6/C16** roles | `mt_principals.kind` is `owner|operator` and where staff records live is settled by C16. Permission checks route through one `can()` — one place to change |
| **C17** wording | Copy, not code |
| **C18** presentation | Sampling built (F13); display vs alert is a UI and threshold decision |
| **C19** trial | An entitlement key once C11 names the unit |
| **C9/C13** portal | **Nothing built.** RouterOS serves the portal; shop-vs-login changes what that template is |
| **C14** merchant of record | **No payment code.** Legal input first (docs/51) |
| **C8′** tunnel-down | Reaping built (§H); whether concurrency is *offered as a customer tool* waits on Phase 0 data |

### L.2 Blocked, and why

| | |
|---|---|
| **docs/30 Q5 — where does this run?** | **Not answered.** Control plane, PostgreSQL, Redis, FreeRADIUS and the concentrator do not fit in the uCRM container, and the concentrator needs a stable public endpoint. Buildable deployment-agnostic; **not deployable until answered.** The first infrastructure decision |
| **Provisioning proof** | Needs Phase 0 hardware. Code builds against CHR; **marked unproven** until real units |
| **Billing integration** | docs/30 Phase 4. Needs C11 |

### L.3 What "final" means here

Per your adjustment: **the final architecture and the implementation of the frozen core** —
not a production-complete system. Nothing here turns an open decision into an assumption,
and §L.2 says plainly what cannot be finished yet.

---

## Build order

Following docs/30's sequencing, which puts the riskiest unknown first:

| | | Exit condition |
|---|---|---|
| **1** | Schema, RLS, tenancy, audit, **isolation suite first** | Two customers provably cannot see each other |
| **2** | Auth, principals, `/me/*` reads, projection tests | The PWA renders from real data; no field leaks |
| **3** | Intents: queue, state machine, idempotency, retry | An intent survives a crash mid-delivery |
| **4** | Policy: profiles, retail plans, validity checks | A customer creates a plan; validity ≠ ceiling |
| **5** | Vouchers, batches, RADIUS projection | Concurrent redemption of one code fails for the second |
| **6** | Sessions, accounting ingestion, reaping | radacct Start/Interim/Stop reaches `mt_sessions` |
| **7** | Devices, lifecycle, CHR harness | Provisions against CHR; **unproven on metal** |
| **8** | Telemetry, uplink sampling | Measured, displayed, **no enforcement path exists** |

Steps 1–6 need no hardware and no open decision. Step 7 needs Phase 0 to be *proven*, not to
be *written*. Step 8 needs C18 only for presentation.

---

## What I need from you before step 1

1. **Stack** — PHP 8.1 zero-dependency, or something else? (§A)
2. **`mt_` prefix kept despite being redundant in a separate database?** (§0.2)
3. **"Tenant" retired as an entity name, "Customer" throughout?** (§0.4)
4. **Where does this run?** — docs/30 Q5. Blocks deployment, not development (§L.2)
5. **Repository** — same repo under a new top-level directory, or a separate repository?
   docs/30 says separate service; it does not say separate repo.

*No production system was contacted. No code, schema or prototype was changed.*
