# 106 — Identity integrity remediation package

**Design only. No migration, no schema change, no production change.**
Nothing here is authorized to build.

> **How this document was validated.** Every DDL statement below was executed
> against the real `dnb_sim` schema **inside a transaction that was rolled
> back**, so the design is *demonstrated* rather than proposed. Residue was
> checked afterwards and is **zero rows, zero constraints**. Nothing was
> applied, and `migrations/` is untouched.

---

## 1. The exact O-1 composite foreign key

```sql
ALTER TABLE mt_sites
  ADD CONSTRAINT mt_sites_service_customer_fkey
  FOREIGN KEY (customer_id, service_id)
  REFERENCES  mt_services (customer_id, id);
```

Executed; reads back verbatim as

```
mt_sites_service_customer_fkey ::
  FOREIGN KEY (customer_id, service_id) REFERENCES mt_services(customer_id, id)
```

The two lists correspond **positionally**: `customer_id ↔ customer_id`,
`service_id ↔ id`. That pairing is the whole invariant — a site's customer must
be the customer of the service it names.

**Column order is cosmetic, and this was measured rather than assumed.** Both
forms are accepted against the same unique constraint:

| Attempted | Against `UNIQUE (id, customer_id)` | Result |
|---|---|---|
| `REFERENCES svc (customer_id, id)` | order reversed | **accepted** |
| `REFERENCES svc (id, customer_id)` | order matching | **accepted** |

PostgreSQL matches the referenced columns as a **set**, not a sequence. So the
ordering above — the one in the instruction — is valid, and W-2's
`(site_id, customer_id) → (id, customer_id)` is equally valid. Either may be
chosen for readability; **neither is more or less safe.**

---

## 2. The supporting UNIQUE constraint

```sql
ALTER TABLE mt_services
  ADD CONSTRAINT mt_services_id_customer_key UNIQUE (id, customer_id);
```

Measured today: `mt_services` carries **only `mt_services_pkey PRIMARY KEY (id)`**.
A composite FK cannot reference a non-unique column pair, so this is required.

### It cannot change legitimate cardinality — proved, twice

`id` is already the primary key, therefore unique on its own. A pair
`(id, customer_id)` built on a unique `id` is **automatically** unique: two rows
can never share it without first sharing `id`.

Demonstrated — the duplicate is rejected by the **primary key**, not by the new
constraint:

```
INSERT … VALUES ('1111…','aaaa…');   -- INSERT 0 1
INSERT … VALUES ('1111…','bbbb…');   -- ERROR: duplicate key value violates
                                     --        unique constraint "svc_pkey"
```

> **Consequence for migration safety: the UNIQUE step cannot fail on existing
> data.** It adds an index, not a restriction. Confirmed a second time in §6,
> where it succeeded *even with a violating site row present*. **Only the
> foreign key can refuse.**

This also answers the question directly: one customer may still hold many
services, and a service still belongs to exactly one customer. **Nothing about
the existing cardinality changes.**

---

## 3. NULL semantics

| Column | Nullability | Consequence |
|---|---|---|
| `mt_sites.service_id` | **NOT NULL** | no row can omit the service |
| `mt_sites.customer_id` | **NOT NULL** | no row can omit the customer |

Under the default **`MATCH SIMPLE`**, a composite FK is **skipped entirely if
any referencing column is NULL**. That is exactly the escape hatch `mt_devices`
has to live with — its `site_id` *is* nullable, which is why W-2 needed a
companion `CHECK (site_id IS NULL OR customer_id IS NOT NULL)`.

**`mt_sites` has no such hatch.** Both columns are `NOT NULL`, so no row can
present a partial key, and `MATCH SIMPLE` therefore behaves identically to
`MATCH FULL` on this table.

> **Do not add `MATCH FULL`, and do not add a CHECK.** Per instruction, and
> because both would be inert: they would constrain a state the column
> definitions already forbid, while implying to a future reader that a NULL case
> exists. **The `NOT NULL` on `customer_id` is what makes the bypass
> impossible** — if it were ever relaxed, `MATCH FULL` would become necessary
> and this paragraph is the reason why.

---

## 4. RLS test matrix — run against the proposed schema

Executed with the constraints in place, inside the rolled-back transaction, as
**`dnb_app`** under RLS. `A` and `B` are two simulated customers.

| # | Test | Required | **Measured** |
|---|---|---|---|
| **C-1** | *control* — A reads its own sites | **> 0** | **2** ✓ |
| **C-2** | *control* — A reads its own services | **> 0** | **1** ✓ |
| **T-1** | A creates a site referencing **A's** service | PASS | **`INSERT 0 1`** ✓ |
| **T-2** | A creates a site referencing **B's** service | FAIL | **refused** — `mt_sites_service_customer_fkey` ✓ |
| **T-3** | A repoints an existing site from A's service to B's | FAIL | **refused** — same constraint ✓ |
| **C-3** | *control* — B reads its own sites | **> 0** | **2** ✓ |
| **T-4** | A reads B's service | invisible | **0** ✓ |
| **T-5** | B reads A's site | invisible | **0** ✓ |
| **T-6** | B deletes a service its **own** sites reference | refused, unchanged | **refused** — `mt_sites_service_id_fkey` ✓ |

**Every zero-row assertion is paired with a non-zero control in the same
session**, per the credible-evidence rule: T-4's `0` is meaningful only because
C-1 and C-2 returned `2` and `1` from that identical session.

### T-6 — the deletion case, stated precisely

With the composite FK in place, **the dangerous version of this test becomes
unreachable**: A can no longer create a site against B's service, so A can no
longer pin it. What remains is the ordinary case — B's own sites reference B's
service, and `ON DELETE RESTRICT` refuses the delete. **That behaviour is
unchanged and is correct**: a service with live sites should not vanish. Ending
a service is `status = 'ended'`, which is a lifecycle operation with no writer
(U-9), not a row deletion.

### Still required at implementation time

| | |
|---|---|
| **T-7** | `UPDATE mt_services.customer_id` while a site references it → refused (documents the S-A consequence) |
| **T-8** | the whole battery with **no tenant context** → 0 rows, no write succeeds, and **never reported as "clean"** |
| **T-9** | **the control on the controls** — T-2 must be shown to *fail* when the constraint is absent. Without T-9 the suite cannot detect its own removal |

---

## 5. Pre-migration census

**Read-only.** Runs through the Admin read boundary as **`dnb_adminapi`** — an
existing non-superuser login role — using `mt_admin_sites()` and
`mt_admin_services()`, which already expose every column involved
(`docs/105` §4.1). No superuser, no `BYPASSRLS`, no new grant.

| # | Count | Blocks migration? |
|---|---|---|
| 1 | sites total | no — **context** |
| 2 | services total | no — **context** |
| 3 | **customer/service mismatches** | **YES — the blocker** |
| 4 | NULL `customer_id` | must be impossible; non-zero ⇒ **wrong database** |
| 5 | NULL `service_id` | must be impossible; non-zero ⇒ **wrong database** |
| 6 | duplicate candidate composite keys `(id, customer_id)` on `mt_services` | must be 0; non-zero ⇒ the **primary key is missing** |
| 7 | orphaned sites — `service_id` resolves to nothing | investigate; should be impossible under the existing FK |
| 8 | orphaned services — no site references them | **expected and legal**; recorded for shape |

Count 3 is the one that stops the work:

```sql
SELECT count(*)
  FROM mt_admin_sites() s
  JOIN mt_admin_services() v ON v.id = s.service_id
 WHERE v.customer_id <> s.customer_id;
```

Demonstrated: with one violating row injected, this returned **`mismatches = 1`**.

### Mandatory controls

The census reports **`INDETERMINATE`**, never `0 mismatches`, unless counts 1
and 2 are both **non-zero** and `current_user`, `current_database()` and the
method (`projections` | `superuser`) are printed. A zero-mismatch result over a
zero-row read measures nothing.

### The operator must be told what blocks it

**Do not repair automatically.** The census reports the mismatching pairs so a
person can decide each one. There is no correct automatic answer: deleting a
site destroys history that 18 `ON DELETE RESTRICT` foreign keys exist to
protect, and re-pointing one silently changes which customer owns a site.

---

## 6. Migration safety — it fails closed, measured

One violating row was injected and the migration attempted:

```
ALTER TABLE mt_services ADD CONSTRAINT … UNIQUE (id, customer_id);
-- ALTER TABLE            ← succeeds even with the violation present

ALTER TABLE mt_sites ADD CONSTRAINT mt_sites_service_customer_fkey …;
-- ERROR:  insert or update on table "mt_sites" violates foreign key
--         constraint "mt_sites_service_customer_fkey"
-- DETAIL: Key (customer_id, service_id)=(10894e6f-…, 4f307167-…)
--         is not present in table "mt_services".
```

> **The migration fails closed by itself.** No guard clause is needed, and none
> should be added — PostgreSQL validates the constraint against every existing
> row before accepting it, and the whole migration is one transaction, so a
> refusal leaves the schema exactly as it was.

**But the error names only ONE pair.** It reports the first violation it meets,
not all of them. An operator working from the error alone would fix one row,
re-run, and meet the next. **That is precisely why §5's census runs first** — the
error diagnoses, the census enumerates.

**`NOT VALID` is available and is NOT recommended here.** It would let the
constraint be added without checking existing rows, deferring the refusal to a
later `VALIDATE CONSTRAINT`. That converts a fail-closed migration into a window
in which the invariant is declared but not enforced. Only E-2 could justify it
(§7).

---

## 7. Locks and index creation

Measured per statement, each in its own transaction:

| Statement | `mt_services` | `mt_sites` |
|---|---|---|
| `ADD CONSTRAINT … UNIQUE (id, customer_id)` | **ACCESS EXCLUSIVE** + Share | — |
| `ADD CONSTRAINT … FOREIGN KEY …` | ShareRowExclusive | **SHARE ROW EXCLUSIVE** + AccessShare |

| Question | Answer |
|---|---|
| Can the index be created inside the migration transaction? | **Yes.** `ADD CONSTRAINT … UNIQUE` builds its index transactionally. Only `CONCURRENTLY` cannot, and `Migrator` runs each file as one implicit transaction (`PDO::exec`, `src/Db/Migrator.php:26`) |
| What blocks readers? | **Only statement 1, and only on `mt_services`.** ACCESS EXCLUSIVE blocks reads as well as writes |
| What blocks `mt_sites`? | **Writes only.** SHARE ROW EXCLUSIVE permits concurrent `SELECT`. `mt_sites` readers are never blocked |
| Should it fail closed? | **Yes, and it already does** — see §6 |
| Expected duration | **UNMEASURED.** `mt_services` is expected to be small — one row per customer service — but *expected* is not *measured*, and **no production timing may be claimed until E-2** |
| Does production size require a different mechanism? | **Unknown, and the question is real.** If E-2 shows `mt_services` large enough that an ACCESS EXCLUSIVE index build is unacceptable, the alternative is `CREATE UNIQUE INDEX CONCURRENTLY` outside a transaction followed by `ADD CONSTRAINT … USING INDEX` — which **this repository's migrator cannot express**. That would be a migrator change, and it must not be invented pre-emptively |

---

## 8. `mt_site_create` — the writer contract

```
mt_site_create(p_service uuid, p_name text, p_location text, p_actor text)
  RETURNS uuid
                 ▲
                 └── there is NO p_customer parameter, by construction
```

```
        authenticated Domain-B customer context
                          │
                          ▼
                requested service_id                    ← untrusted; names a candidate
                          │
                          ▼
        verify the service belongs to that customer     ← application authorization
                          │
                          ▼
              derive customer_id from the service       ← never from the caller
                          │
                          ▼
                     INSERT the site
                          │
                          ▼
        composite FK re-checks the same invariant       ← the database floor
```

**The forgery is not rejected — it is unrepresentable.** There is no parameter
in which to express it.

| Layer | Evidence class (`docs/103`) | Stops |
|---|---|---|
| composite FK | 2–3 | **every** path, including a direct `UPDATE` by the table owner |
| the function derives the customer | 3 | a caller naming the wrong customer |
| the route carries no customer | 5 | the request shape |

**An application check alone is not the fix.** `docs/105` §1.2 measured why:
referential integrity is evaluated *below* RLS, while an application check runs
*above* it and would find nothing to object to. The FK is the control; the
function is defence in depth.

### Resolution differs by plane, and the difference matters

- **Staff plane** (`dnb_adminwrite`, definer function, **no tenant context**) —
  staff legitimately act across customers, so "the authenticated customer" does
  not bound them. The bound is structural: the service names the customer. Actor
  is a parameter from the Admin identity boundary, `actor_kind = 'staff'`, with
  a W-1 audit row in the same transaction.
- **Customer plane** (`dnb_app`, if ever exposed — *not proposed here*) — the
  service must be resolved **under the caller's own RLS context**, so another
  customer's `service_id` resolves to **no row** and the call refuses. It must
  **never** be resolved by a definer function that can see everything and
  checked afterwards.

Also required: **refuse rather than create a missing service**, and do not
silently accept a service whose `status = 'ended'` — flag it, do not invent a
rule. **W-4 is open, so none of this may be bound to an HTTP route.**

---

## 9. U-1 — refined, still open

**Not closed.** Restated against the onboarding spine, with what is established:

| Operation | uCRM **customer** link | uCRM **service** link | Basis |
|---|---|---|---|
| customer creation | **not required** | — | established |
| principal creation | **not required** | — | established; a Domain-B login is an authentication artifact |
| device register / stage / ship | **not required** | — | established — *inventory is not ownership* |
| device assignment | *proposed: required* | no | asserts ownership — but **not the only gate** (`docs/102`) |
| **service creation** | **proposed: REQUIRED — the first hard gate** | **proposed: required** | the first act asserting a **billable** relationship, and where `docs/101`'s coherence rule lands (*the uCRM service's `clientId` must equal the client linked to that service's customer*) — uncheckable unless both links exist |
| site creation | **inherited** | inherited | a site needs a service; §1 binds it to that service's customer. **No separate gate** |
| plan / voucher creation | *proposed: required* | where a service is involved | revenue-bearing; **this is B-2 work** — they write directly under RLS with nowhere to put a check |
| voucher redemption | **FORBIDDEN** | forbidden | `docs/89`, `docs/102` |
| accounting ingest | **FORBIDDEN** | forbidden | one EXECUTE, no table privileges |
| audit write | **FORBIDDEN** | forbidden | or the least-established records become the least recorded |

**Ruled out by evidence:** a blanket `NOT NULL` (U-2 — three independent
reasons), gating at `mt_customer_create`, and gating *only* at
`mt_device_assign`. **U-1 and U-5 must be decided together.**

---

## 10. P-B — globally unique `mt_principals.phone`

### The OTP architecture does assume it — and unsafely

```sql
-- mt_auth_issue_code
SELECT id, customer_id INTO v_principal, v_customer
  FROM mt_principals WHERE phone = p_phone AND status = 'active';
```

A **non-`STRICT`** `SELECT … INTO`, with **no `ORDER BY`** and **no `LIMIT`**.
PL/pgSQL semantics, measured rather than recalled:

| Form | Two matching rows |
|---|---|
| `SELECT … INTO` | **returns the first row, NO ERROR** — measured: `id=1, customer=CUSTOMER-A` |
| `SELECT … INTO STRICT` | raises **`P0003` — query returned more than one row** |

> **If phone uniqueness were relaxed, `mt_auth_issue_code` would silently bind
> the one-time code to an arbitrary principal — and therefore to an arbitrary
> customer.** The code row stores `principal_id` and `customer_id`, and
> `mt_auth_verify_code` returns them, so **the tenant of the resulting session
> would be chosen non-deterministically.** No error, no log, no signal.

**The unique index is therefore load-bearing for correctness, not merely for
lookup speed.** `docs/100` recorded that it makes the lookup *safe*; this is the
mechanism by which.

### May one person legitimately operate several customers?

The question is **already open, twice**, and is not P-B's to settle:

- **C6** — owner / operator roles inside the Customer PWA (`docs/47`), blocked on
  the PWA freeze
- **C10** — *"Is the docs/42 Reseller the same person as the Customer PWA
  user?"* — **ARCH**, and rated **"Highest. Rebuilds permission logic and
  possibly a surface"**
- **C16** — where staff records live, because *"C6 creates logins for people who
  are not DishNet customers"*

A reseller or agent managing several venues is exactly the shape that would need
one human to hold two customers. **That is C10, and it is open.**

### Verdict

> **Changing phone uniqueness is BREAKING, not a schema tweak.** It is not an
> index change — it changes what a login *is*, because the current login resolves
> a tenant from a phone number alone. If one person must serve two customers, the
> candidate answers are a **second phone number**, an explicit
> **principal-selection step after OTP**, or a login that **names the customer
> first** — each of which is a C6/C10 decision.

**Do not change it. The principal writer must treat a duplicate phone as a
refusal, never an upsert**, and must not assume either answer.

---

## 11. P-C — principal reassignment

### A live session would keep the old tenant — measured

```sql
-- mt_auth_sessions has its OWN customer_id column
INSERT INTO mt_auth_sessions (principal_id, customer_id, token_hash, expires_at) …

-- mt_auth_resolve_token returns the SESSION's copy, not the principal's
SELECT s.principal_id, s.customer_id
  FROM mt_auth_sessions s
  JOIN mt_principals p ON p.id = s.principal_id
 WHERE s.token_hash = … AND p.status = 'active';
```

The join to `mt_principals` exists **only to check `status`**. The tenant comes
from `s.customer_id`.

> **So moving a principal from customer A to customer B would leave every live
> session still serving A's data** until it expired or was revoked — a
> cross-tenant access window opened by an administrative action, invisible to
> the operator, the customer and the audit trail.

Add the already-measured attribution damage (`docs/100`: `sold_by`,
`created_by` and `actor_principal_id` are `SET NULL` and *nothing errors*), and:

> ### **P-C — recommendation: principal reassignment is FORBIDDEN.**
> There is to be no operation that changes `mt_principals.customer_id`. The
> correct procedure is **disable the principal and create a new one** under the
> other customer, which is lossless where reassignment is not.
>
> If it is ever nonetheless required, it is a definer function that **revokes
> every live session for that principal in the same transaction** — and that
> requirement is the reason it should not be built casually.

**Recommended, not decided. Nothing implemented.**

---

## 12. S-A — service migration between customers

**Status: UNIMPLEMENTED.** Confirmed:

| | |
|---|---|
| writer | **none** — `mt_services` is written only by `Plugin/Simulator.php` |
| document describing it | **none found** |
| schema | `customer_id` `NOT NULL`, no update path, `ON DELETE RESTRICT` |

**Recommendation: `forbidden` for now, by constraint rather than by policy.**
With §1 in place and `ON UPDATE NO ACTION` inherited,
`UPDATE mt_services SET customer_id = …` is **refused while any site references
the service**. A stray update cannot do it; only a designed operation could.

If it is ever wanted it is **administrative reassignment**, not a customer-facing
feature: one definer function moving the service, its sites and their devices in
one transaction with one audit trail — and re-papering to a different uCRM
client besides. **Do not create migration behaviour now.**

---

## 13. I-A — idempotency, per writer

**The existing mechanism cannot be reused, for two independent measured
reasons** (`docs/105` §8.1): `mt_idempotency` is keyed `(customer_id, key)` with
`customer_id NOT NULL` — so a customer-creation retry has nothing to key on —
and `dnb_adminwrite` holds **zero table privileges**
(`has_table_privilege(…,'mt_idempotency','INSERT') = false`) while the Admin
plane sets no tenant context.

| Writer | Natural key | Actor scope | Retry hazard | Required |
|---|---|---|---|---|
| **customer creation** | **none** — `name` is not unique, `radius_ref` is generated *by the insert* | staff | a duplicate **customer**, which then cannot be deleted (18 RESTRICT FKs) | caller-supplied key in a **non-tenant** store |
| **principal creation** | `phone`, **only when non-NULL** | staff | a unique violation that is an **error, not a replay** — and a NULL-phone principal has **no key at all** | caller-supplied key; a duplicate phone is a **refusal** (§10), never an upsert |
| **service creation** | **none whatsoever** — `customer_id` + a `kind` with one legal value | staff | **two indistinguishable services**; worst case of the five | caller-supplied key, mandatory |
| **site creation** | **none** — `(customer, service, name)`; `name` is not unique | staff | a duplicate site, which a voucher may then bind to (Decision 2b) | caller-supplied key |
| **device assignment** | the device | staff | **idempotent in state, not in record** | decide replay vs reassignment |

**`mt_device_assign`, measured:** an absolute-value `UPDATE`
(`customer_id = p_customer, site_id = p_site, name = p_name`), so identical
arguments leave identical state — but it re-stamps `claimed_at = now()` and
writes **another audit row**, and *different* arguments are a legitimate
**reassignment**, not a retry. It also returns `NULL` rather than raising when
the device does not exist, which a retry handler must not read as success.

### The store

Forced by the two reasons above: **a non-tenant idempotency store, reachable by
`dnb_adminwrite`**, keyed by `(endpoint, key)` with a `request_digest` — the same
shape `mt_idempotency` already uses (*"so the same key with a different body is
caught"*) minus the tenancy. It is structurally the same answer `docs/89`
reached for the attempt store, for the same underlying reason: **a check that
requires tenant data cannot run in the case that has none.**

**A unique violation is not a replay** until the stored digest matches.
**Do not build it yet.**

---

## 14. Exact prerequisites before implementation

| # | Prerequisite | State |
|---|---|---|
| 1 | §1–§3 (the composite FK, its UNIQUE, the NULL analysis) **approved** | awaiting |
| 2 | the §5 census has run in production and reports **CLEAR** | awaiting operator |
| 3 | **E-2** — the production census | **still the gate**; `docs/79` |
| 4 | a per-row operator decision for any mismatch found | conditional on 2 |
| 5 | E-2 confirms `mt_services` is small enough for an ACCESS EXCLUSIVE index build, **or** a migrator change is authorized | open (§7) |
| 6 | migration 020's own production authorization | still not authorized |
| 7 | the §4 matrix written **first**, **including T-9** | not started |
| 8 | **I-A** — the non-tenant idempotency store | open (§13) |
| 9 | **U-1** + **U-5** decided together | open (§9) |
| 10 | **C6 / C10 / C16** before any writer branches on `kind` or assumes one-person-one-customer | open since `docs/47` |
| 11 | **P-B** decided — the principal writer's duplicate-phone behaviour | open (§10) |
| 12 | **P-C** ratified as forbidden, or designed with session revocation | recommended (§11) |
| 13 | **W-4** before anything is reachable over HTTP | open |

**Order: O-1 → census → decisions 8–12 → writers.**

---

## 15. Open items

| # | Item | Status |
|---|---|---|
| **O-1** | composite FK | **designed and demonstrated**, not authorized |
| **P-A** | `owner`/`operator` | **RETIRED** — it is C6/C16 |
| **P-B** | global phone uniqueness | open — **changing it is breaking**; bounded by C6/C10 |
| **P-C** | principal reassignment | **recommendation: FORBIDDEN** — awaiting ratification |
| **S-A** | service migration | **UNIMPLEMENTED**; forbidden-by-constraint once O-1 lands |
| **I-A** | idempotency store | open — a non-tenant store is forced |
| **U-1 / U-5** | where the uCRM links become mandatory | open — boundary refined, not closed |
| **U-2** | `ucrm_client_id NOT NULL` | open — **E-2** |
| **U-6** | authoritative source for the OTP phone | open |
| **U-9** | does a uCRM suspension suspend the service? | open |
| **C6 / C10 / C16** | roles; reseller identity; where staff records live | open since `docs/47` |
| **W-4** | staff identity | open |

---

**Stopping here. Suite unchanged: 1,599 assertions, 27 suites. No code, no
schema change, no migration, nothing installed, no gate moved. The remediation
is demonstrated and applied nowhere.**
