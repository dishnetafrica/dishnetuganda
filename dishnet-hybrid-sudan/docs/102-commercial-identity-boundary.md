# 102 — The commercial identity boundary

**Documentation only. No schema change, no migration, no sync engine, no
production change.**

Which Domain-B operations may run on network identity alone, and which require a
uCRM link. **Measured against the code and the database, not assumed** — the
instruction was explicit that the examples offered were not to be taken as
correct, and two of them turned out not to be.

---

## 1. Two axes, not one

The classification asked for mixes two independent questions. They are kept
apart here because an operation can be both *staff-only* and *link-free*.

| Axis | Values |
|---|---|
| **Actor plane** — who may invoke it | customer (`dnb_app`) · staff (`dnb_adminwrite`) · worker (`dnb_worker`) · RADIUS (`dnb_radius`) · guest (`dnb_portal`, unbuilt) |
| **Linkage** — what commercial identity it needs | **A** Domain-B only · **B** + uCRM customer link · **C** + uCRM customer **and** service link · **FORBIDDEN** (must not consult uCRM) |

"**D — staff-only regardless of linkage**" is an *actor* answer. Every D
operation still has a linkage answer, and for most of them it is **A**.

---

## 2. How the boundary was measured — and why the obvious method is wrong

### 2.1 Privilege grants overstate what is possible

`dnb_app` holds `INSERT/UPDATE/DELETE` on **20 tables**, including
`mt_customers`, `mt_devices`, `mt_principals` and `mt_audit_log`. Classifying by
grant would conclude the customer plane can do almost anything.

**It cannot, and the reason is two other mechanisms.** Measured by execution:

```
as dnb_app, with a tenant context:
  INSERT INTO mt_customers (name) VALUES ('BYPASS-TEST');
  ERROR: new row violates row-level security policy for table "mt_customers"

  DELETE FROM mt_audit_log WHERE customer_id = <own>;
  ERROR: mt_audit_log is append-only: DELETE is not permitted
```

The first is blocked by **RLS `WITH CHECK`**, the second by the
**`mt_audit_append_only()` trigger**. Neither appears in the grant table.

> **The effective boundary is RLS + triggers + which functions exist — not the
> privilege list.** Everything below is classified against that.

### 2.2 Several operations in the brief do not exist

An operation that has never been built cannot be given a linkage requirement as
though it were live. Each is marked **NOT BUILT**, with what it *would* require.

---

## 3. The measured operation inventory

Every `mt_*` function and its executing roles were enumerated from
`pg_proc`/`has_function_privilege`; every writer of the commercial tables was
found by searching `src/`.

| Table | Written by | Reachable today |
|---|---|---|
| `mt_customers` | `mt_customer_create()` | function yes; **no route bound** |
| `mt_devices` | the seven provisioning functions | functions yes; **no route bound** |
| `mt_services` | **`Plugin/Simulator.php` only** | **NOT BUILT** |
| `mt_sites` | **`Plugin/Simulator.php` only** | **NOT BUILT** |
| `mt_plans` | `Policy/PlanRepository.php` | **yes** — `POST /me/plans` |
| `mt_voucher_batches`, `mt_vouchers` | `Vouchers/VoucherService.php` | **yes** — `POST /me/vouchers` |
| `mt_sessions` | `mt_session_account()` | yes — `dnb_radius` |
| `mt_audit_log` | `mt_audit_write()` | yes, inside the seven |

> **Finding B-1 — services and sites have no production writer.** Both are
> created only by the simulator. There is no definer function, no repository
> class and no route. So "service creation" and "site creation" are not
> operations that can be gated today; they are operations that must be *built*,
> and the gate designed into them from the start.

---

## 4. The classification

**Legend** — Linkage: **A** Domain-B only · **B** + customer link ·
**C** + customer and service link · **FORBID** must not consult uCRM.

| # | Operation | Implementation | Exists? | Actor | Linkage |
|---|---|---|---|---|---|
| 1 | **Router staging** (register) | `mt_device_register(serial, model, ros, wg_pubkey, tunnel_ip, staged_by)` | fn yes, route no | **staff (D)** | **A** — no customer exists or is referenced |
| 2 | **Router shipping** | `mt_device_set_state(device,'shipped',actor)` | fn yes, route no | **staff (D)** | **A** |
| 3 | **Router assignment** | `mt_device_assign(device, customer, site, name, actor)` | fn yes, route no | **staff (D)** | **B** — inventory becomes customer-facing here |
| 4 | **Site creation** | simulator only | **NOT BUILT** | would be staff | **B** — a site hangs off a service under a customer |
| 5 | **Service creation** | simulator only | **NOT BUILT** | would be staff | **C** — this is where the uCRM *service* link binds |
| 6 | **Plan creation / edit / retire** | `PlanRepository` ← `POST/PATCH /me/plans` | **yes** | **customer** | **B** — a plan carries a price |
| 7 | **Voucher batch creation** | `VoucherService::issueBatch` ← `POST /me/vouchers` | **yes** | **customer** | **C** — the sale must attribute to a uCRM service |
| 8 | **Voucher issuance** | same call | **yes** | **customer** | **C** |
| 9 | **Voucher revoke** | `POST /me/vouchers/{id}/revoke` | **yes** | **customer** | **B** |
| 10 | **Voucher redemption** | `mt_voucher_redeem(code)` | **wired to nothing** (`docs/86`) | guest | **FORBID** — §5 |
| 11 | **Session visibility** | `GET /me/sessions`, RLS | **yes** | **customer** | **A** |
| 12 | **Session disconnect** | `POST /me/sessions/{id}/disconnect` | **yes** | **customer** | **A** |
| 13 | **Customer PWA — network screens** | `/me`, `/me/sites`, `/me/usage`, `/me/uplink`, `/me/intents` | **yes** | **customer** | **A** |
| 14 | **Customer PWA — commercial screens** | — | **NOT BUILT** | customer | **B/C** |
| 15 | **Billing** | — | **NOT BUILT**, no adapter | customer | **C** |
| 16 | **Support** | — | **NOT BUILT**, and possibly not API-reachable (`docs/100` §9.4) | customer | **B** |
| 17 | **Provisioning — desired config** | `mt_device_set_desired(device, desired, actor)` | fn yes, route no | **staff (D)** | **A** |
| 18 | **Provisioning — intent claim / run** | `mt_intent_claim`, `IntentWorker` | **yes** | **worker** | **A** |
| 19 | **Device credentials** | `mt_device_set_secret(device, username, sealed, actor)` | fn yes, route no | **staff (D)** | **A** |
| 20 | **WAN interface record** | `mt_device_set_wan(device, interface, by)` | fn yes, route no | **staff (D)** | **A** |
| 21 | **Suspension** | **no operation exists**; `mt_services.status` has no writer | **NOT BUILT** | would be staff | **B** — U-9 |
| 22 | **Decommission** | `mt_device_set_state(device,'decommissioned',actor)` | fn yes, route no | **staff (D)** | **A** |
| 23 | **RADIUS accounting ingest** | `mt_session_account(...)` | **yes** | **RADIUS** | **A** |
| 24 | **Audit write** | `mt_audit_write(...)` inside the seven | **yes** | **system** | **A — never gated** (§6) |

---

## 5. Two classifications that are NOT what the brief suggested

### 5.1 Voucher redemption — **FORBIDDEN**, not "required"

The guest portal must **never** consult uCRM, and this follows from decisions
already closed:

- `docs/89`: *"tenant/customer data must not be required to perform the
  anti-enumeration check"* — an unknown code resolves no customer, so a check
  needing one could not run **in exactly the case enumeration produces**.
- `docs/88` P-1: `dnb_portal` holds EXECUTE on one function and **no table
  privileges**.
- A uCRM round-trip inside redemption would make response time depend on a
  remote system, defeating the **response-uniformity** property `docs/89`
  records as not tradeable.

**Redemption authorizes from `voucher.site_id` and that site's authorized NAS
set. Commercial identity does not enter it.**

### 5.2 Audit — **never gated**, under any model

An unlinked customer must still be fully auditable. Gating `mt_audit_write` on a
uCRM link would mean the least-established records are the least recorded —
exactly backwards. `mt_audit_log.actor_kind` stays `principal | staff | system`;
**no new actor kind.**

---

## 6. The commercial activation boundary

Collecting rows 3–9 and 14–16, and **dropping the single-gate idea**:

```
NETWORK INVENTORY — Domain-B identity alone is sufficient
   register · stage · ship · set WAN · set desired config · set credentials
   decommission · provision · accounting ingest · audit · session read
        │
        │  ── the boundary is crossed at any ONE of these ──
        ▼
COMMERCIAL ACTIVITY — a valid uCRM customer link is required
   B   assign a router to a customer          (inventory becomes visible)
   B   create or edit a plan                  (a price is set)
   B   revoke a voucher
   B   create a site
   B   suspend a service
   B   expose support
   C   create a service                       (binds the uCRM service link)
   C   issue a voucher batch                  (the sale)
   C   expose billing
```

### 6.1 Why there is no single gate — measured

`mt_device_assign` looked like the one structural moment, because setting
`customer_id` is what makes a device visible through RLS. It is **not
sufficient**:

```
mt_sites    columns: id, customer_id, service_id, name, location, created_at
mt_vouchers references a device?  NO
live estate: sites with no device = 1
```

**Neither a site nor a voucher references a router.** The chain
`service → site → plan → voucher` reaches a completed sale with no device ever
assigned — and the simulator already contains a site with no router. An
assignment-only gate would never fire for such a customer.

> **The rule is enforced at the set of operations above, each in its own
> function, not at one structural moment and not at row creation.**

### 6.2 What is NOT gated, deliberately

`mt_customer_create` stays **ungated**. Gating it would block the legitimate
internal inventory workflow that L-1 just protected, and `ucrm_client_id` stays
nullable — **U-2 is not decided here and still waits on E-2**.

---

## 7. The fifteen design tests

**REQUIRED** · **OPTIONAL** · **FORBIDDEN** · **N/A**

| # | Scenario | uCRM linkage | Note |
|---|---|---|---|
| 1 | Equipment-first staging | **N/A** | no customer is referenced |
| 2 | Equipment-first shipping | **N/A** | same |
| 3 | Customer-first onboarding | **REQUIRED** | the customer is created *from* a uCRM client |
| 4 | Router assignment | **REQUIRED** | the boundary crossing |
| 5 | PWA visibility | **REQUIRED** *(for the estate to exist at all)* | network screens need no *further* check — RLS already governs, and §5.1 of `docs/101` measured that unclaimed devices are invisible |
| 6 | Voucher issuance | **REQUIRED** — customer **and** service | the sale |
| 7 | HotSpot service activation | **REQUIRED** (C) | **and NOT BUILT** — `docs/93`: `activating`/`active` are unreachable |
| 8 | Billing | **REQUIRED** (C) | NOT BUILT |
| 9 | Support | **REQUIRED** (B) | NOT BUILT; reachability unproven |
| 10 | Customer suspension | **REQUIRED** (B) | NOT BUILT — U-9 |
| 11 | Customer cancellation | **REQUIRED** (B) | NOT BUILT. **Domain-B history is retained** — `docs/101` §12 |
| 12 | Router replacement | **N/A** for the new unit until assigned; **REQUIRED** at assignment | the outgoing unit goes `orphaned`/`decommissioned`, keeping history |
| 13 | Customer migration to another uCRM client | **REQUIRED**, via audited unlink + relink | `docs/101` §6.1 — forbidding it would strand the estate |
| 14 | Audit history | **FORBIDDEN to gate** | §5.2 |
| 15 | Unlink / relink | **staff-only, audited** | carries previous and new relationship plus a reason |

---

## 8. Enforcement — where the checks would live

Not implemented. Recorded so the shape is agreed before anything is written.

| | |
|---|---|
| **where** | inside the `SECURITY DEFINER` function for each gated operation, in the same transaction as its effect — never in PHP, never in the panel, never in the browser |
| **what it checks** | `mt_customers.ucrm_client_id IS NOT NULL` for **B**; additionally `mt_services.ucrm_service_id IS NOT NULL` for **C** |
| **plus, for C** | the **coherence rule** (`docs/101` §7.3): the uCRM service's `clientId` must equal the client linked to that service's customer. No FK can express it; the linking function checks it against uCRM at link time |
| **failure mode** | refuse with a named reason. **Never** degrade silently, and never fall back to an unlinked path |
| **customer-plane operations (6–9)** | rows 6–9 are invoked by `dnb_app`, which today writes `mt_plans` and `mt_vouchers` **directly** under RLS. A gate therefore requires those writes to move behind definer functions first — a real piece of work, not a flag |

> **Finding B-2 — the customer-plane commercial writes are not behind functions
> at all.** `PlanRepository` and `VoucherService` write their tables directly.
> Gating rows 6–9 means giving them definer functions with audit, the same
> shape W-1 gave the seven. That is the largest single item implied by U-1, and
> it is not a small one.

---

## 9. What this settles, and what it does not

**Settled by measurement**

| | |
|---|---|
| the effective boundary is **RLS + triggers + functions**, not grants | §2.1 |
| services and sites have **no production writer** | §3, B-1 |
| **no single gate exists** — site and voucher reference no device | §6.1 |
| redemption must **not** consult uCRM | §5.1 |
| audit must **never** be gated | §5.2 |
| the customer-plane commercial writes are **not behind functions** | §8, B-2 |

**Still open**

| # | |
|---|---|
| **U-1** | approval of the boundary in §6 as the rule |
| **U-2** | `ucrm_client_id NOT NULL` — **E-2, the production census** |
| **U-5** | the service link, which row 5 cannot be built without |
| **U-6** | authoritative source for the OTP phone |
| **U-7** | S-1 proxy or S-2 signed assertion |
| **U-8** | how long a customer may stay unlinked |
| **U-9** | does a uCRM suspension suspend the Domain-B service? |
| **C3** | may one uCRM client hold several Domain-B customers? |
| **B-2** | approval to move the customer-plane commercial writes behind definer functions |

---

**Stopping here. Suite unchanged: 1,599 assertions, 27 suites. No schema change,
no migration, no sync engine, nothing installed, no gate moved.**
