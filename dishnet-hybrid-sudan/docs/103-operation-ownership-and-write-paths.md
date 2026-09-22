# 103 — Operation ownership and write paths

**Repository inspection only. No production change, no schema change, no
migration, no uCRM integration.** Every row below was found by searching the
code or asking the database — **never inferred from a table grant**, for the
reason recorded in §11.

---

## 0. The headline

The schema and the simulator make Domain B look more complete than it is.
Enumerating the actual writers gives a different picture:

```
identity chain          production write path?
──────────────          ──────────────────────
uCRM client             (external)
  ↓
mt_customers            function exists, NO production caller
  ↓
mt_principals           NONE — Plugin/Simulator.php only
  ↓
mt_services             NONE — Plugin/Simulator.php only
  ↓
mt_sites                NONE — Plugin/Simulator.php only
  ↓
mt_devices              YES — seven definer functions (no route bound)
  ↓
mt_plans                YES — Policy/PlanRepository.php  ← POST /me/plans
  ↓
mt_vouchers             YES — Vouchers/VoucherService.php ← POST /me/vouchers
  ↓
mt_sessions             YES — mt_session_account()        ← dnb_radius
```

> **Finding W-1 — four of the five identity entities have no production write
> path.** Customer, principal, service and site are written by the simulator, by
> tests, or by nothing. `src/Customers/` is an **empty directory**.

This matters for U-1 directly: **you cannot gate an operation that does not
exist.** Designing uCRM synchronisation now would mean synchronising against
workflows that have never been built.

---

## 1. Complete mutating-operation inventory

Found by `grep -rnoE "(INSERT INTO|UPDATE|DELETE FROM) +mt_[a-z_]+" src/` plus
the function inventory from `pg_proc`. **20 write statements across 12 files.**

| # | Operation | Entry point | Caller | Actor | Tables | Audit | Idempotent | Production writer |
|---|---|---|---|---|---|---|---|---|
| 1 | Customer creation | `mt_customer_create(name, created_by)` | `Plugin/Simulator.php`, tests | staff | `mt_customers`, `mt_audit_log` | **W-1, in-transaction** | no | **NO CALLER** |
| 2 | Customer linking | — | — | — | — | — | — | **NOT IMPLEMENTED** |
| 3 | Customer unlinking | — | — | — | — | — | — | **NOT IMPLEMENTED** |
| 4 | Principal creation | `INSERT mt_principals` | `Plugin/Simulator.php` | — | `mt_principals` | none | no | **NOT IMPLEMENTED** |
| 5 | Principal update | `mt_auth_create_session` sets `last_login_at` only | `Auth/Authenticator` | customer | `mt_principals` | none | no | **partial — timestamp only** |
| 6 | Service creation | `INSERT mt_services` | `Plugin/Simulator.php` | — | `mt_services` | none | no | **NOT IMPLEMENTED** |
| 7 | Service linking | — | — | — | — | — | — | **NOT IMPLEMENTED** |
| 8 | Site creation | `INSERT mt_sites` | `Plugin/Simulator.php` | — | `mt_sites` | none | no | **NOT IMPLEMENTED** |
| 9 | Site update | — | — | — | — | — | — | **NOT IMPLEMENTED** |
| 10 | Router registration | `mt_device_register(serial, model, ros, wg_pubkey, tunnel_ip, staged_by)` | `Devices/DeviceRegistry` | staff | `mt_devices`, `mt_audit_log` | **W-1** | no | yes, **no route bound** |
| 11 | Router staging | `mt_device_set_state(device,'staged',actor)` | `DeviceRegistry::transition` | staff | `mt_devices`, `mt_audit_log` | **W-1** | no | yes, **no route bound** |
| 12 | Router shipping | same, `'shipped'` | same | staff | same | **W-1** | no | yes, **no route bound** |
| 13 | Router assignment | `mt_device_assign(device, customer, site, name, actor)` | `DeviceRegistry::assign` | staff | `mt_devices`, `mt_audit_log` | **W-1** | no | yes, **no route bound** |
| 14 | WAN interface record | `mt_device_set_wan(device, interface, by)` | `DeviceRegistry` | staff | `mt_devices`, `mt_audit_log` | **W-1** | no | yes, **no route bound** |
| 15 | Device credentials | `mt_device_set_secret(device, username, sealed, actor)` | `DeviceRegistry::setCredentials` | staff | `mt_device_secrets`, `mt_audit_log` | **W-1** | no | yes, **no route bound** |
| 16 | Desired config | `mt_device_set_desired(device, desired, actor)` + `INSERT mt_device_config` | `DeviceRegistry::setDesired` | staff | `mt_device_config`, `mt_audit_log` | **W-1** | no | yes, **no route bound** |
| 17 | Provisioning — enqueue | `INSERT mt_intents` | `Intents/IntentQueue` | staff/system | `mt_intents` | none | no | yes |
| 18 | Provisioning — claim/run | `mt_intent_claim`, `UPDATE mt_intents` ×6 | `Jobs/IntentWorker` | **worker** | `mt_intents` | `AuditLog` (caller-written) | lease-based | yes |
| 19 | Plan creation | `INSERT mt_plans` | `Policy/PlanRepository` ← `POST /me/plans` | **customer** | `mt_plans`, `mt_profiles` | `AuditLog` **(skippable)** | **no** | **yes** |
| 20 | Plan update | `UPDATE mt_plans` ×2 | same ← `PATCH /me/plans/{id}` | customer | `mt_plans` | `AuditLog` (skippable) | no | **yes** |
| 21 | Plan retire | `UPDATE mt_plans` | same ← `POST …/retire` | customer | `mt_plans` | `AuditLog` (skippable) | no | **yes** |
| 22 | Voucher batch creation | `INSERT mt_voucher_batches` | `Vouchers/VoucherService::issueBatch` ← `POST /me/vouchers` | **customer** | `mt_voucher_batches` | `AuditLog` (skippable) | **YES — the only one** | **yes** |
| 23 | Voucher issuance | `INSERT mt_vouchers`, `INSERT mt_hotspot_users` | same call | customer | `mt_vouchers`, `mt_hotspot_users` | `AuditLog` (skippable) | yes, with the batch | **yes** |
| 24 | Voucher revoke | `UPDATE mt_vouchers` | `VoucherService` ← `POST …/revoke` | customer | `mt_vouchers` | `AuditLog` (skippable) | no | **yes** |
| 25 | Voucher redemption | `mt_voucher_redeem(code)` | `VoucherService::redeem` | — | `mt_vouchers`, `mt_hotspot_users` | **none — F-7** | no | **NO CALLER** (`docs/86`) |
| 26 | Session disconnect | `Sessions/SessionService` ← `POST …/disconnect` | route | customer | `mt_sessions` | `AuditLog` (skippable) | no | **yes** |
| 27 | Accounting ingest | `mt_session_account(...)` | `Sessions/AccountingIngest` | **RADIUS** | `mt_sessions` | **none** | by `session` id | **yes** |
| 28 | Uplink sample | `mt_uplink_record`, `mt_uplink_prune` | `Jobs/UplinkSampler` | worker | `mt_uplink_samples` | none | no | **yes** |
| 29 | Device decommission | `mt_device_set_state(device,'decommissioned',actor)` | `DeviceRegistry` | staff | `mt_devices`, `mt_audit_log` | **W-1** | no | yes, **no route bound** |
| 30 | Customer suspension | — | — | — | — | — | — | **NOT IMPLEMENTED** |
| 31 | Service suspension | — | — | — | — | — | — | **NOT IMPLEMENTED** |
| 32 | Audit write — provisioning | `mt_audit_write(...)` | the seven, in-transaction | system | `mt_audit_log` | itself | no | **yes** |
| 33 | Audit write — customer API | `Audit/AuditLog::write` | `Routes.php` ×6 | customer | `mt_audit_log` | itself | no | **yes, but skippable** |
| 34 | Idempotency record | `INSERT/UPDATE mt_idempotency` | `Http/Idempotency` | customer | `mt_idempotency` | none | itself | **yes** |
| 35 | Migration record | `INSERT mt_migrations` | `Db/Migrator` | owner | `mt_migrations` | none | by filename | **yes** |

---

## 2. Current write-path inventory, by file

| File | Writes |
|---|---|
| `Vouchers/VoucherService.php` | `mt_vouchers` (I+U), `mt_voucher_batches` (I+U), `mt_hotspot_users` (I) |
| `Policy/PlanRepository.php` | `mt_plans` (I + U×2) |
| `Policy/ProfileResolver.php` | `mt_profiles` (I) |
| `Intents/IntentQueue.php` | `mt_intents` (I+U) |
| `Jobs/IntentWorker.php` | `mt_intents` (U×6) |
| `Devices/DeviceRegistry.php` | `mt_device_config` (I) — the rest via definer functions |
| `Http/Idempotency.php` | `mt_idempotency` (I+U) |
| `Audit/AuditLog.php` | `mt_audit_log` (I) |
| `Db/Migrator.php` | `mt_migrations` (I) |
| **`Plugin/Simulator.php`** | **`mt_principals`, `mt_services`, `mt_sites` (I), `mt_customers` (U)** |

> **The simulator is the only writer of three tables and the only updater of a
> fourth.** It is excluded from the release package (`docs/96`), so **an
> installed copy of RC1 cannot create a principal, a service or a site at all.**

---

## 3–6. Requirements per operation

### The four identities, kept apart

**A** Domain-B identity · **B** uCRM customer · **C** uCRM service ·
**D** physical/network device.

These are **not** one "customer active" flag. Operation 10 needs **D** and
nothing else. Operation 22 needs **A + B + C** and no device at all.

### 6. Audit requirements

| Class | Operations | Property |
|---|---|---|
| **W-1, unskippable** | 1, 10–16, 29 | the audit row is written **inside** the definer function, in the same transaction. A caller cannot omit it |
| **caller-written, skippable** | 18–24, 26, 33 | `Kernel` wraps these in `TenantContext::run`, so they *are* transactional — the defect is that a new handler can simply not call `AuditLog` (**F-8**, measured) |
| **no audit at all** | 17, 25, 27, 28, 34, 35 | includes **accounting ingest** and **voucher redemption** (**F-7**) |

**Audit must never be gated on a uCRM link** (`docs/102` §5.2). It is the record
of what happened, including to unlinked rows.

---

## 7. Current versus missing production writers

| Entity | Writer today | Status |
|---|---|---|
| `mt_devices` | seven definer functions | **built**, no route bound (W-4) |
| `mt_plans` | `PlanRepository` | **built**, customer route live |
| `mt_vouchers` / `mt_voucher_batches` | `VoucherService` | **built**, customer route live |
| `mt_sessions` | `mt_session_account` | **built**, RADIUS live |
| `mt_intents` | `IntentQueue` / `IntentWorker` | **built** |
| `mt_uplink_samples` | `UplinkSampler` | **built** |
| `mt_customers` | `mt_customer_create` | **function only — no production caller** |
| `mt_principals` | — | **MISSING** |
| `mt_services` | — | **MISSING** |
| `mt_sites` | — | **MISSING** |
| customer/service link | — | **MISSING** (not designed to be built yet) |
| suspension | — | **MISSING** |

---

## 8. The boundary matrix

**REQUIRED · OPTIONAL · FORBIDDEN · N/A · NOT IMPLEMENTED**

| Operation | Domain-B | uCRM customer | uCRM service | Router | Site | Audit | Current writer |
|---|---|---|---|---|---|---|---|
| Customer creation | REQUIRED | **OPTIONAL** | N/A | N/A | N/A | REQUIRED | fn, no caller |
| Customer linking | REQUIRED | REQUIRED | N/A | N/A | N/A | REQUIRED | **NOT IMPL** |
| Customer unlinking | REQUIRED | REQUIRED | N/A | N/A | N/A | REQUIRED | **NOT IMPL** |
| Principal creation | REQUIRED | OPTIONAL | N/A | N/A | N/A | REQUIRED | **NOT IMPL** |
| Principal update | REQUIRED | OPTIONAL | N/A | N/A | N/A | REQUIRED | partial |
| Service creation | REQUIRED | **REQUIRED** | **REQUIRED** | N/A | N/A | REQUIRED | **NOT IMPL** |
| Service linking | REQUIRED | REQUIRED | REQUIRED | N/A | N/A | REQUIRED | **NOT IMPL** |
| Site creation | REQUIRED | **REQUIRED** | OPTIONAL | N/A | N/A | REQUIRED | **NOT IMPL** |
| Site update | REQUIRED | REQUIRED | OPTIONAL | N/A | REQUIRED | REQUIRED | **NOT IMPL** |
| Router registration | REQUIRED | **N/A** | N/A | REQUIRED | N/A | REQUIRED ✓ | built |
| Router staging | REQUIRED | **N/A** | N/A | REQUIRED | N/A | REQUIRED ✓ | built |
| Router shipping | REQUIRED | **N/A** | N/A | REQUIRED | N/A | REQUIRED ✓ | built |
| Router assignment | REQUIRED | **REQUIRED** | OPTIONAL | REQUIRED | REQUIRED | REQUIRED ✓ | built |
| Provisioning | REQUIRED | N/A | N/A | REQUIRED | OPTIONAL | REQUIRED ✓ | built |
| Plan creation | REQUIRED | **REQUIRED** | OPTIONAL | N/A | N/A | REQUIRED (skippable) | built |
| Plan update / retire | REQUIRED | REQUIRED | OPTIONAL | N/A | N/A | REQUIRED (skippable) | built |
| Voucher batch creation | REQUIRED | **REQUIRED** | **REQUIRED** | **N/A** | REQUIRED | REQUIRED (skippable) | built |
| Voucher issuance | REQUIRED | REQUIRED | REQUIRED | **N/A** | REQUIRED | REQUIRED (skippable) | built |
| Voucher redemption | REQUIRED | **FORBIDDEN** | **FORBIDDEN** | N/A | REQUIRED | REQUIRED (**F-7: none**) | **NO CALLER** |
| Voucher revoke | REQUIRED | REQUIRED | OPTIONAL | N/A | N/A | REQUIRED (skippable) | built |
| Session disconnect | REQUIRED | OPTIONAL | N/A | N/A | N/A | REQUIRED (skippable) | built |
| Accounting ingest | REQUIRED | **FORBIDDEN** | **FORBIDDEN** | N/A | N/A | **none today** | built |
| Device decommission | REQUIRED | N/A | N/A | REQUIRED | N/A | REQUIRED ✓ | built |
| Customer suspension | REQUIRED | REQUIRED | OPTIONAL | N/A | N/A | REQUIRED | **NOT IMPL** |
| Service suspension | REQUIRED | REQUIRED | REQUIRED | N/A | N/A | REQUIRED | **NOT IMPL** |
| Audit | REQUIRED | **FORBIDDEN to gate** | FORBIDDEN to gate | N/A | N/A | itself | built |

### 8.1 The four special cases verified

| | Verified |
|---|---|
| **A. `mt_customer_create` without uCRM** | ✔ its signature takes no uCRM id and `ucrm_client_id` is nullable — **OPTIONAL**, and it must stay so |
| **B. register / stage / ship without a customer** | ✔ `mt_device_register` takes no customer; the simulator's `staged` router has `customer_id` NULL — **N/A** |
| **C. `mt_device_assign` establishes the relationship** | ✔ it is the only operation taking `(device, customer, site)` together, and W-2 constrains `device.customer_id = site.customer_id` |
| **D. redemption must not call uCRM** | ✔ **FORBIDDEN** — `docs/89`'s anti-enumeration rule and response uniformity |
| **E. audit never blocked by missing linkage** | ✔ **FORBIDDEN to gate** |
| **F/G. site and service creation** | ✔ **no production writer** — simulator only |
| **H. plan/voucher writes** | ✔ `PlanRepository` and `VoucherService`, under `dnb_app` + RLS, **not** behind definer functions (**B-2**) |

### 8.2 Two rows worth reading twice

- **Voucher issuance needs no router.** `mt_vouchers` has no device column and
  the estate already holds a site with no router. A sale completes without
  hardware.
- **Accounting ingest must not consult uCRM.** `dnb_radius` holds EXECUTE on one
  function and no table privileges; a NAS packet must never trigger a CRM
  lookup.

---

## 9. Missing write paths — what would be needed

**Not built. Specified so the shape is agreed first.**

| Path | Why needed | Invoked by | Identity | uCRM | Audit | Idempotent | Boundary |
|---|---|---|---|---|---|---|---|
| **Principal create/disable** | nobody can be given PWA access today | staff, via the bridge | A | OPTIONAL | REQUIRED | no | `dnb_adminwrite`; **`phone` is the auth key — creating one grants login** |
| **Service create** | the commercial container; nothing can hang off it | staff, via the bridge | A+B+C | **REQUIRED** | REQUIRED | no | `dnb_adminwrite`; enforces the §7.3 coherence rule |
| **Site create/update** | vouchers are site-bound (2b); no site, no sale | staff, via the bridge | A+B | REQUIRED | REQUIRED | no | `dnb_adminwrite` |
| **Customer link / unlink** | I-1 | staff, via the bridge | A+B | REQUIRED | REQUIRED | no | `dnb_adminwrite`, reason recorded |
| **Service link / unlink** | U-5 | staff, via the bridge | A+B+C | REQUIRED | REQUIRED | no | `dnb_adminwrite`, coherence checked |
| **Suspension** | U-9 | staff or a uCRM event | A+B | REQUIRED | REQUIRED | no | `dnb_adminwrite` |
| **Definer functions for plan/voucher** | **B-2** — make the audit unskippable and give the link a place to be checked | customer plane | A+B(+C) | per §8 | **REQUIRED, in-transaction** | keep the existing key | moves `dnb_app` off direct table writes |

---

## 10. uCRM bridge responsibility map

| Surface | Initiates | Never |
|---|---|---|
| **uCRM DishNet plugin** | link/unlink; service, site and principal creation; router assignment; suspension — each by calling the Domain-B API | connect to Domain-B PostgreSQL; hold a Domain-B role credential; write a table; implement business logic |
| **Domain-B Admin API** | **authoritative for every Domain-B operation.** The plugin is a caller, never a second implementation | trust a staff identity supplied by a browser (U-7) |
| **Customer PWA** | plans, vouchers, revoke, disconnect — as today | choose the customer; reach uCRM directly; reach the database |
| **Guest portal** | redemption only, through `dnb_portal` | consult uCRM; touch any table |
| **Background worker** | intent claim/run, session reap, uplink sample/prune | anything commercial |

> **The plugin must not become a second business-logic implementation.** Its job
> is identity, presentation and invocation.

---

## 11. The security evidence hierarchy — a project rule

Strongest first. **An operation is authorized only on the evidence available at
the level claimed.**

```
1. actual execution test              ← strongest
2. RLS / database policy
3. SECURITY DEFINER function boundary
4. application authorization
5. route / UI availability
6. table grants alone                 ← weakest; NOT evidence
```

Earned, not asserted: `dnb_app` holds `INSERT/UPDATE/DELETE` on **20 tables**
including `mt_customers` and `mt_audit_log`, and can use almost none of it — the
customer INSERT is refused by **RLS `WITH CHECK`**, the audit DELETE by the
**append-only trigger**. Classifying by grant would have been wrong about both.

**Never cite a grant, a route or a button as proof that something is permitted
or prevented.**

---

## 12. Repository-edit safety — a project rule

Three documentation edits in this project used `str.replace` with no assertion.
When the anchor did not match they **changed nothing and reported success**.

> **Any automated source or document transformation must assert, and exit
> non-zero when the assertion fails:**
>
> 1. the expected **anchor exists** before editing;
> 2. the **occurrence count** is what was expected;
> 3. the **replacement count** is what was expected;
> 4. the **resulting content contains** the intended section.
>
> **"The script exited 0" is not evidence that the change happened** — the same
> rule as §11, applied to the repository instead of the database.

This is a rule for tooling and documentation edits. **No application behaviour
changes for it.**

---

## 13. Unresolved decisions

| # | Open | Waits on |
|---|---|---|
| **U-1** | approval of the §8 matrix as the boundary | this document |
| **U-2** | `ucrm_client_id NOT NULL` | **E-2, the production census** |
| **U-5** | the service link | U-1 |
| **U-6** | authoritative source for the OTP phone | approval |
| **U-7** | S-1 proxy or S-2 signed assertion | approval |
| **U-8** | how long a customer may stay unlinked | U-1 |
| **U-9** | does a uCRM suspension suspend the Domain-B service? | approval |
| **C3** | may one uCRM client hold several Domain-B customers? | a real case |
| **B-2** | move plan/voucher writes behind definer functions | approval |
| **W-1 (new)** | **build the four missing write paths** — principal, service, site, and a production customer caller | approval; **this is larger than the link work** |
| **F-7** | redemption writes no audit row | `docs/87`, unauthorized |
| **F-8** | six customer-API audit sites are skippable | open |

> **The largest item is not the uCRM link.** It is that customer, principal,
> service and site have no production write path at all. Until they do, a
> synchronisation engine would be synchronising against workflows that exist
> only in the simulator.

---

**Stopping here. Suite unchanged: 1,599 assertions, 27 suites. No schema change,
no migration, no adapter, no sync engine, nothing installed, no gate moved.**
