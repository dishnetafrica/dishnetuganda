# 107 — Identity and onboarding decisions

**Design and decision package only. No migration, no schema modification, no
production connection, no application code, no test-fixture change.**

This is the last identity/onboarding design checkpoint before the onboarding
spine is implemented. It closes what the measured evidence of `docs/100`–`106`
supports closing, and refuses to close what it does not.

---

## 1. O-1 — the database invariant, closed as a design

> **INVARIANT O-1.** For every row of `mt_sites`, the pair
> `(customer_id, service_id)` must reference the matching identity
> `(customer_id, id)` in `mt_services`. A site belonging to customer A may never
> reference a service belonging to customer B.

### 1.1 The target definition

```sql
-- step 1 — the supporting UNIQUE the composite FK requires
ALTER TABLE mt_services
  ADD CONSTRAINT mt_services_id_customer_key UNIQUE (id, customer_id);

-- step 2 — the invariant
ALTER TABLE mt_sites
  ADD CONSTRAINT mt_sites_service_customer_fkey
  FOREIGN KEY (customer_id, service_id)
  REFERENCES  mt_services (customer_id, id);
```

| Property | Decision | Basis |
|---|---|---|
| supporting UNIQUE | **required**, `(id, customer_id)` on `mt_services` | a composite FK cannot reference a non-unique pair; `mt_services` has only its PK today |
| does it change cardinality? | **No.** `PRIMARY KEY (id)` is strictly stronger, so the pair is automatically unique | measured twice — the duplicate is rejected by `mt_services_pkey`, and the UNIQUE succeeded even with a violating site present |
| referenced column order | **cosmetic** — both orders accepted | measured; PostgreSQL matches the column *set* |
| both columns NOT NULL | **yes** — `mt_sites.customer_id` and `.service_id` | measured |
| `MATCH FULL` | **NO** | with both columns `NOT NULL`, `MATCH SIMPLE` is equivalent; adding it would be inert **and would imply a NULL case exists** |
| additional CHECK | **NO** | `mt_devices` needed one only because its `site_id` *is* nullable |
| `NOT VALID` | **NO** | it would declare the invariant without enforcing it, creating a partially-enforced window. The invariant must bind immediately |

### 1.2 Measured lock behaviour (`docs/106` §7)

| Statement | `mt_services` | `mt_sites` |
|---|---|---|
| step 1 — `ADD CONSTRAINT … UNIQUE` | **ACCESS EXCLUSIVE** | — |
| step 2 — `ADD CONSTRAINT … FOREIGN KEY` | SHARE ROW EXCLUSIVE | **SHARE ROW EXCLUSIVE** |

**Step 1 is the only read-blocking window, and it falls on `mt_services` alone.**
`mt_sites` readers are never blocked. The index builds inside the migration
transaction; only `CONCURRENTLY` cannot, and `Migrator` runs each file as one
implicit transaction. **Duration stays UNMEASURED — no production timing may be
claimed until E-2.**

### 1.3 Two separate operational gates

**The census and the migration are different operations, run at different times,
with a human decision between them.** They must not be combined into one script,
because a script that measures and then acts on its own measurement gives the
operator nothing to approve.

```
GATE 1 — CENSUS (read-only, §8)      →   verdict: CLEAR | BLOCKED(n) | INDETERMINATE
                                              │
                                    operator reviews; per-row decisions if BLOCKED
                                              │
GATE 2 — MIGRATION (one transaction) →   applies steps 1 and 2, or refuses
```

**Violating rows must be zero before step 2 is attempted.** It refuses by itself
if they are not — measured — but its error names only **one** offending pair, so
the census is what enumerates them. **Do not repair automatically.**

**Not authorized to implement.**

---

## 2. P-B — CLOSED: `mt_principals.phone` remains globally UNIQUE

> **DECISION P-B — CLOSED.** `mt_principals_phone_uq UNIQUE (phone) WHERE phone
> IS NOT NULL` stays exactly as it is. **The uniqueness is not to be weakened,
> narrowed to per-customer, or dropped.**

### Why — the authentication path depends on it for correctness

```sql
-- mt_auth_issue_code
SELECT id, customer_id INTO v_principal, v_customer
  FROM mt_principals WHERE phone = p_phone AND status = 'active';
```

A **non-`STRICT`** `SELECT … INTO`, with **no `ORDER BY`** and **no `LIMIT`**.
Measured:

| Form | With two matching rows |
|---|---|
| `SELECT … INTO` | **returns the first row, no error** |
| `SELECT … INTO STRICT` | raises `P0003 — query returned more than one row` |

The code row stores `principal_id` **and `customer_id`**, and
`mt_auth_verify_code` returns both. So:

> **With duplicate phone numbers, the one-time code — and therefore the tenant of
> the resulting session — would be bound to an arbitrary principal, silently.**
> No error, no log, no signal. The unique index is load-bearing for
> **correctness**, not for lookup speed.

### C10 is a separate decision and must not be solved here

Whether one human may legitimately operate several DishNet customers is **C10** —
*"Is the docs/42 Reseller the same person as the Customer PWA user?"*, **ARCH**,
rated *"Highest. Rebuilds permission logic and possibly a surface"* — together
with **C6** (owner/operator roles) and **C16** (where staff records live).

> **Do not solve C10 by weakening the phone constraint.** If one person must
> serve two customers the candidate answers are a **second number**, an explicit
> **principal-selection step after OTP**, or a login that **names the customer
> first**. Each is an authentication redesign, and **authentication is not being
> redesigned now.**

**Binding on the principal writer:** a duplicate phone is a **refusal**, never an
upsert and never a silent selection.

---

## 3. P-C — CLOSED: principal reassignment is PROHIBITED

> **DECISION P-C — CLOSED.** No operation may change
> `mt_principals.customer_id`. There is to be no reassignment function, route or
> administrative action.

### Why — live sessions would keep the old tenant

```sql
-- mt_auth_sessions carries its OWN customer_id
INSERT INTO mt_auth_sessions (principal_id, customer_id, token_hash, expires_at) …

-- and token resolution returns the SESSION's copy, not the principal's
SELECT s.principal_id, s.customer_id
  FROM mt_auth_sessions s
  JOIN mt_principals p ON p.id = s.principal_id
 WHERE s.token_hash = … AND p.status = 'active';
```

The join to `mt_principals` exists **only to test `status`**. The tenant comes
from `s.customer_id`.

> Moving a principal from customer A to customer B would leave **every live
> session still authorized in A's context** until it expired or was revoked — a
> cross-tenant authorization window opened by an administrative action and
> invisible to the operator, the customer and the audit trail.

Compounding it: `docs/100` measured that `sold_by`, `created_by` and
`actor_principal_id` are `SET NULL` and **nothing errors**, so history silently
loses its actor.

### The required lifecycle

```
ACTIVE principal ──▶ DISABLED          (status = 'disabled')
                        │
                        ▼
        create a NEW principal under the new customer
```

### Why disabling is safe where reassignment is not

The asymmetry is in the same query, and it is the whole argument:

| Field | Read from | Effect |
|---|---|---|
| `p.status` | **the principal, live**, on every resolution | disabling takes effect **immediately** — existing tokens stop resolving |
| `s.customer_id` | **the session's own snapshot**, written once at login | reassignment would **not** take effect — the old tenant persists until expiry |

`mt_auth_issue_code` additionally filters `status = 'active'`, so a disabled
principal receives no new codes either.

> **Disable is enforced at resolution time; reassignment would not be enforced at
> all.** One field is re-read on every request and the other is a copy — which is
> exactly why the safe lifecycle is disable-and-recreate.

**Not authorized to implement.**

---

## 4. S-A — CLOSED: service migration is PROHIBITED as an ordinary operation

> **DECISION S-A — CLOSED.** `mt_services.customer_id` is not a mutable field. A
> service belongs to the commercial relationship it was created for.

**Status today:** UNIMPLEMENTED — no writer exists (`Plugin/Simulator.php` only),
no document describes one, `customer_id` is `NOT NULL` with `ON DELETE RESTRICT`.

### How O-1 reinforces it

With the composite FK in place and `ON UPDATE NO ACTION` inherited:

```sql
UPDATE mt_services SET customer_id = <other customer> WHERE id = …;
-- refused while ANY site references that service
```

**The prohibition stops being a policy and becomes a constraint.** A stray
`UPDATE`, a careless admin script, or a future writer cannot do it by accident —
only a deliberate, designed operation could, and it would have to move the
service, its sites and their devices together.

### If commercial ownership genuinely changes

Model it as a **lifecycle**, not a field update:

```
old service ──▶ status = 'ended', ended_at set
                        │
                        ▼
      new service created under the new customer,
      new site(s), devices re-assigned via mt_device_assign
```

That preserves history, keeps every audit row attributable to the customer that
actually held the relationship, and needs no new mutation path. **The uCRM side
re-papering (`docs/101`: unlink/relink, audited, carrying previous and new
relationship plus a reason) is a separate operation on the *link*, not on the
service's ownership.**

**Not authorized to implement.**

---

## 5. U-1 / U-5 — OPEN, and reduced to one operator question

### 5.1 What "commercially active" must mean

> **Proposed definition.** A Domain-B service is **commercially active** when
> revenue-bearing artifacts may be issued against it — plans priced, vouchers
> sold, access granted to paying guests.

Five conditions are candidates for that moment:

```
uCRM customer link  +  uCRM service link  +  Domain-B customer
                    +  Domain-B service   +  site
```

### 5.2 What the evidence already settles

| | |
|---|---|
| a Domain-B customer may exist with **no** uCRM link | measured |
| a device may exist with **no** customer, across `registered → staged → shipped` | measured; Q5 = C |
| the commercial chain **customer → service → site** is independent of the device | measured — a sale completes with no hardware |
| a site requires a service; a service requires no site; a site requires no device | measured |
| voucher redemption and accounting ingest must **never** consult uCRM | **FORBIDDEN**, `docs/89`/`102` |
| audit must never be gated on a link | `docs/102` |
| the service link **implies** the customer link | `docs/101`'s coherence rule — *the uCRM service's `clientId` must equal the client linked to that service's customer* — is uncheckable unless both exist |

**So the gate cannot be at customer creation, cannot be only at device
assignment, and cannot be a blanket `NOT NULL`.** All three are ruled out by
evidence. Equipment-first is preserved in every candidate: `device → staged →
shipped` touches no customer at all.

### 5.3 The one thing that is NOT established

Two placements remain, and the schema is not neutral between them:

| | Gate at **creation** | Gate at **activation** |
|---|---|---|
| rule | a Domain-B service **cannot be created** without both links | a service may be created unlinked, but cannot become commercially active without them |
| schema cost | **none** | **a new `mt_services.status` value** — the CHECK permits only `active · suspended · ended`, and `started_at` is `NOT NULL` |
| breaks equipment-first? | only if DishNet sometimes creates the Domain-B service before uCRM has it | no |

> **Adding a status value is a schema decision**, and the same rule applies that
> `docs/101` applied to `UNCLAIMED`: do not create a second source of truth to
> express a state a predicate could express. That materially favours gating at
> creation — **unless** the operator sometimes creates the Domain-B service
> first.

### 5.4 The question that closes U-1

> **Q7 — OPERATOR.** When DishNet sets up a new service for a customer, does the
> **uCRM service record always exist before** the Domain-B service is created —
> or is the Domain-B service sometimes created first, with the uCRM service
> added later?
>
> - **A — uCRM service always first** → gate at **creation**; no schema change.
> - **B — sometimes Domain-B first** → gate at **activation**, and a new service
>   state becomes a real schema decision to take deliberately.
> - **C — both** → as B, and the unlinked window must be bounded and visible.

**U-1 stays OPEN.** This is the same shape as Q5, which closed L-1: one operator
fact, not a preference. **U-5 stays OPEN and must be decided with U-1**, because
the service link is what the coherence rule checks and `mt_services` has no uCRM
column at all today.

---

## 6. I-A — idempotency for the onboarding spine

### 6.1 Both existing mechanisms are tenant-scoped — measured

| Mechanism | Key | Why it cannot serve the spine |
|---|---|---|
| `mt_idempotency` | `PRIMARY KEY (customer_id, key)`, `customer_id NOT NULL REFERENCES mt_customers` | **nothing to key on before the customer exists**; and `dnb_adminwrite` holds **zero table privileges**, while the Admin plane sets no tenant context |
| `mt_intents` | `mt_intents_idem_uq (customer_id, idempotency_key) WHERE idempotency_key IS NOT NULL` | same tenancy dependency. `IntentQueue::enqueue` documents it: *"Customer-scoped: call inside `TenantContext::run()`"*, and its lookup `WHERE idempotency_key = ?` **relies on RLS to scope itself** |

> **The project has two idempotency mechanisms and both assume a tenant.** The
> spine needs a third shape. This is structurally the same conclusion `docs/89`
> reached for the attempt store, for the same reason: **a check that requires
> tenant data cannot run in the case that has none.**

**Also measured:** of the three production `enqueue` call sites, only
`voucher.publish` passes a key. **`voucher.revoke` and `session.disconnect` pass
none.** Consistent with `docs/103` — `POST /me/vouchers` is the only idempotent
route. *(Recorded as evidence; not in scope to fix here.)*

> **CORRECTED by `docs/108` §0 and §4.** This paragraph originally ended *"so a
> retried revoke or disconnect enqueues a second intent that reaches a router."*
> **That clause was an overstatement and is withdrawn.** `bin/worker.php` binds
> **`NullDelivery`**, so no intent of any kind reaches a router today; the router
> consequence is latent until F6-B. And the split is **three-way, not two-way**:
> `voucher.revoke` *is* retry-safe, by a state guard inside its `UPDATE`
> (`WHERE id = ? AND state IN ('unused','active')`) that returns 404 before the
> enqueue is reached. **Only `session.disconnect` is unguarded**, and its
> present-day cost is a duplicate audit row, not a duplicated router action.

### 6.2 Per-operation design

| # | Operation | Natural key | Actor | Scope | Before customer exists? | Replay | Conflict | Audit | Mechanism |
|---|---|---|---|---|---|---|---|---|---|
| 1 | **customer creation** | **none** — `name` not unique, `radius_ref` generated by the insert | staff | global | **YES — by definition** | return the same `customer_id` | same key, different digest → refuse | W-1 writes one; **a replay must NOT write a second** | **table** (non-tenant) |
| 2 | **principal creation** | `phone` **when non-NULL** | staff | global (phone is globally unique) | no | phone exists + digest matches → return existing | phone exists + digest differs → **refuse** (P-B) | W-1 shape | **unique constraint**, plus **table** for the NULL-phone case |
| 3 | **uCRM customer linking** | **`ucrm_client_id`** — already `UNIQUE` | staff | global | no | same pair twice → no-op | different client onto a linked customer → refuse; **relink is its own audited operation** | audited with previous + new + reason (`docs/101`) | **unique constraint** |
| 4 | **service creation** | **none whatsoever** — `customer_id` + a `kind` with one legal value | staff | customer | no | return the same `service_id` | same key, different digest → refuse | W-1 shape | **table** — mandatory; the worst case of the eight |
| 5 | **uCRM service linking** | the uCRM **service id**, *if* U-5 adds a unique column | staff | global | no | same pair twice → no-op | **coherence failure** (`clientId` ≠ linked client) → refuse | audited | **unique constraint** — *does not exist yet* (U-5) |
| 6 | **site creation** | **none** — `(customer, service, name)`; `name` not unique | staff | customer, **derived from the service** | no | return the same `site_id` | same key, different digest → refuse | W-1 shape | **table** |
| 7 | **device assignment** | the **device id** plus the requested target | staff | global (device identity is estate-wide) | **the device may pre-exist any customer** | identical `(customer, site, name)` → **no-op: no audit row, no `claimed_at` re-stamp** | different target → **a reassignment, not a retry** — requires explicit intent | one row per *actual* change | **domain-specific invariant** |
| 8 | **provisioning / delivery intents** | `idempotency_key` | principal or staff | customer | no | existing intent returned | — | none today (`docs/103`) | **unique constraint** — exists, `(customer_id, idempotency_key)`, tenant-scoped |

### 6.3 What the table forces

- **Three different mechanisms, deliberately.** A unique constraint where a
  natural key exists (3, 5, 8, and 2's common case); a domain-specific invariant
  where the operation is a state assertion (7); a **table** only where there is
  genuinely no natural key (1, 4, 6, and 2's NULL-phone case).
- **The table must be non-tenant** and reachable by `dnb_adminwrite`, keyed
  `(endpoint, key)` with a `request_digest` — the same shape `mt_idempotency`
  already uses (*"so the same key with a different body is caught"*) minus the
  tenancy.
- **A unique violation is not a replay** until the stored digest matches.
- **A replay must not write a second audit row.** W-1 makes the audit row
  unskippable *inside the function*, so replay detection has to happen **before**
  the function body runs, not after.
- **Operation 7 is the trap.** It looks the most idempotent and is the only one
  where a repeated call with *different* arguments is a legitimate different
  operation.

**Not authorized to implement.**

---

## 7. Customer-first and equipment-first — where they converge

```
CUSTOMER-FIRST                              EQUIPMENT-FIRST

uCRM customer                               device registration
      │                                           │
      ▼                                           ▼
Domain-B customer                           staging
      │                                           │
      ▼                                           ▼
principal                                   shipping
      │                                           │
      ▼                                           │   ← no customer anywhere above
uCRM service                                      │
      │                                           │
      ▼                                     (uCRM customer)
Domain-B service   ← U-1's candidate gate         │
      │                                           ▼
      ▼                                     Domain-B customer → principal
    site                                          │
      │                                           ▼
      ▼                                    uCRM service → Domain-B service → site
device registration → staging → shipping          │
      │                                           │
      └───────────────┬───────────────────────────┘
                      ▼
            ╔═══════════════════════╗
            ║   mt_device_assign    ║   ← the ONLY convergence point
            ╚═══════════════════════╝
                      │
                      ▼
                provisioning
```

### The convergence point, stated precisely

> **`mt_device_assign` is the only operation that takes both a device and a
> customer/site.** It is therefore the single point at which the network chain
> and the commercial chain meet — in **both** journeys.

**Convergence is not the same as a gate**, and conflating them was already
refuted: `docs/102` measured that `service → site → plan → voucher` completes a
sale with **nothing assigned**, so `mt_device_assign` is where the lifecycles
*meet* and **not** where commercial authority is established (§5).

### The two chains are independent, and the journeys differ only in order

| | Commercial chain | Network chain |
|---|---|---|
| operations | customer → service → site | register → stage → ship |
| needs the other? | **no** — a sale completes with no hardware | **no** — inventory needs no customer |
| identical in both journeys? | **yes** | **yes** |

> **The two journeys are not two designs. They are one design, entered from
> either end.** That is why no placeholder customer is ever needed.

**Preserved, unchanged:** *network inventory may exist without commercial
identity*; `UNCLAIMED` stays the predicate `customer_id IS NULL` and never a
device state; **no fake, pending or provisional customer is ever created** to
satisfy a foreign key.

---

## 8. The production census gate

**Read-only. Uses the existing privileged read path — `dnb_adminapi` calling the
`mt_admin_*()` projections, whose `dnb_def_admin` `USING (true)` SELECT policies
already span all tenants. No superuser. No `BYPASSRLS` role** — one created for a
census would outlive it.

### 8.1 Data evidence

| # | Measure | Blocks? |
|---|---|---|
| 1 | services total | context |
| 2 | sites total | context |
| 3 | NULL `mt_sites.customer_id` | must be impossible ⇒ **wrong database** |
| 4 | NULL `mt_sites.service_id` | must be impossible ⇒ **wrong database** |
| 5 | **site/service customer mismatches** | **YES — the blocker** |
| 6 | orphaned services — no site references them | no; **expected and legal** |
| 7 | orphaned sites — `service_id` resolves to nothing | investigate |
| 8 | duplicate `(customer_id, service_id)` pairs in `mt_sites` | context — legal today (several sites may share a service) |
| 9 | **rows that would be rejected by O-1** | **identical to 5** — reported as the explicit pre-flight of the constraint |

### 8.2 Schema evidence — equally required

The migration assumes a schema. **Production may not be at that schema**, and no
data count would reveal it:

| # | Measure | Why |
|---|---|---|
| 10 | migrations applied, and the latest filename | development is at **21**, latest `021_admin_services_and_voucher.sql`. A production schema behind that has different constraints |
| 11 | existing FK constraints on `mt_sites` and `mt_devices` | confirms W-2 is present and that O-1's constraint is genuinely absent |
| 12 | existing UNIQUE constraints on `mt_services` | confirms only the PK exists, so step 1 is needed and is not a duplicate |
| 13 | current indexes on both tables | the index step 1 builds must not already exist under another name |
| 14 | is migration 020 applied? | **it is not authorized for production**; if absent, W-2 is absent, and O-1's sibling invariant is missing too |

### 8.3 Controls — mandatory

The census reports **`INDETERMINATE`**, never `0 mismatches`, unless:

- counts 1 and 2 are **both non-zero** — a zero-mismatch result over a zero-row
  read measures nothing;
- `current_user`, `current_database()` and `version()` are printed;
- the method is stated — `projections` or, if the schema predates 021,
  `superuser`, **never silently**;
- a deliberate negative control returns rows for a known customer.

### 8.4 Output

One record: timestamp, database, role, method, counts 1–14, every control's
result, and one verdict — **`CLEAR` · `BLOCKED (n)` · `INDETERMINATE`**. A
`BLOCKED` verdict lists the offending pairs by opaque identifier so a person can
decide each one. **No customer names, no site names, no locations.**

**The operator runs it. This session cannot reach production** — `docs/79`
remains the handoff and **production data state is NOT ESTABLISHED**.

---

## 9. Decision status

| Decision | Status | Evidence | Implementation gate |
|---|---|---|---|
| **O-1** — site/service customer integrity | **CLOSED as a design** — composite FK, no `MATCH FULL`, no CHECK, no `NOT VALID` | `docs/105` §1 (defect, controlled); `docs/106` §1–§7 (DDL demonstrated in a rolled-back transaction, zero residue; locks measured per statement) | census **CLEAR** + explicit approval; two separate operational gates |
| **P-B** — phone uniqueness | **CLOSED — remains globally UNIQUE** | `mt_auth_issue_code` uses a non-`STRICT` `SELECT … INTO`, measured to return the first row silently; `STRICT` raises `P0003` | none — **nothing changes.** Binding on the principal writer: duplicate phone ⇒ refuse |
| **P-C** — principal reassignment | **CLOSED — PROHIBITED** | `mt_auth_sessions` carries its own `customer_id`; `mt_auth_resolve_token` returns the session's copy; `SET NULL` attribution erasure (`docs/100`) | none — **no operation is to be built.** Lifecycle is disable + create new |
| **S-A** — service migration | **CLOSED — PROHIBITED as an ordinary operation** | no writer, no document, `customer_id NOT NULL`; O-1 + `ON UPDATE NO ACTION` makes it constraint-refused | none. If ownership changes: end the service, create a new one |
| **U-1** — where the uCRM customer link becomes mandatory | **OPEN** — reduced to **Q7** | §5.2 rules out customer-creation, device-assignment-only, and blanket `NOT NULL`; §5.3 shows the schema is not neutral between creation-gate and activation-gate | **Q7 (operator)**; then approval |
| **U-5** — the uCRM service link | **OPEN — decide WITH U-1** | `mt_services` has no uCRM column at all; the coherence rule needs both links | Q7 + approval |
| **I-A** — idempotency | **DESIGNED, open for approval** | both existing mechanisms measured tenant-scoped; per-operation table in §6.2 | approval; the non-tenant store is a schema addition |
| **C10** — is the Reseller the PWA user? | **OPEN since `docs/47`** | ARCH, *"Highest. Rebuilds permission logic and possibly a surface"*; C6 and C16 attached | **not to be solved by weakening P-B** |
| **U-2** — `ucrm_client_id NOT NULL` | **OPEN** | 18 `ON DELETE RESTRICT` FKs; an unlinked customer with history cannot be deleted | **E-2** |
| **U-6** — authoritative source for the OTP phone | **OPEN** | phone is the authentication key; a uCRM contact edit would rotate a credential | approval |
| **U-9** — does a uCRM suspension suspend the service? | **OPEN** | `status` has `suspended` and no writer | approval |
| **C6 / C16** — owner/operator roles; where staff records live | **OPEN since `docs/47`** | schema comment `-- kind: owner \| operator. C6/C16 OPEN` | PWA freeze. **A writer may STORE `kind`, never branch on it** |
| **W-4** — staff identity | **OPEN** | `DenyAllIdentity` is still the production binding | approval — nothing reachable over HTTP until then |
| **E-2** — the production census | **NOT RUN** | this session cannot reach production | the operator; `docs/79` |

**Nothing above authorizes an installation.** RC1 remains *installable with
conditions* and **must not be installed into the live UISP/uCRM or production
environment**: the production migration, the voucher activation path and the
onboarding identity model are each still short of their gates.

---

**Stopping here. Suite unchanged: 1,599 assertions, 27 suites. No migration, no
schema modification, no production connection, no application code, no
test-fixture change. Four decisions closed, four questions left open on purpose,
one reduced to a single operator question.**
