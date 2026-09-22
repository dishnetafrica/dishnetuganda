# 84 — Admin write boundary: inventory, findings, and the proposed design

**Status: DESIGN AND EVIDENCE ONLY. No code changed in this increment.** No
write implemented, no migration, no production change. `DenyAllIdentity` remains
the only binding and the Admin UI remains read-only.

---

# 1. ADMIN WRITE INVENTORY

Columns: **Cap** = capability · **Mech** = current write mechanism ·
**Audit?** = does the current path write `mt_audit_log` · **Intent?** ·
**MT** = touches MikroTik · **RAD** = touches FreeRADIUS · **Retry** = safely
retryable · **HW** = needs physical MikroTik verification.

## 1.1 Router operations

| Operation | Cap | Tables | Mech | Audit? | Intent? | MT | RAD | Retry | HW |
|---|---|---|---|---|---|---|---|---|---|
| **assign customer/site** | `routers.assign` | `mt_devices`, `mt_device_secrets`, `mt_device_config` | `mt_device_assign()` definer | **NO** | no | no | no | yes (idempotent UPDATE) | no |
| **stage** | `routers.register` | `mt_devices` | `mt_device_register()` sets `staged` when `staged_by` given | **NO** | no | no | no | **no** — `serial` is UNIQUE, a retry is a duplicate-key error | no |
| **provision** | `routers.act` | `mt_intents` → device | `IntentQueue` + `RouterOsDelivery` | queue: **NO**; worker: **yes** | **yes** `device.provision` | **yes** | indirectly | yes — intent has `idempotency_key` | **YES** |
| **reprovision** | `routers.act` | — | **DOES NOT EXIST** | — | — | yes | — | — | **YES** |
| **activate** | `routers.act` | `mt_devices.state` | `mt_device_set_state()` + `mt_device_transition` trigger | **NO** | no | no | no | yes | no |
| **suspend** | `routers.act` | — | **NO SUCH STATE** — the machine has no `suspended` | — | — | — | — | — | — |
| **reboot** | `routers.act` | — | **DOES NOT EXIST** — no delivery case | — | — | yes | no | — | **YES** |
| **reset / recovery** | `routers.act` | — | **DOES NOT EXIST** | — | — | yes | no | — | **YES — metal only** |
| **decommission** | `routers.act` | `mt_devices.state` | `mt_device_set_state('decommissioned')`; trigger makes it terminal | **NO** | no | no | no | yes | no |
| **rename** | `routers.assign` | `mt_devices.name` | only via `mt_device_assign()` — no rename of its own | **NO** | no | no | no | yes | no |
| **change configuration** | `routers.act` | `mt_device_config.desired` | `mt_device_set_desired()` | **NO** | no *(desired only)* | via a later provision | no | yes | **YES** |

## 1.2 Commercial operations

| Operation | Cap | Tables | Mech | Audit? | Intent? | MT | RAD | Retry | HW |
|---|---|---|---|---|---|---|---|---|---|
| **create plan** | `plans.write` | `mt_plans`, `mt_profiles` | `PlanRepository::create()` — **direct INSERT** under RLS | **yes** (customer route) | no | no | no | no — name is unique per customer | no |
| **edit plan** | `plans.write` | `mt_plans` | `PlanRepository` direct UPDATE | **yes** | no | no | no | yes | no |
| **retire plan** | `plans.write` | `mt_plans.active` | direct UPDATE | **yes** | no | no | no | yes | no |
| **create voucher batch** | `vouchers.generate` | `mt_voucher_batches`, `mt_vouchers`, `mt_hotspot_users`, `mt_intents` | `VoucherService::issueBatch()` — direct INSERTs | **yes** | **yes** `voucher.publish` | no | **yes, eventually** | **yes** — `Idempotency-Key` honoured | no |
| **issue vouchers** | `vouchers.generate` | as above | same call — not separable today | **yes** | yes | no | yes | yes | no |
| **revoke voucher** | `vouchers.revoke` | `mt_vouchers.state`, `mt_intents` | `VoucherService::revoke()` + `voucher.revoke` intent | **yes** | **yes** | yes | **yes** | yes | **YES** |
| **expire voucher** | — | `mt_vouchers.state` | **NO ADMIN PATH** — expiry is time-derived | — | — | — | — | — | — |
| **disconnect session** | `sessions.disconnect` | `mt_intents` | `session.disconnect` intent | **yes** | **yes** | **yes** | no | yes | **YES** |

## 1.3 Customer and site operations

| Operation | Cap | Tables | Mech | Audit? | Intent? | Retry | Notes |
|---|---|---|---|---|---|---|---|
| **create customer** | `customers.write` | `mt_customers` | `mt_customer_create()` definer, INSERT-only policy | **NO** | no | no — `ucrm_client_id` unique | returns the id alone and **cannot read back**, deliberately (017 §4) |
| **edit customer** | `customers.write` | `mt_customers` | **DOES NOT EXIST** | — | — | — | |
| **create site** | `sites.write` | `mt_sites` | **NO ADMIN PATH** — only the customer route, inside its own tenant context | — | — | — | |
| **edit site** | `sites.write` | `mt_sites` | **DOES NOT EXIST** | — | — | — | |
| **assign router to site** | `routers.assign` | `mt_devices` | `mt_device_assign()` | **NO** | no | yes | §2 F-2 |

---

# 2. SECURITY FINDINGS

## F-1 — **No SQL function anywhere writes an audit row.** *(generalises the known finding)*

Searched every migration: `mt_audit_log` appears only in its own DDL, its
append-only triggers, the RLS list, and migration 019's read projection.
**Every audit row in the system is written by `src/Api/Routes.php` — the
customer surface — or by `IntentWorker`.**

So the deficiency is not confined to `mt_device_assign`. **All seven
provisioning/admin definer functions are unaudited**: `mt_device_register`,
`mt_device_assign`, `mt_device_set_state`, `mt_device_set_secret`,
`mt_device_set_desired`, `mt_device_set_wan`, `mt_customer_create`.

Binding any of them to an HTTP route today would produce a state change with
**no actor, no timestamp and no record** — and `mt_audit_log.actor_kind` has
allowed `'staff'` since migration 004 while nothing has ever produced one.

## F-2 — `mt_device_assign` does not enforce `device.customer_id = site.customer_id`

**Measured, not read** (docs/72 Part A): called as `dnb_admin` with customer A
and customer B's site, it **accepted**, leaving the invariant violated. No
CHECK, foreign key, trigger or policy prevents it — the two foreign keys
validate each column independently, so existence, not ownership.

**And RLS is not the guard.** `dnb_def_prov` holds `USING (true) WITH CHECK
(true)` on `mt_devices`, deliberately, because provisioning must see unassigned
stock. Permissive policies are OR-combined, so `true` wins.

**Nor is it admin-only.** `dnb_app` — the customer request role — holds `UPDATE`
on `mt_devices` from migration 006's blanket grant, and the tenant policy
constrains `customer_id` while saying nothing about `site_id`. Measured
(docs/73 §1.1): a customer re-pointed its own device at another customer's site,
`rows=1`, with no privileged function involved.

**The remediation is designed and approved in principle** (docs/73 §4 — composite
FK + CHECK) **and is not implemented.** Until it is, this operation must not be
reachable from Admin.

## F-3 — Direct table privileges bypass the intended function path entirely

`dnb_admin` holds `SELECT, INSERT, UPDATE, DELETE` on **all tables** (migration
015 line 60, with `ALTER DEFAULT PRIVILEGES` so new tables inherit it). An Admin
**write** route connecting as `dnb_admin` could therefore write any table
directly and never call a function at all — the function boundary would be a
convention, not a control.

The read boundary already solved the equivalent problem by giving the API its
own `dnb_adminapi` identity with zero table privileges. **Writes need the same
treatment**, and §6 proposes it.

## F-4 — Delivery implements three of the eleven hardware operations

`RouterOsDelivery` handles exactly `device.provision`, `session.disconnect` and
`voucher.revoke`. **Reboot, reset/recovery, reprovision and configuration push
have no delivery case at all.** A button bound to any of them would queue an
intent that nothing can deliver, and the intent would expire — a UI that appears
to act and does not.

## F-5 — Several operations in the brief do not exist in any form

**Suspend** has no state (`mt_devices.state` allows registered, staged, shipped,
connected, provisioned, active, orphaned, diverged, decommissioned). **Rename**
exists only as a side effect of `mt_device_assign`. **Edit customer**, **edit
site** and an **admin create-site** path do not exist. These are product gaps,
not security ones, but a write-boundary design that silently invented them would
be designing a different product.

## F-6 — What *is* sound, and should be preserved

- `mt_device_transition` enforces the state machine in the database, and makes
  `decommissioned` terminal.
- The intent queue carries `idempotency_key` with a partial unique index, and
  `IntentWorker` **does** write audit rows on both outcomes.
- `VoucherService::issueBatch()` honours an `Idempotency-Key`.
- `mt_customer_create` deliberately returns only an id and cannot read back, so
  creating a customer does not confer the ability to read the fleet.

---

# 3. AUDIT REQUIREMENTS

**Every state-changing Admin operation must produce exactly one audit row, in
the same transaction as the change.**

| Requirement | Detail |
|---|---|
| **Actor** | `actor_kind = 'staff'`, `actor = StaffIdentity::$subject` — the abstract `AdminIdentityPort` boundary, since the provider is unresolved. **No fake production staff identity is created.** |
| **Atomicity** | The row is written **inside the function**, not by the caller. A caller-written row can be skipped by a different caller; F-1 exists precisely because audit lives in one route file |
| **Coverage** | All seven definer functions in F-1, plus every new admin write |
| **Content** | action, target type/id, source, and `detail` limited to non-free-form fields — D-2 already withholds raw `detail` from the read surface, so writing secrets into it would create data no screen can ever show |
| **Failure** | A failed write must still be attributable. Either audit-then-act in one transaction, or record the attempt with its outcome |

**This is a change to the seven existing functions**, which is a control-plane
migration and its own authorization.

---

# 4. INTENT REQUIREMENTS

The rule: **anything that must reach a router is an intent; nothing else is.**

| Produces an intent | Does not |
|---|---|
| provision, reprovision, reboot, reset, configuration push, disconnect session, revoke voucher *(AAA side)* | assign, stage, activate, decommission, rename, create/edit plan, create customer/site |

- The Admin API **must never call `DeliveryPort`**. `test_frozen_guards.php`
  already asserts nothing in `Api/` or `Http/` can reference it, and the AAA
  publisher was added to that same containment.
- Every intent-producing route needs an idempotency key, following
  `issueBatch()`.
- The UI renders **202/queued** as queued — never as success, and never with
  wording implying when a router will be reached (B1 unresolved).

---

# 5. HARDWARE-DEPENDENT OPERATIONS

**Nothing below is `HARDWARE VERIFIED`. No physical MikroTik has answered
anything.**

| Operation | Depends on | Marker |
|---|---|---|
| provision | HotSpot + RADIUS client creation order; `use-radius` on the profile (H5) | **VERSION/MODEL DEPENDENT** |
| disconnect session | `ip/hotspot/active/remove` taking `.id` (H6) — **fails silently if wrong** | **UNRESOLVED** |
| revoke voucher | the AAA side is C-b, which is F6-B and undeployed | **UNRESOLVED** |
| reboot | no delivery case; RouterOS command unverified | **UNRESOLVED** |
| reset / recovery | factory-reset and run-after-reset behaviour | **UNRESOLVED — metal only.** CHR has no RouterBOARD serial and no factory reset (docs/56 §6) |
| configuration push | full config render never executed against a device | **UNRESOLVED** |

---

# 6. PROPOSED WRITE BOUNDARY

```
  Admin UI  →  Admin API  →  AdminIdentity (capability)  →  authorized function  →  database
                                                          ↘  IntentQueue  →  Delivery  →  MikroTik
```

1. **A dedicated write identity.** `dnb_adminwrite`, LOGIN, **no table
   privileges**, holding `EXECUTE` on the admin write functions only — mirroring
   `dnb_adminapi`. Without it, F-3 makes the function boundary a convention.
2. **Audit inside the function**, never in the route (§3).
3. **Capability per operation**, declared as the read routes already do; no
   default allow.
4. **The Admin API never touches `DeliveryPort`** — hardware work is an intent.
5. **Idempotency key required** on every intent-producing route.
6. **No write is bound before its preconditions clear** (§7).

## 6.1 What may be bound first, once its preconditions clear

In increasing risk: **decommission / activate** (state machine already enforced
in the database) → **create plan / edit plan** (already audited, no hardware) →
**create voucher batch** (audited, idempotent, no hardware) → **disconnect
session / revoke voucher** (intent + hardware, so behind H6 and F6-B) →
**assign** (blocked on F-2) → **reboot / reset / reprovision** (no delivery case
exists).

---

# 7. OPEN DECISIONS

| | Decision | Blocks |
|---|---|---|
| **W-1** | **Fix F-1** — put audit inside the seven definer functions. A control-plane migration | every Admin write |
| **W-2** | **Fix F-2** — the composite FK + CHECK from docs/73 §4, with the backfill from docs/74 §7. Still gated on the production census | `assign`, and therefore most of provisioning |
| **W-3** | **`dnb_adminwrite`** — accept the dedicated write identity, or accept that writes run as `dnb_admin` with full table privileges | every Admin write |
| **W-4** | **Staff identity provider** — unresolved, so no audit row can name a real actor yet | binding any write in a deployment |
| **W-5** | **F-5's missing operations** — build suspend/rename/edit-customer/edit-site/create-site, or remove them from the UI plan | UI completeness |
| **W-6** | **F-4's missing delivery cases** — reboot, reset, reprovision, config push | those buttons |

**Unchanged:** production untouched · production RADIUS untouched · C-b not
deployed · AAA Publisher not deployed · Q2 **OPEN** · `dnb_site_nas` **NOT
CHOSEN** · staff identity provider **NOT CHOSEN** · F6-B **NOT AUTHORIZED** ·
production census **NOT OBTAINED** · suite **1118 assertions**, unchanged by this
increment.
