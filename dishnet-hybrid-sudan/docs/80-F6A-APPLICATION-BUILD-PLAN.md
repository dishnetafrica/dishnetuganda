# 80 — F6-A application build: plan, gap analysis, and two blocking decisions

**Status: PLAN. No production system was modified. No code written yet under this
plan.** F6-B remains gated in full.

This is deliverables **1, 2, 3, 10 and 11** of the F6-A brief. It is grounded in
a survey of what is actually in the repository, not in what the documents say
should be there.

---

## 1. What already exists — measured, not assumed

The backend is **substantially built**. `src/` holds 29 classes across 11
namespaces, and the suite is **718 assertions, green**.

| Area | State |
|---|---|
| Auth (OTP, sessions, rate limiting) | **built** — `mt_auth_*`, `Authenticator`, `RateLimited` |
| Tenancy + RLS | **built** — `TenantContext`, `FORCE ROW LEVEL SECURITY` on every tenant table |
| Idempotency | **built** — `Http/Idempotency`, partial unique index |
| Intent queue + worker | **built** — `IntentQueue`, `IntentState`, `Jobs/IntentWorker` |
| Delivery port | **built** — `DeliveryPort`, `NullDelivery`, `RouterOsDelivery`, `RouterOs/RestClient` |
| Devices | **built** — `DeviceRegistry`, sealed credentials (`Crypto/SecretBox`) |
| Policy | **built** — plans, validator, `ProfileResolver`, RouterOS limit book |
| Sessions / accounting | **built** — `SessionService`, `AccountingIngest` (retransmit, reorder, 32-bit wrap) |
| Telemetry | **built** — `UplinkSampler`, `UplinkRepository` |
| Vouchers | **built** — `VoucherService`, `CodeGenerator` |
| Audit | **built** — `Audit/AuditLog`, append-only |
| **Customer API** | **built** — 20 routes under `/api/v1/me/*` + auth + the internal accounting hook |
| **Customer PWA** | **prototype only** — 13 screens, renders from **mock objects**, calls no endpoint |
| **Admin Web** | **prototype only** — and **`/api/v1/admin/*` has ZERO routes** |
| **Captive portal** | **not built** |
| **RADIUS publisher** | **not built** — `voucher.publish` resolves to `assertRadiusBacked()` and writes nothing |

**One instruction in the brief is already satisfied.** *"No hardcoded `ether1`"* —
migration 016 replaced the guess with an established WAN **fact**
(`mt_device_set_wan`); `UplinkSampler::wan()` now follows the recorded fact and
**samples nothing** when none is set, rather than falling back to a name or to
the busiest interface. `tests/test_wan_and_limits.php` asserts a decoy `ether1`
carrying 500/400 Mbps is *not* recorded. Nothing to change.

---

## 2. Two blocking decisions — surfaced, not taken

The brief says: *do not make new architectural decisions silently; if an
unresolved decision blocks a feature, isolate it behind a boundary and continue.*
Two qualify, and **both block Admin Web specifically**.

### D-A. There is no staff identity model at all

`mt_principals.kind` is `CHECK (kind IN ('owner','operator'))` and every
principal is **customer-scoped** (`mt_principals.customer_id`). `mt_audit_log`
and `mt_intents` both anticipate `actor_kind = 'staff'` — **nothing creates
one.** Migration 013 says as much: *"behind a staff principal; today nothing
customer-facing can reach them."*

So DishNet staff have **no identity, no authentication and no session**. Admin
Web cannot be authenticated without inventing one, and inventing one silently is
exactly what the brief forbids. It needs: a staff table, staff authentication
(is it OTP like customers? SSO? password?), staff sessions, and role assignment.

**Isolation so the rest proceeds:** every admin route will sit behind an
`AdminIdentity` port that answers *"who is this staff member and what may they
do?"* The port is real; its **implementation is the decision**. Until it is
taken, the only binding supplied is a deny-all default plus a test double, so
the admin API is fully testable and **cannot be reached in any deployment**.

### D-B. The "Reseller" role collides with a closed position

docs/42 §4's matrix has three roles — Super Admin, Operations/Technician,
**Reseller** — and the Reseller rows describe a tenant-like scope (*"Own
business only"*, *"Assigned only"*, *"Own batches only"*).

`CLAUDE.md` records the opposite as settled: **the ISP/operator hierarchy is a
business-model input, not a tenant layer. Do not build one from it.**

These cannot both be implemented as written. **Not resolved here.** The build
implements **Super Admin** and **Operations/Technician** only; the `AdminRole`
enum leaves `reseller` unimplemented with a pointer to this section, so the gap
is visible in code rather than quietly filled in.

---

## 3. Architecture changes required from docs/55 / docs/56

| | Change | Why |
|---|---|---|
| **A1** | **Staff identity** — new tables and auth path | D-A. docs/55 §D covers *customer* identity only; §J asserts *"one permission system, two renderings"* without saying what the admin half authenticates against |
| **A2** | **`/api/v1/admin/*`** — an entire surface | docs/56 §10: zero routes. The largest single gap |
| **A3** | **An admin serializer** distinct from `Http/Serializer/Projection` | docs/55 §J: *"Same RLS, different serializers."* The customer projection deliberately hides profiles, sealed credentials, `radius_ref` |
| **A4** | **Estate-scope reads** for staff | Every current query is tenant-filtered by `app.customer_id`. Staff read across customers, so admin reads need a distinct, audited path — **not** by disabling RLS |
| **A5** | **Alert engine** | docs/42 §19 build-order fact 1: the Dashboard's *Needs Attention* band is why the Dashboard exists, so the alert engine is MVP even though the Alerts screen is deferred |
| **A6** | **Captive portal** as a third experience | Admin / Customer PWA / Guest portal stay separate. Not built at all today |
| **A7** | **`RadiusPublisher` port** | Decision 7's publisher has no code. The **port and simulator** are F6-A; the real binding is F6-B |

---

## 4. The adapter boundary

```
   Application  ──►  DeliveryPort        ──►  NullDelivery        (default)
                                         ──►  SimulatedRouterOs   (deterministic, F6-A)
                                         ──►  RouterOsDelivery    (real, F6-B activation)

                ──►  RadiusPublisherPort ──►  NullPublisher       (default)
                                         ──►  SimulatedRadius     (deterministic, F6-A)
                                         ──►  FreeRadiusPublisher (real, F6-B activation)

                ──►  AdminIdentity       ──►  DenyAll             (default — D-A unresolved)
```

**Rule: the simulator never masquerades as a router.** Every simulated response
is tagged at the port boundary, `GET /api/v1/admin/health` reports which binding
is active, and a guard test asserts the real bindings are **not** selectable
without an explicit configuration flag that no default sets.

`DeliveryPort` already exists and `NullDelivery` already implements it — the
pattern is established, not invented.

---

## 5. Build order

Sequenced so nothing waits on a decision it does not need.

| Phase | Work | Blocked by |
|---|---|---|
| **P1** | **Wire the Customer PWA to its existing API.** Every screen has an endpoint; none calls one (docs/56 §9). Pure front-end integration | **nothing** |
| **P2** | **Simulator + `RadiusPublisher` port** — deterministic router and RADIUS doubles, replacing mock objects in tests with a real port | **nothing** |
| **P3** | **Admin API** behind `AdminIdentity` — routes, estate reads, admin serializer, audit on every mutation | shipped deny-all until **D-A** |
| **P4** | **Admin Web** — docs/42 §19's approved order: Dashboard, Routers, Router detail, Plans, Vouchers, Batches, Reports; Sessions and Intents as shells | P3, **D-A** |
| **P5** | **Alert engine** (voucher exhaustion needs no router) | P3 |
| **P6** | **Captive portal** | Decision 4, docs/67 §7 |
| **P7** | Integrity constraints, RADIUS publisher binding, real RouterOS adapter | **F6-B — gated** |

**P1 and P2 start immediately.** P3 can be built and tested in full behind
deny-all; only its *binding* waits on D-A.

---

## 6. Database migration plan — development/test only

Migrations 019+ land in `migrations/` and run against `dnb_test` and disposable
databases via `bin/migrate.php`. **They are not applied to any production
database, and the production census (docs/79) is unaffected and still
outstanding.**

| | Migration | Phase | Note |
|---|---|---|---|
| 019 | staff identity + roles | P3 | **only after D-A**; not written before |
| 020 | alert rules and alert state | P5 | |
| 021 | captive-portal configuration | P6 | |
| — | **customer/site integrity constraints** | **F6-B** | docs/76 §B.2 — **not in F6-A.** They are a *production-data* change and their backfill depends on the census |

**`mt_vouchers.site_id NOT NULL` is NOT in this plan.** It is decided (docs/77
§1) and its migration is gated on the census. `mt_voucher_batches.site_id`
remains an **open schema decision** and appears nowhere.

---

## 7. Hardware-dependent assumptions — the register

Every assumption carries one marker: `DOCUMENTED` · `VERSION/MODEL DEPENDENT` ·
`HARDWARE VERIFIED` · `UNRESOLVED`. **Nothing is `HARDWARE VERIFIED` today** —
no physical MikroTik has answered anything.

The seven already embedded in code (docs/56 §5.3), restated with markers:

| | Assumption | Where | Marker |
|---|---|---|---|
| H1 | RouterOS serves REST on a self-signed cert with no further configuration | `RestClient` | **VERSION/MODEL DEPENDENT** |
| H2 | `Mikrotik-Rate-Limit` accepts up to 2³²−1 bps | `PlanValidator::MAX_RATE_BPS` | **DOCUMENTED** |
| H3 | HotSpot `shared-users` accepts up to 65535 | `PlanValidator::MAX_DEVICES` | **DOCUMENTED** |
| H4 | ~~`ether1` is the WAN~~ | — | **RESOLVED** — replaced by a recorded fact (§1) |
| H5 | `ip/hotspot/profile` carries `use-radius` | `RouterOsDelivery` | **VERSION/MODEL DEPENDENT** |
| H6 | `ip/hotspot/active/remove` takes `.id` | `RouterOsDelivery::disconnect` | **UNRESOLVED** — fails silently if wrong |
| H7 | `interface` returns `rx-bits-per-second` | `UplinkSampler` | **VERSION/MODEL DEPENDENT** |

Added by F6-A's scope, all **UNRESOLVED** until hardware answers: WireGuard
peer syntax on the target RouterOS version; HotSpot server/profile creation
order; `Mikrotik-Group` semantics; whether a queued disconnect takes effect
without a HotSpot reload; reset/run-after-reset behaviour; MTU on the tunnel;
push vs poll (**B1**).

---

## 8. What still requires physical MikroTik verification

Everything in §7 not marked RESOLVED, plus docs/56 §6: the bootstrap flow can
only be proven **on metal** — CHR has no radio, no RouterBOARD serial and no
factory-reset behaviour. **The simulator narrows nothing here; it only lets the
application be built and tested meanwhile.**

---

## 9. Production safety

**No production system was modified by this plan, and none will be by F6-A.**
No production PostgreSQL migration, schema change, backfill, RLS modification,
FreeRADIUS change, C-b deployment, AAA publisher deployment, router provisioning
or customer assignment. The production census remains **outstanding and NOT
ESTABLISHED**, and it does not block F6-A.

---

## 10. What I need before P3's binding and P4

1. **D-A — staff identity**: what do DishNet staff authenticate with?
2. **D-B — the Reseller role**: implement it, or drop it from the matrix?

Both block only the admin **binding** and Admin Web. **P1, P2 and the admin API
behind deny-all proceed now.**
