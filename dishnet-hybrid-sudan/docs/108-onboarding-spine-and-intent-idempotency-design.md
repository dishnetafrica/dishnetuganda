# 108 — Onboarding spine and intent idempotency

**Design only. No migration, no schema change, no production access, no
application code, no fixture change.** Nothing here is authorized to build.

---

## 0. A correction to `docs/107`

`docs/107` §6 recorded that `voucher.revoke` and `session.disconnect` carry no
idempotency key, and concluded *"a retry enqueues a second intent that reaches a
router."*

> **The last clause was an overstatement, and is withdrawn.** Measured:
> `bin/worker.php` — the only production construction of `IntentWorker` — binds
> **`NullDelivery`**, whose `deliver()` returns
> `retryable('no delivery path is configured')` and whose `confirm()` returns
> `false`. `RouterOsDelivery` is not referenced by `Runtime/Bindings.php` at all;
> `Bindings::defaults()` is `NullDelivery` + `NullPublisher`, and a real binding
> requires `DN_ALLOW_REAL_BINDINGS`.

**Today no intent of any kind reaches a router.** The defect is real but its
consequence is smaller and different from what `docs/107` claimed — §4 states it
exactly. The router consequence is **latent until F6-B binds a real adapter**.

Recorded rather than quietly fixed, per the project's own rule: an overstated
risk is as much a measurement failure as an understated one.

---

## 1. Q7 / U-1 — evidence searched, decision NOT taken

> **Q7.** When DishNet sets up a new service, does the uCRM service record always
> exist before the Domain-B service is created, or is the Domain-B service
> sometimes created first?

### 1.1 What the repository does contain

The sibling plugin `dishnet-hybrid-sudan` runs on the DishNet server and models
the same problem. Measured from `migrations/020_lte_subscribers.sql` and
`026_lte_financial_ledger.sql`:

```sql
CREATE TABLE lte_subscribers (...)        -- NO uCRM column of any kind

CREATE TABLE lte_service_links (
  lte_subscriber_id INTEGER NOT NULL,     -- the plugin's own entity
  ucrm_client_id    INTEGER NOT NULL,     -- the uCRM side
  linked_by         INTEGER DEFAULT NULL, -- staff who created the link
  linked_at         TEXT DEFAULT (datetime('now')),
  UNIQUE(lte_subscriber_id, ucrm_client_id)
);
```

Two things follow, and only two:

1. **The local entity carries no uCRM reference at all**, so an `lte_subscribers`
   row **can exist unlinked**. Linking is a *separate, later, attributed act* —
   `linked_by` and `linked_at` exist precisely because the link happens at a
   different time from the creation.
2. **Despite its name, `lte_service_links` links to a uCRM *client*, not a uCRM
   *service*.** There is no uCRM service reference anywhere in the sibling
   plugin either.

### 1.2 Why this does not answer Q7

| | |
|---|---|
| It shows local-entity-first is **representable and deliberate** in a running DishNet system | ✓ |
| It shows DishNet's engineering has **chosen** a shape that tolerates an unlinked local entity | ✓ |
| It says anything about **uCRM services** | ✗ — the plugin links clients only |
| It describes the **Uganda MikroTik** workflow | ✗ — it is the Sudan LTE business |
| It is evidence of **workflow** rather than of **schema preference** | ✗ |

> **Q7 asks what DishNet's people actually do. A schema in a different product
> line is evidence about a previous design choice, not about the Uganda MikroTik
> sales workflow.** Treating it as an answer would be exactly the inference this
> project forbids — reading a workflow off a table definition.

### 1.3 Status

> **U-1 and U-5 remain OPEN.** The repository has been searched and **cannot**
> answer Q7. It is an operator question, in the same class as Q5, and must be
> answered by a person who knows the sales process.

What the evidence *does* do is raise the prior for **B/C** — sometimes Domain-B
first — because the one DishNet precedent that exists chose that shape
deliberately. **That is a prior, not a decision, and it must not be treated as
one.** If the answer is B or C, the new `mt_services` state that implies is a
schema decision to be taken explicitly (`docs/107` §5.3).

---

## 2. The complete onboarding spine

Seven entities across two systems. `[uCRM]` is external; everything else is
Domain B.

```
CUSTOMER-FIRST                                 EQUIPMENT-FIRST

[uCRM] client created                          mt_device_register
        │                                              │
        ▼                                              ▼
mt_customer_create                             mt_device_stage
        │                                              │
        ▼                                              ▼
  link: customer ⇄ [uCRM] client               mt_device_ship
        │                                              │
        ▼                                              │  ◀── no customer
mt_principal_create                                    │      anywhere above
        │                                              │
        ▼                                       [uCRM] client created
[uCRM] service created                                 │
        │                                              ▼
        ▼                                      mt_customer_create
mt_service_create  ◀── U-1's candidate gate            │
        │                                              ▼
        ▼                                        link customer ⇄ client
  link: service ⇄ [uCRM] service                       │
        │                                              ▼
        ▼                                      mt_principal_create
mt_site_create                                         │
        │                                              ▼
        ▼                                      [uCRM] service → mt_service_create
mt_device_register → stage → ship                      │      → link → mt_site_create
        │                                              │
        └──────────────────┬───────────────────────────┘
                           ▼
                 ╔═══════════════════════╗
                 ║   mt_device_assign    ║   ← the ONLY convergence point
                 ╚═══════════════════════╝
                           │
                           ▼
                     provisioning intents
                           │
                           ▼
                  plans → vouchers → sale
```

### 2.1 The convergence, stated exactly

**`mt_device_assign` is the only operation that takes both a device and a
customer/site.** It is therefore the single point at which the network chain and
the commercial chain meet — identically in both journeys.

**Convergence is not a gate.** `docs/102` measured that
`service → site → plan → voucher` completes a sale with nothing assigned, so
`mt_device_assign` is where the lifecycles *meet* and **not** where commercial
authority is established.

### 2.2 The two chains are independent

| | Commercial chain | Network chain |
|---|---|---|
| operations | customer → service → site | register → stage → ship |
| needs the other? | **no** — a sale completes with no hardware | **no** — inventory needs no customer |
| identical in both journeys? | **yes** | **yes** |

> **The two journeys are not two designs. They are one design, entered from
> either end.** The only difference is *when* the network chain starts. That is
> why **no placeholder customer is ever needed**, and why `UNCLAIMED` stays the
> predicate `customer_id IS NULL` rather than a device state.

---

## 3. Idempotency, per operation

| # | Operation | Natural key | Scope | Actor | Preconditions | Replay | Conflict | Audit | **Tenant context at execution?** |
|---|---|---|---|---|---|---|---|---|---|
| 1 | **customer creation** | **none** — `name` not unique; `radius_ref` generated by the insert | global | staff | a non-blank name | return the same `customer_id` | same key, different digest → **refuse** | W-1 writes one; **replay writes none** | **NO** — and no customer exists yet |
| 2 | **principal creation** | `phone`, **only when non-NULL** | global (phone is globally unique) | staff | customer exists | phone exists + digest matches → return existing | phone exists + digest differs → **refuse, never upsert** (P-B) | W-1 shape | **NO** |
| 3 | **uCRM customer link** | **`ucrm_client_id`** — already `UNIQUE` | global | staff | customer exists; uCRM client exists | same pair → no-op | different client onto a linked customer → **refuse**; relink is its own audited operation | audited with previous + new + reason | **NO** |
| 4 | **service creation** | **none whatsoever** — `customer_id` + a `kind` with one legal value | customer | staff | customer exists (+ links, if U-1 says so) | return the same `service_id` | same key, different digest → **refuse** | W-1 shape | **NO** |
| 5 | **uCRM service link** | the uCRM **service id** — *no column exists yet* (U-5) | global | staff | service exists; **coherence**: the uCRM service's `clientId` equals the client linked to that service's customer | same pair → no-op | coherence failure → **refuse** | audited | **NO** |
| 6 | **site creation** | **none** — `(customer, service, name)`; `name` not unique | customer, **derived from the service** | staff | service exists | return the same `site_id` | same key, different digest → **refuse** | W-1 shape | **NO** |
| 7 | **device assignment** | the **device id** + the requested target | global (device identity is estate-wide) | staff | device exists; site's customer matches (W-2) | identical `(customer, site, name)` → **no-op: no audit row, no `claimed_at` re-stamp** | different target → **a reassignment, not a retry** | one row per *actual* change | **NO** |
| 8 | **provisioning / delivery intents** | `idempotency_key` | customer | principal or staff | customer exists | existing intent returned | — | none today | **YES** — `enqueue` runs inside `TenantContext::run` |

### 3.1 What the table forces

- **Seven of the eight have no tenant context at execution.** Only operation 8
  does, and it is the one that already has a mechanism.
- **Four have no natural key at all** — 1, 4, 6, and 2's NULL-phone case.
- **Three have a genuine natural key already enforced by a unique constraint** —
  3, 8, and 2's common case. **They need no table.**
- **One is a state assertion, not a creation** — 7. Its idempotency is a
  domain-specific invariant, and it is the trap: it looks the most idempotent
  and is the only one where repeating with *different* arguments is a legitimate
  different operation.

---

## 4. The intent asymmetry — measured, and it is three-way

`docs/107` described a two-way split. Measured, it is three-way, and the third
case is safe for a reason nobody designed as idempotency.

| Operation | Idempotency key | Guard | **Retry-safe today?** |
|---|---|---|---|
| `voucher.publish` | **yes** | — | **Safe.** `enqueue` returns the first intent |
| `voucher.revoke` | **no** | **yes — a state guard in the SQL** | **Safe, incidentally** — see below |
| `session.disconnect` | **no** | **none** | **NOT safe** — duplicate intent **and** duplicate audit row |

### 4.1 `voucher.revoke` is safe by a state guard, not by a key

```php
// VoucherService::revoke
UPDATE mt_vouchers SET state = 'revoked', revoked_at = now()
 WHERE id = ? AND state IN ('unused','active') RETURNING *
```

The handler runs `revoke()` **first**, and returns `Response::notFound()` when it
yields `null` — **before** reaching `enqueue` or the audit write. A second
revoke of the same voucher matches no row, so:

- no second state transition;
- **no second intent**;
- **no second audit row**.

> **It is retry-safe — but by accident of where the guard sits, not by design.**
> Two consequences worth recording: the replay answers **404**, so a caller
> cannot distinguish *"already revoked by my own retry"* from *"no such
> voucher"*; and the safety depends on statement order in the handler, which any
> future edit could silently reverse.

### 4.2 `session.disconnect` has no guard

```php
$s = (new SessionService($db))->find($id);      // a READ — always succeeds
if ($s === null) { return Response::notFound(); }
$intent = (new IntentQueue($db))->enqueue(...); // no idempotency key
(new AuditLog($db))->record(...);               // unconditional
```

Every retry proceeds. **Two intents, two audit rows**, unconditionally.

### 4.3 What that costs today — and what it will cost

With `NullDelivery` bound (§0), a duplicate intent is delivered nowhere: it is
retried to `max_attempts` and then fails. **So today the cost is a duplicate
audit event and wasted queue capacity — not a duplicated router action.**

> **The router consequence is latent and arrives with F6-B.** When
> `RouterOsDelivery` is bound, a duplicated `session.disconnect` becomes a real
> duplicated action against a real router.

**Not fixed here, per instruction.** Named as a blocker in §8.

---

## 5. The ordering rule

> **RULE I-1 — idempotency detection must occur BEFORE the mutating, audited
> operation executes. A replay must not create a second audit event.**

This is forced by W-1, not chosen. W-1 makes the audit row unskippable **inside**
the provisioning function, written by `mt_audit_write()` in the same
transaction. So a replay check placed *after* the call, or *inside* it after the
mutation, cannot prevent a duplicate audit row — the row is already written.

Consequences that bind the writers:

- the replay check is the **first** thing the function does, before any mutation
  and before `mt_audit_write()`;
- a replay returns the **stored result**, and writes **nothing** — no audit row,
  no timestamp re-stamp, no queue row;
- `voucher.revoke` already satisfies this **by placement** (§4.1), which is why
  it is safe; `session.disconnect` violates it, which is why it is not;
- **audit evidence is a record of acts that happened.** A duplicate audit row for
  an act that happened once is not a harmless extra — it is a false record, and
  `mt_audit_log` is append-only by trigger, so it cannot be corrected afterwards.

---

## 6. Where the existing mechanism suffices

| Mechanism | Key | Sufficient for |
|---|---|---|
| `mt_idempotency` | `PRIMARY KEY (customer_id, key)`, `customer_id NOT NULL`, FORCE RLS | **none of operations 1–7** |
| `mt_intents_idem_uq` | `(customer_id, idempotency_key) WHERE NOT NULL` | **operation 8 only** — which already uses it |
| `mt_customers_ucrm_client_id_key` | `UNIQUE (ucrm_client_id)` | **operation 3** — no table needed |
| `mt_principals_phone_uq` | `UNIQUE (phone) WHERE NOT NULL` | **operation 2, common case** — no table needed |

**Two independent reasons the tenant-keyed mechanism cannot serve operations 1–7:**

1. **Operation 1 has no `customer_id` to key on**, by definition — the same
   structural problem `docs/89` solved for the attempt store.
2. **`dnb_adminwrite` holds zero table privileges** —
   `has_table_privilege(…,'mt_idempotency','INSERT') = false` — and the Admin
   plane sets no tenant context, so the policy would evaluate
   `customer_id = NULL` even if the privilege existed.

> **A non-tenant mechanism is required for operations 1, 4, 6 and the NULL-phone
> case of 2** — the four with no natural key. Operations 3, 5 and 7 need **no
> table at all**: a unique constraint or a domain invariant is stronger, because
> it cannot be bypassed by a caller that omits the key.

Shape, forced rather than chosen: keyed `(endpoint, key)`, carrying a
`request_digest` — the same discipline `mt_idempotency` already documents,
*"so the same key with a different body is caught"* — reachable by
`dnb_adminwrite`, and **not** tenant-scoped.

---

## 7. Writer order

**Not authorized to implement.** Dependencies, in order:

| # | Step | Why here |
|---|---|---|
| **0a** | **O-1** — census (gate 1), operator decision, migration (gate 2) | the site writer would otherwise make a known cross-customer defect reachable |
| **0b** | **the non-tenant idempotency store** | **before the first writer, not after.** A duplicate customer cannot be deleted — 18 `ON DELETE RESTRICT` FKs (`docs/100`) — so shipping customer creation without it creates unremovable garbage |
| 1 | `mt_principal_create` / `mt_principal_disable` | grants the login; highest risk; P-B and P-C bind it; **stores `kind`, never branches on it** (C6) |
| 2 | `mt_service_create` | the container everything hangs off; **U-1's candidate gate**, so it cannot be written before Q7 is answered |
| 3 | `mt_site_create` | requires 0a; derives the customer from the service |
| 4 | a production caller for `mt_customer_create` | the function exists and is audited; only the route is missing (W-4) |
| 5 | the uCRM link writers (operations 3 and 5) | require U-1 **and** U-5, and U-5 requires a column that does not exist |
| 6 | the intent replay fix (§4) | independent of the spine; **must precede F6-B** |

> **Step 2 cannot start before Q7 is answered**, because the answer determines
> whether the gate is in the function or in a service state that does not yet
> exist. That makes Q7 the critical path, not O-1.

---

## 8. Gate table

| Item | Status | Evidence required | Implementation dependency |
|---|---|---|---|
| **O-1** | **CLOSED as a design** (`docs/106`/`107`) | production census verdict **CLEAR**; **E-2** | two operator gates: census → decision → migration. Blocks `mt_site_create` |
| **P-B** | **CLOSED — phone stays globally UNIQUE** | none — measured and settled | binds the principal writer: duplicate phone ⇒ **refuse**. Blocks nothing |
| **P-C** | **CLOSED — reassignment PROHIBITED** | none | no operation to be built. Lifecycle is disable + create new |
| **S-A** | **CLOSED — service migration PROHIBITED** | none | becomes constraint-enforced once O-1 lands |
| **U-1** | **OPEN** | **Q7 — operator.** The repository was searched and cannot answer it (§1) | **blocks `mt_service_create`, and therefore the whole commercial chain** |
| **U-5** | **OPEN — decide WITH U-1** | Q7 + the live `clients/services` shape | blocks the uCRM service link writer; needs a column that does not exist |
| **I-A** | **DESIGNED, open for approval** (§3, §6) | approval of the non-tenant store | **blocks every writer** — it is step 0b, not a follow-up |
| **C10** | **OPEN since `docs/47`** | product/identity decision — *"Highest. Rebuilds permission logic"* | **may not be solved by weakening P-B.** Blocks nothing immediately |
| **Intent replay** — `session.disconnect` | **NEW BLOCKER, unfixed** | none — measured (§4.2) | **must be fixed before F6-B.** Harmless today only because `NullDelivery` is bound |
| **U-2** | **OPEN** | **E-2** | blocks any `NOT NULL` on `ucrm_client_id` |
| **W-4** | **OPEN** | approval | blocks every HTTP route for the spine |
| **E-2** | **NOT RUN** | the operator; `docs/79` | blocks O-1's migration and U-2 |

---

**Stopping here. Suite unchanged: 1,599 assertions, 27 suites. No migration, no
schema change, no production access, no application code, no fixture change.
One `docs/107` overstatement withdrawn, one new blocker named, U-1 left open
because the repository cannot answer it.**
