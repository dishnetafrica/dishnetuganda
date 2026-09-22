# 112 — The Domain-B onboarding spine: final design

**Design only. No migration, no schema change, no production access, no
application code, no fixtures.** Nothing here is authorized to build.

`docs/111` established the dependency direction:

```
Domain-B onboarding  →  Domain-B API  →  uCRM bridge      (not the reverse)
```

This resolves the Domain-B side the bridge waits on.

---

## 0. A finding that bounds the whole design — **A-1**

`docs/84` **F-3** already records that `dnb_admin` holds
`SELECT, INSERT, UPDATE, DELETE` on **all tables** from migration 015, with
`ALTER DEFAULT PRIVILEGES` so new tables inherit it. F-3 states the consequence
as a hypothetical: *"An Admin write route connecting as `dnb_admin` **could**
therefore write any table directly."*

**Three things extend it, proved by execution as `dnb_admin` under RLS, rolled
back:**

| | Test | Result |
|---|---|---|
| **C** | *control* — can this session see what it should? | customers `1`, audit rows `8` ✓ |
| **A-1a** | `INSERT INTO mt_audit_log (… 'DIRECT-WRITE-PROBE' …)` | **`INSERT 0 1`** — **the audit trail is forgeable** |
| **A-1b** | `INSERT INTO mt_customers (name) VALUES (…)` | **refused** — *"new row violates row-level security policy"* |
| **A-1c** | `has_table_privilege('dnb_worker','mt_audit_log','INSERT')` | **true** — F-3 names only `dnb_admin`; **migration 015 line 73 grants both** |

**What this means, stated exactly:**

- **F-3 reaches `mt_audit_log`, not just business tables.** A role with the
  blanket grant can write an audit row naming **any actor and any action**, and
  `mt_audit_log` is **append-only by trigger** — so a forged row **cannot be
  removed or corrected**. That is worse than a forgeable business write, which
  can at least be reversed.
- **RLS still bounds it to the caller's own tenant** (A-1b). So this is an
  **attribution and integrity** problem, **not** a tenancy breach. Do not
  overstate it.
- **It is latent** in the same way O-1 was: no production route connects as
  `dnb_admin`. `Database::adminApi()` and `::adminWrite()` are *"separate from
  `admin()` on purpose"*, and the code says so in two places.

> **Binding consequence for this design: no new writer may be reachable by
> `dnb_admin`, and the spine must not add a route that connects as it.**
> W-1's *"no HTTP role may write an audit row directly"* is true of
> `dnb_adminwrite` and `dnb_adminapi` — **it is not true of `dnb_admin` or
> `dnb_worker`.**

**Also recorded:** `src/Api/AdminRoutes.php:103` comments that *"dnb_admin holds
EXECUTE and no table privilege whatsoever"*. That is true of **`dnb_adminapi`**,
which the route actually uses, and **false of `dnb_admin`**, which it names. The
**behaviour is correct; the comment is wrong**, in a security-critical place.

---

## 1. The nine operations

Every writer below follows the W-1 pattern: `SECURITY DEFINER`, owned by a
definer role, `SET search_path = public, pg_temp`, `EXECUTE` to
**`dnb_adminwrite` only**, one `mt_audit_write()` row **inside the same
transaction**, and the **actor as a parameter from the identity boundary** —
never a session GUC, never a request field.

### 1.1 Domain-B operator / customer creation

```
mt_customer_create(p_name text, p_created_by text) RETURNS uuid     -- EXISTS
```

| | |
|---|---|
| status | **the function exists and is audited; it has NO production caller** |
| needs | a non-blank name + an actor. **Nothing else** — no uCRM link, service, site, router, phone or voucher |
| `radius_ref` | supplied by a column DEFAULT |
| idempotency | **no natural key** — `name` is not unique. Caller-supplied key in the **non-tenant** store (§4) |
| why it matters | a duplicate customer **cannot be deleted** — 18 FKs `ON DELETE RESTRICT` |

**What is missing is the caller, not the function.** A new signature is **not**
required unless the uCRM link is to be set at creation — and `docs/110`
withdrew that requirement.

### 1.2 Principal creation

```
mt_principal_create(p_customer uuid, p_kind text, p_display_name text,
                    p_phone text, p_email text, p_actor text) RETURNS uuid
mt_principal_disable(p_principal uuid, p_actor text, p_reason text)
```

| | |
|---|---|
| status | **NO writer of any kind** — simulator only |
| **P-B binds it** | `phone` is **globally UNIQUE**. A duplicate phone is a **REFUSAL**, never an upsert and never a silent selection |
| **C6/C16 bind it** | it may **STORE** `kind` (`owner`/`operator`); it must **NOT branch on it** until C6 closes |
| **P-C binds it** | there is **no reassignment operation**. `customer_id` is set once |
| phone NULL | permitted — and such a principal **cannot authenticate at all**, since `mt_auth_issue_code` requires `phone = ? AND status='active'`. That is U-6, open |
| disable | **closes live sessions immediately** — `mt_auth_resolve_token` re-reads `p.status` on every resolution |
| idempotency | `phone` **is** the natural key when non-NULL; **no key at all** when NULL → caller-supplied key |

> **This is the highest-risk writer in the spine**: it is the only operation that
> grants a human being a login.

### 1.3 The operator/customer authentication boundary

**Unchanged, and nothing in this design touches it.**

```
phone → mt_auth_issue_code → mt_auth_verify_code → mt_auth_create_session
      → token → mt_auth_resolve_token → SET LOCAL app.customer_id → RLS
```

| Property | Consequence |
|---|---|
| `p.status` is re-read **live** on every resolution | disable is immediate |
| `s.customer_id` is the **session's own snapshot** | reassignment would not take effect — hence **P-C** |
| the tenant resolves from a **globally unique phone** | hence **P-B** |

**Authentication is not being redesigned.** The spine only *creates* principals;
it changes no part of the OTP path.

### 1.4 Service creation

```
mt_service_create(p_customer uuid, p_kind text, p_actor text) RETURNS uuid
```

| | |
|---|---|
| status | **NO writer** — simulator only |
| `kind` | the CHECK permits **exactly one value**, `mikrotik_hotspot` |
| **uCRM** | **NOT required** (`docs/110` §1, withdrawing the earlier proposal) |
| idempotency | **no natural key whatsoever** — `customer_id` + a `kind` with one legal value. **The worst case of the nine**; a caller-supplied key is mandatory |
| open | whether a *commercially-inactive* state is needed is **Q7/U-1**, and adding one is a **schema decision** (`docs/107` §5.3) |

### 1.5 Site creation

```
mt_site_create(p_service uuid, p_name text, p_location text, p_actor text)
       ▲  there is NO p_customer parameter, by construction
```

**Derive, never accept.** `customer_id` is read from the service row, so the
forgery is **unrepresentable** rather than rejected. Three layers, weakest last:
**composite FK (O-1) → the function derives → the route carries no customer.**

> **Blocked on O-1.** A production site writer would make a known cross-customer
> integrity defect reachable. §5.

### 1.6 uCRM customer linkage — optional, later

```
mt_customer_ucrm_link(p_customer uuid, p_ucrm_client integer,
                      p_actor text, p_reason text)
mt_customer_ucrm_unlink(p_customer uuid, p_actor text, p_reason text)
```

**Not a prerequisite for anything in §2.** An unlinked operator is a fully
functioning operator. Audited with **previous and new** relationship plus a
reason. **Never inferred per request, never from a phone number.**

> **Cardinality is NOT assumed.** `ucrm_client_id` is `integer UNIQUE` today, so
> the schema already enforces 1:1 — and **C10 must be decided before
> cardinality**, since a reseller holding several venues is exactly the case that
> would break it (`docs/111` §5.1). **This function is designed but its
> cardinality is open.**

Idempotency: **the existing UNIQUE is the mechanism** — no table needed.

### 1.7 uCRM service linkage — deferred

**No column exists**, and `docs/109` found no uCRM HotSpot service plan evidenced
anywhere. **U-5 stays deferred**: designing a cardinality for a relationship
whose far side is not known to exist would be inventing both ends.

### 1.8 Device assignment

```
mt_device_assign(p_device, p_customer, p_site, p_name, p_actor)   -- EXISTS
```

| | |
|---|---|
| status | **built, audited, no route bound** |
| W-2 | `(site_id, customer_id) → mt_sites(id, customer_id)` binds it, and the function's own comment says the invariant is *"NOT checked here… it is a constraint"* |
| idempotency | **a domain-specific invariant, not a table.** Identical `(customer, site, name)` → **no-op: no audit row, no `claimed_at` re-stamp**. **Different arguments are a reassignment, not a retry** |
| trap | it returns **`NULL`** rather than raising when the device does not exist — a retry handler must not read that as success |

### 1.9 Provisioning / delivery intent

| | |
|---|---|
| status | **built**; `mt_intents_idem_uq (customer_id, idempotency_key)` exists |
| tenant context | **YES** — the only one of the nine that has one at execution |
| idempotency | the existing unique constraint suffices |
| **blocker** | **`session.disconnect` carries no key and no guard.** Per instruction it is **NOT fixed here**; it remains a blocker before F6-B (`docs/108` §4) |

---

## 2. The two flows

### 2.1 Customer-first — required vs optional

```
mt_customer_create          REQUIRED — the tenant root
      │
      ▼
mt_principal_create         OPTIONAL for network operation;
      │                     REQUIRED before anyone can log in
      ▼
mt_service_create           REQUIRED — a site cannot exist without a service
      │
      ▼
mt_site_create              REQUIRED before a voucher (Decision 2b)
      │
      ▼
device register → stage → ship → assign      OPTIONAL — a sale completes
                                             with no hardware (docs/102)
```

| Step | Required for… |
|---|---|
| customer | everything |
| principal | **a login only.** An operator managed entirely by DishNet staff needs none |
| service | a site |
| site | a voucher (2b), and a router's `site_id` |
| device | **nothing in the commercial chain** |
| **uCRM link** | **nothing** |

### 2.2 Equipment-first

```
mt_device_register → mt_device_stage → mt_device_ship
        │  customer_id IS NULL throughout — inventory is not ownership
        ▼
   (the customer materialises)
        │
        ▼
mt_customer_create → mt_principal_create → mt_service_create → mt_site_create
        │
        ▼
mt_device_assign                    ← the ONLY convergence point
        │
        ▼
provisioning intent
```

**A device may exist with no customer** — measured, and unclaimed devices are
already invisible to every tenant because `NULL = <uuid>` is NULL, not true.
**`UNCLAIMED` stays a predicate, never a device state.** **No placeholder
customer, ever.**

> The two flows are **one design entered from either end**. The commercial chain
> and the network chain are independent and identical in both.

---

## 3. Closed decisions this design preserves

| | Preserved how |
|---|---|
| **P-B** — global phone uniqueness | the principal writer **refuses** a duplicate phone; the OTP path is untouched |
| **P-C** — no principal reassignment | **no function is designed that changes `mt_principals.customer_id`** |
| **S-A** — no ordinary service migration | no function changes `mt_services.customer_id`; O-1 makes it constraint-refused |
| **O-1** — site/service/customer integrity | `mt_site_create` **depends on it** and may not ship before it |

---

## 4. Idempotency — I-A applied to every writer

**Rule I-1 (from `docs/108`): detection occurs BEFORE the mutating, audited
operation executes. A replay must not create a second audit event.** Forced by
W-1 — the audit row is written inside the function, so a check placed after it
cannot prevent the duplicate. The check is the **first** statement; a replay
returns the stored result and writes **nothing**.

| Class | Operations | Mechanism |
|---|---|---|
| **Pre-customer** — no tenant exists | customer creation | **non-tenant table**, `(endpoint, key)` + `request_digest`, reachable by `dnb_adminwrite` |
| **No natural key, tenant exists but staff plane has no context** | service creation, site creation, NULL-phone principal | **the same non-tenant table** |
| **Natural key already enforced** | principal-by-phone, uCRM customer link | **the existing UNIQUE** — stronger than a table, because a caller cannot bypass it by omitting the key |
| **State assertion** | device assignment | **domain invariant** — compare current to requested; identical is a no-op, different is a reassignment |
| **Tenant-scoped, already solved** | provisioning intents | `mt_intents_idem_uq` |

**Conflict behaviour, uniformly:** same key + **different digest** → **refuse**.
A unique violation is **not** a replay until the stored digest matches.

> **Why the store must be non-tenant, measured twice:** `mt_idempotency` is keyed
> `(customer_id, key)` with `customer_id NOT NULL`, so customer creation has
> nothing to key on; and **`dnb_adminwrite` holds zero table privileges**, so it
> could not write `mt_idempotency` even where a customer exists.

---

## 5. Production safety

### 5.1 Prerequisites, in order

| # | Gate | State |
|---|---|---|
| 1 | **the O-1 census** — read-only, through `mt_admin_sites()`/`mt_admin_services()` as `dnb_adminapi`; **no superuser, no `BYPASSRLS` role** | operator |
| 2 | **operator reviews**; per-row decisions if BLOCKED. **Never combined with 3** | operator |
| 3 | **the O-1 migration** — `UNIQUE (id, customer_id)` on `mt_services`, then the composite FK. Fails closed by itself | approval |
| 4 | **E-2** — the production census | operator; `docs/79` |
| 5 | **migration 020's own production authorization** | still not given |
| 6 | **the non-tenant idempotency store** — *before the first writer* | approval |
| 7 | **W-4** — before any of this is reachable over HTTP | open |

### 5.2 Indexes and constraints required

| | |
|---|---|
| **new** | `mt_services_id_customer_key UNIQUE (id, customer_id)` — cannot fail on existing data, since `PRIMARY KEY (id)` is strictly stronger |
| **new** | `mt_sites_service_customer_fkey FOREIGN KEY (customer_id, service_id) REFERENCES mt_services (customer_id, id)` |
| **no** `MATCH FULL`, **no** CHECK | both `mt_sites` columns are already `NOT NULL`, so `MATCH SIMPLE` is equivalent; adding either would be inert **and imply a NULL case exists** |
| **new** | the non-tenant idempotency store's `UNIQUE (endpoint, key)` |
| **unchanged** | `mt_principals_phone_uq`, `mt_customers_ucrm_client_id_key`, `mt_intents_idem_uq` — all already the right shape |
| lock note | the UNIQUE takes **ACCESS EXCLUSIVE on `mt_services`** — the only read-blocking window. `CREATE INDEX CONCURRENTLY` is **unavailable** (`Migrator` runs each file as one implicit transaction). **Duration UNMEASURED until E-2** |

### 5.3 Actor and audit

- Actor is **always a parameter** from the identity boundary, `actor_kind = 'staff'`.
- **One audit row per *actual* change**, inside the function, same transaction.
- **A replay writes none** (§4).
- **Audit is never gated on a uCRM link** — or the least-established records
  become the least recorded.
- **`actor_kind` stays `principal | staff | system`.** No `guest`, no `worker`.

### 5.4 RLS boundaries

Unchanged: every tenant table `ENABLE` + **`FORCE ROW LEVEL SECURITY`**, policy
`USING/WITH CHECK (customer_id = mt_current_customer())`. **With no tenant
context the predicate is NULL, not true — even the owner sees zero rows.** The
staff plane sets **no** tenant context, which is why definer functions rather
than direct writes are the only workable shape.

### 5.5 SECURITY DEFINER boundaries

| Role | May |
|---|---|
| `dnb_adminwrite` | **EXECUTE the spine functions, and nothing else** — zero table privileges, now and by default |
| `dnb_adminapi` | EXECUTE the read projections only |
| `dnb_def_*` | own the functions; **a non-owner GRANT is answered with a WARNING, not an error** — so ownership must be asserted, not assumed |
| `dnb_app` | **gains nothing.** A customer must not create their own principal, service or site |
| `dnb_portal`, `dnb_radius` | untouched — no table privileges, one EXECUTE each |

### 5.6 Which operations may NOT be called directly by `dnb_admin`

> **All nine.** Per **A-1**, `dnb_admin` (and `dnb_worker`) carry migration 015's
> blanket grant *including `mt_audit_log` INSERT*, so a route connecting as
> either could write business state **and forge its own audit row**.

- **No spine function may be granted EXECUTE to `dnb_admin`.**
- **No new route may connect as `dnb_admin`.** `Database::adminWrite()` is the
  only write identity, and it is *"deliberately not `admin()`"*.
- Revoking migration 015's blanket grant is **F-3's own remediation** and is
  **not** in this design's scope — but **A-1 raises its priority**, because the
  forgeable target is the audit trail.

### 5.7 Customer-facing vs staff-only

| Staff-only (`dnb_adminwrite`) | Customer-facing (`dnb_app`, under RLS) |
|---|---|
| customer creation · principal create/disable · service creation · site creation · uCRM link/unlink · device register/stage/ship/assign/provision | plan create/update/retire · voucher batch + issuance · voucher revoke · session view/disconnect · `/me` reads |

**None of the nine is customer-facing.** The customer plane keeps exactly what it
has; **B-2** (moving plan/voucher writes behind definer functions) is separate
work and unchanged by this design.

---

## 6. The operation table

| Operation | Actor | Preconditions | Domain-B dependency | uCRM dependency | Idempotency | Audit | Status |
|---|---|---|---|---|---|---|---|
| **customer create** | staff | non-blank name | none | **none** | non-tenant table | W-1 ✓ exists | **function EXISTS, no caller** |
| **principal create** | staff | customer exists; **phone unique or NULL** | customer | **none** | `phone` UNIQUE, else table | W-1 required | **NOT IMPLEMENTED** |
| **principal disable** | staff | principal exists | principal | **none** | state assertion | W-1 required | **NOT IMPLEMENTED** |
| **service create** | staff | customer exists | customer | **none** | **table — mandatory** | W-1 required | **NOT IMPLEMENTED** |
| **site create** | staff | service exists | service + **O-1** | **none** | table | W-1 required | **BLOCKED on O-1** |
| **uCRM customer link** | staff | customer exists; uCRM client exists | customer | **the link itself** | existing UNIQUE | audited, prev + new + reason | **DESIGNED; cardinality OPEN (C10)** |
| **uCRM service link** | staff | service exists; coherence holds | service + **U-5 column** | **the link itself** | UNIQUE (no column yet) | audited | **DEFERRED — far side unevidenced** |
| **device register/stage/ship** | staff | — | **none** | **none** | serial UNIQUE | W-1 ✓ | **EXISTS, no route** |
| **device assign** | staff | device + customer + site; **W-2** | site | **none** | **domain invariant** | W-1 ✓ | **EXISTS, no route** |
| **provisioning intent** | staff/principal | customer exists | customer | **none** | `mt_intents_idem_uq` | **none today** | **EXISTS** |
| *`session.disconnect`* | principal | session exists | — | **none** | **NONE — no key, no guard** | caller-written | **BLOCKER, not fixed here** |

---

## 7. Status

**Closed and preserved:** P-B · P-C · S-A · O-1 (as a design).

**Open:** U-1 (Q7) · U-2 (E-2) · U-5 (deferred) · U-6 · U-7 · U-9 · C6/C10/C16 ·
W-4/W-5/W-6 · I-A (approval) · E-2.

**Blockers:** O-1 census + migration · the non-tenant idempotency store ·
`session.disconnect` replay (before F6-B) · **N-1/N-2/N-3** (the bridge) ·
**A-1** (new).

---

**Stopping here. Suite unchanged: 1,599 assertions, 27 suites. No migration, no
schema change, no production access, no application code, no fixtures. One new
finding recorded; nothing built; no gate moved.**
