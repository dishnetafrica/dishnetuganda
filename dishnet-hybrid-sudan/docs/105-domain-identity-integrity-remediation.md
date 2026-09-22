# 105 — Domain identity integrity remediation

**Design only. No migration, no schema change, no production change.**
Nothing in this document is authorized to build.

`docs/104` found **O-1** while measuring cardinality. This closes the design of
its remediation, defines the census that must precede any migration, and
resolves — from existing documents — which of the spine's open questions were
already open under another name.

---

## 0. The evidence rule this document is written under

> **Negative result + positive control + known authorization context
> = credible evidence.**

Standing rule for this project, from this point on. A `0 rows` result means
nothing on its own, because it has **seven** possible causes:

| | `0 rows` might mean |
|---|---|
| 1 | genuinely zero |
| 2 | RLS hid everything |
| 3 | wrong tenant context |
| 4 | wrong database |
| 5 | wrong role |
| 6 | the query never executed |
| 7 | the fixture was never created |

Only 1 is a finding. The other six are defects in the measurement. **Every
census query and every security test must therefore carry a positive control
that proves the session can see something it is entitled to see** — and the
authorization context (role, database, tenant) must be stated, not assumed.

**This document violated that rule twice while being written**, and both are
recorded rather than quietly fixed:

- §1.4 — a cross-tenant `INSERT … SELECT` that returned **`INSERT 0 0`**. The
  subquery ran under the attacker's own RLS context, so it selected nothing and
  wrote nothing. The next statement then "passed", which would have been read as
  *the defect does not reproduce*.
- `docs/104` §1 — a first count of `mt_sites` that returned `0` because no
  tenant context was set. **That is cause 2 and 3, not cause 1.**

---

## 1. The O-1 invariant

> **INVARIANT O-1.** For every row of `mt_sites`:
> `mt_sites.customer_id` **must equal** the `customer_id` of the
> `mt_services` row identified by `mt_sites.service_id`.
>
> A site belonging to customer A must never reference a service belonging to
> customer B.

Today nothing enforces it. `mt_sites` carries two **independent**
single-column foreign keys and no constraint relates them:

```
mt_sites_customer_id_fkey  FOREIGN KEY (customer_id) REFERENCES mt_customers(id) ON DELETE RESTRICT
mt_sites_service_id_fkey   FOREIGN KEY (service_id)  REFERENCES mt_services(id)  ON DELETE RESTRICT
```

RLS does not close the gap: its `WITH CHECK` tests the **written row's own**
`customer_id`, which in an attack is correctly the attacker's. `service_id` is
an unchecked pointer at a row the writer cannot read.

### 1.1 Measured under full controls

Run as **`dnb_app`**, under RLS, in one transaction, rolled back. `A` and `B`
are two simulated customers.

| # | Step | Result |
|---|---|---|
| **C1** | *control* — B deletes its own service while nothing references it | **`DELETE 1`** — the delete path works |
| 2 | A inserts its own site referencing **B's service**, by literal UUID | **`INSERT 0 1`** — accepted |
| **C2** | *control* — can A **read** the service it just referenced? | **`0`** — no |
| **C3** | *control* — can B **see** the row now referencing its service? | **`0`** — no |
| 4 | B deletes that same service again | **`ERROR … violates foreign key constraint "mt_sites_service_id_fkey" … Key is still referenced from table "mt_sites"`** |
| 5 | residue after rollback | **`0`** |

### 1.2 What the controls establish

- **C1 rules out the null result.** Without the cross-tenant row the delete
  succeeds, so step 4's refusal is caused by the injected reference and not by
  some pre-existing constraint.
- **C2 proves A wrote a reference to a row it cannot read.** Write authority and
  read authority are not the same surface — RLS bounded A's reads and did not
  bound this write.
- **C3 proves B cannot see, diagnose or remove the thing blocking it.**
- **C2 + C3 + step 4 together establish the mechanism:**

> **Referential integrity is enforced *below* RLS.** Neither party can see the
> other's row, yet the constraint evaluates both. That is precisely why a
> composite foreign key is a real floor — and why an application-level check
> alone is not, since an application check runs *above* RLS and would find
> nothing to object to.

### 1.3 Severity, stated exactly

| | |
|---|---|
| **Disclosure** | **No.** Confirmed twice. RLS still hides the victim's service row; the cross-tenant site is *dangling* to its owner |
| **Integrity** | **Yes.** A row exists whose two references disagree about who owns it |
| **Availability** | **Yes — cross-tenant denial.** The victim can never end that service, and cannot see why |
| **Reachable how** | The writer must supply the victim's **service UUID**. C2 shows the attacker cannot read it, and `gen_random_uuid()` is not enumerable |
| **Therefore** | **Not remotely exploitable by enumeration.** The realistic trigger is not an attacker — it is **a writer that passes a `service_id` it did not derive**: a stale identifier, a copied request, a confused admin, a bug |

That last line is the point. **The spine is about to build exactly such a
writer**, which is why O-1 is a prerequisite and not a backlog item.

### 1.4 The failed test, recorded

The first attempt at §1.1 used a subquery to find B's service:

```sql
INSERT INTO mt_sites (customer_id, service_id, name)
  SELECT :A, s.id, 'O1-PIN' FROM mt_services s WHERE s.customer_id = :B LIMIT 1;
-- INSERT 0 0
```

The `SELECT` ran under **A's** RLS context, found nothing, and inserted nothing.
The delete that followed then succeeded — a result that reads as *"no
cross-tenant denial"* and is simply a test that never ran. The literal-UUID form
is the valid one, and it also happens to model the real threat more honestly:
**the attacker does not read the victim's row, it only needs to name it.**

---

## 2. Proposed remediation — the W-2 pattern, one level up

### 2.1 The existing pattern, measured

```
mt_devices_customer_id_fkey     FOREIGN KEY (customer_id) REFERENCES mt_customers(id) ON DELETE RESTRICT
mt_devices_site_id_fkey         FOREIGN KEY (site_id)     REFERENCES mt_sites(id)     ON DELETE SET NULL
mt_devices_site_customer_fkey   FOREIGN KEY (site_id, customer_id)
                                  REFERENCES mt_sites (id, customer_id)          ← W-2, no ON DELETE/UPDATE clause
mt_devices_site_needs_customer  CHECK ((site_id IS NULL) OR (customer_id IS NOT NULL))
mt_sites_id_customer_key        UNIQUE (id, customer_id)                          ← what W-2 references
```

Proved still binding, in the same session as the O-1 attack: the same
`dnb_app` that inserted the cross-tenant **site** was refused when it tried to
point a **device** at another customer's site —
`ERROR … violates foreign key constraint "mt_devices_site_customer_fkey"`.
**One attacker, one session, two tables, two different answers.**

### 2.2 The proposal

| | |
|---|---|
| **Supporting UNIQUE** | `ALTER TABLE mt_services ADD CONSTRAINT mt_services_id_customer_key UNIQUE (id, customer_id)` — required; a composite FK can only reference a unique set. Measured: `mt_services` today has **only its primary key** |
| **The composite FK** | `ALTER TABLE mt_sites ADD CONSTRAINT mt_sites_service_customer_fkey FOREIGN KEY (service_id, customer_id) REFERENCES mt_services (id, customer_id)` |
| **Existing FKs** | **Both stay.** W-2 is additive — `mt_devices` kept its two single-column FKs alongside the composite one. Removing `mt_sites_service_id_fkey` would change `ON DELETE` behaviour, which is **not** the proven defect |
| **A CHECK?** | **Not needed, and it must not be added.** `mt_devices` needs `site_needs_customer` only because `site_id` is **nullable** — under `MATCH SIMPLE` a NULL component skips the check entirely. `mt_sites.service_id` and `mt_sites.customer_id` are **both `NOT NULL`**, so no row can skip it |
| **ON DELETE** | **Omit — inherit `NO ACTION`**, exactly as W-2 does. `docs/100` measured that nothing in this schema is `DEFERRABLE`, so `NO ACTION` blocks identically to `RESTRICT` |
| **ON UPDATE** | **Omit — inherit `NO ACTION`.** Consequence, deliberate: while any site references it, `mt_services.customer_id` **cannot be updated**. Service migration therefore becomes impossible *by accident* and must be designed explicitly — see **S-A**, §7 |

### 2.3 Behaviour under each operation

| Operation | Before | After |
|---|---|---|
| INSERT a site with a matching service | accepted | accepted — unchanged |
| INSERT a site with **another customer's** service | **accepted (O-1)** | **refused** |
| UPDATE `mt_sites.service_id` across customers | accepted | refused |
| UPDATE `mt_sites.customer_id` | accepted | refused unless the service agrees |
| UPDATE `mt_services.customer_id` | accepted | **refused while sites reference it** |
| DELETE a referenced service | refused | refused — unchanged |
| DELETE an unreferenced service | accepted | accepted — unchanged |

### 2.4 RLS interaction

**None required, and none may be added.** §1.2 proved the constraint is
evaluated below RLS, so it binds every caller — `dnb_app` under a tenant
context, a definer function under `SET LOCAL ROLE`, and a direct `UPDATE` by
the owner alike. This is the same reason W-2 was built as a constraint rather
than a check inside `mt_device_assign`, and the function says so in its own
comment.

### 2.5 What this does **not** fix

- Sites that already violate the invariant — the constraint **refuses to be
  created** while one exists. That is §3.
- Any other relationship. See §3.4.

---

## 3. Migration preconditions

### 3.1 Measured constraints on how it can be applied

- **Migrations run as one implicit transaction.** `Migrator` executes each file
  through a single `PDO::exec` on a multi-statement string
  (`src/Db/Migrator.php:26`), and the file's comment says so. Therefore
  **`CREATE INDEX CONCURRENTLY` is unavailable** — PostgreSQL forbids it inside
  a transaction block. A non-blocking build would need a migration mechanism
  this repository does not have.
- Consequently both statements take **`ACCESS EXCLUSIVE`** on their tables for
  the duration. **How long is unknown**, because production row counts are
  unknown — that is E-2.
- `ADD CONSTRAINT … NOT VALID` followed by a later `VALIDATE CONSTRAINT` is the
  standard way to shorten the lock. It is **available** and is a decision, not a
  default: `NOT VALID` means the constraint does not bind existing rows until
  validated, which is a weaker guarantee for a window of unknown length.

### 3.2 Preconditions, all of which must hold first

1. **The census (§4) reports zero mismatches in production.** If one exists the
   `ALTER` fails outright — the migration cannot half-apply, but it also cannot
   proceed.
2. **E-2 — the production census — has run.** Unchanged; `docs/79` is still the
   handoff. **Production data state is NOT ESTABLISHED.**
3. **A remediation decision exists for any mismatch found.** There is no
   automatic answer: a mismatched site cannot simply be deleted (18 FKs with
   `ON DELETE RESTRICT`, `docs/100`), and re-pointing it changes which customer
   owns a site. **That is an operator decision, per row.**
4. **Ordering within the migration:** `UNIQUE` on `mt_services` first, then the
   FK on `mt_sites`. The reverse fails.
5. **A rollback statement is written and tested** — `DROP CONSTRAINT` for both,
   in reverse order.
6. **Migration 020 is still not applied to production.** This one must not be
   the first to go in ahead of it.

### 3.3 Existing-data census — see §4

### 3.4 What must **not** be bundled into this migration

Per instruction, and repeated here because the temptation is real once a
migration file is open:

- **customer FKs** — not proven defective
- **principal relationships** — P-A/P-B/P-C are open questions, not defects
- **service lifecycle** — S-A is unimplemented, not broken
- **device lifecycle** — W-2 already closed its equivalent
- **voucher relationships** — `mt_vouchers.site_id NOT NULL` is a *separate*
  decided-but-unmigrated item (`docs/77` §1) gated on the same census. **It is
  not this migration.**
- **`credential_hash`** — dead, and still not to be dropped opportunistically

One proven defect, one remediation.

---

## 4. Production census design

**Read-only. It creates nothing, alters nothing, and links nothing.**

> **This is not E-2.** E-2 is the whole production census (`docs/79`). This is
> the narrow O-1 precondition, and it may be run as part of E-2 or before it.

### 4.1 The privileged read path already exists — no superuser needed

Measured, not proposed:

```
dnb_def_admin_mt_sites_select     ON mt_sites      USING (true)  cmd=SELECT  roles={dnb_def_admin}
dnb_def_admin_mt_services_select  ON mt_services   USING (true)  cmd=SELECT  roles={dnb_def_admin}

mt_admin_sites()     → TABLE(id, customer_id, service_id, name, location, created_at)
mt_admin_services()  → TABLE(id, customer_id, kind, status, started_at, ended_at)

EXECUTE granted to:  dnb_def_admin, dnb_adminapi          ← not PUBLIC
```

Both projections expose exactly the columns O-1 concerns, across all tenants,
**SELECT-only**. So:

> **The entire O-1 census is computable as `dnb_adminapi` — an ordinary,
> existing, non-superuser login role — through the Admin read boundary. No
> superuser, no `BYPASSRLS`, no new role, no new grant, no new policy.**

This is the documented privileged read-only method the instruction asks for, and
it is weaker than superuser in exactly the right way: it cannot write.

**If that path is unavailable** (an older schema without migration 021), the
documented fallback is a superuser session, stated explicitly in the output as
`method=superuser`, never silently. A `BYPASSRLS` role must **not** be created
for this — it would outlive the census.

### 4.2 The required counts

Each runs through the projections; `S` = `mt_admin_sites()`, `V` = `mt_admin_services()`.

| # | Measure | Shape |
|---|---|---|
| 1 | sites total | `count(*) FROM S` |
| 2 | services total | `count(*) FROM V` |
| 3 | **site/service customer mismatches** | `S JOIN V ON V.id = S.service_id WHERE V.customer_id <> S.customer_id` — **the blocker; must be 0** |
| 4 | orphaned sites — service_id resolves to nothing | `S LEFT JOIN V … WHERE V.id IS NULL` |
| 5 | orphaned services — no site references them | `V WHERE NOT EXISTS (SELECT 1 FROM S WHERE S.service_id = V.id)` — **expected and legal**, recorded for shape |
| 6 | NULL `customer_id` | must be 0 — the column is `NOT NULL`; a non-zero result means the census read the wrong database |
| 7 | NULL `service_id` | as above |
| 8 | duplicate identity keys | `radius_ref`, `ucrm_client_id`, `mt_principals.phone`, device `serial`/`wg_pubkey`/`tunnel_ip` — each already uniquely indexed; a non-zero count means the index is missing, not that duplicates exist |
| 9 | decommissioned relationships | sites whose service `status = 'ended'`; devices in `decommissioned` still holding a `site_id` |
| 10 | cross-tenant references, per relationship | the §3.4 list, **counted and reported but not remediated** — evidence for whether any *other* pair needs its own composite FK |

Rows 6 and 7 are **deliberately measures that should be impossible**. They are
the census's own sanity check: if a `NOT NULL` column reports NULLs, the
measurement is wrong, not the data.

### 4.3 Mandatory controls

The census **must abort and report `INDETERMINATE`** — never `0 mismatches` —
unless all of these hold:

| Control | Why |
|---|---|
| `current_user`, `current_database()`, `version()` printed | rules out causes 4 and 5 |
| `mt_admin_sites()` returns **> 0** rows | rules out causes 2, 3, 6, 7 — **if there are no sites at all, there is nothing to certify and the census says so** |
| `mt_admin_services()` returns **> 0** rows | as above |
| count 1 and count 2 are **both non-zero** before any mismatch count is believed | a mismatch count of 0 over an empty set is cause 2, not cause 1 |
| the method is stated: `projections` or `superuser` | no silent privilege escalation |
| a deliberate **negative control**: a query that *should* return rows for a known customer does | proves the session is not filtered |

> **A zero-mismatch result over a zero-row read is not evidence of anything.**
> The census must say `INDETERMINATE — no rows visible under <role>` and stop.

### 4.4 Output

A single record: timestamp, database, role, method, the ten counts, every
control's result, and one verdict — `CLEAR`, `BLOCKED (n mismatches)`, or
`INDETERMINATE`. **No customer names, no site names, no locations, no UUIDs of
real customers** beyond what is needed to act — a mismatch is reported as a
count plus opaque identifiers the operator can resolve locally.

---

## 5. RLS / positive-control test design

For the eventual implementation, and for every future security test in this
project.

| # | Test | Must show |
|---|---|---|
| **T-1** | positive control: tenant A sees A's own sites | **> 0** — before any negative assertion is believed |
| **T-2** | A inserts a site referencing **its own** service | accepted — the writer is not broken |
| **T-3** | A inserts a site referencing **B's** service by literal UUID | **refused by `mt_sites_service_customer_fkey`** — the O-1 regression |
| **T-4** | the refusal names the constraint | so a future reader knows *which* control fired |
| **T-5** | A cannot read B's service | **0** — confirms T-3 was a write-side refusal, not a read-side filter |
| **T-6** | B can still delete its own unreferenced service | **the C1 control** — proves the fix did not create a new denial |
| **T-7** | `UPDATE mt_services.customer_id` while a site references it | refused — documents the S-A consequence |
| **T-8** | same battery run with **no tenant context** | **0 rows, and no write succeeds** — never asserted as "clean" |
| **T-9** | meta-test: T-3 fails if the constraint is dropped | **the control on the controls** — proves the test can detect absence |

**T-9 is not optional.** A regression test that passes when the constraint is
missing is the same defect as a census that reports zero over an empty read.

---

## 6. P-A / P-B / P-C — what the documents already answer

### P-A — `owner` vs `operator`: **NOT A NEW QUESTION**

It is **C6**, open since `docs/47`, with **C16** attached:

- `docs/47` §C6 — *"Owner vs staff roles inside the Customer PWA · **A** two roles
  (owner / operator) · **B** single login · **C** configurable per customer"*,
  blocked on the **PWA freeze**
- `docs/48` §C16 — *"**Are hotel staff uCRM records?** Sub-accounts under the
  customer · full uCRM contacts"*, because *"C6 creates logins for people who are
  not DishNet customers. Where they live is a data-model decision"*
- `docs/55:115` — the schema comment itself: `-- kind: owner | operator. C6/C16 OPEN: see §L`
- `docs/55` §404 and `docs/56` §91 — *"Permission checks route through one
  `can()` — one place to change"*
- `docs/80` D-A — every principal is customer-scoped; DishNet staff have **no
  identity at all**

> **Resolution: P-A is a duplicate. Retire it and carry C6/C16.** The `operator`
> value was reserved deliberately, its meaning was always C6's to decide, and
> `docs/104` rediscovered an open decision rather than finding a new one. The
> principal writer must not branch on `kind` until C6 closes — it may only
> **store** it.

### P-B — globally unique phone: **GENUINELY NEW, nothing addresses it**

No document discusses the *scope* of the uniqueness. What exists is the
mechanism only:

- `mt_principals_phone_uq UNIQUE (phone) WHERE phone IS NOT NULL` — **no
  `customer_id` in the index**
- `mt_auth_issue_code` → `FROM mt_principals WHERE phone = p_phone AND status = 'active'`
- `docs/100` — *"`mt_principals.phone` is the **authentication key**… uniquely
  indexed, so the lookup is safe"*

The consequence is forced by the lookup, not chosen: **a global index is what
makes `phone → exactly one principal` resolvable at all.** Per-customer
uniqueness would make the OTP lookup ambiguous and would require the login to
name a customer first.

> **P-B stays open, and it is a real question with a real cost either way.**
> Today one human being cannot hold logins for two customers. Changing it is not
> an index change — it changes what a login *is*. **No writer may assume either
> answer**; the principal writer must treat a duplicate phone as a refusal, not
> as an upsert.

### P-C — principal reassignment: **NEW; one near-miss in the documents**

`docs/42` §320 lists *"Reassign site/tenant"* — but as a **device** action on
Router Detail, not a principal one. No document addresses moving a principal
between customers. What is measured:

- `mt_principals.customer_id` is `NOT NULL` and has no update path
- `docs/100` — deleting a principal **silently nulls** `sold_by`, `created_by`
  and `actor_principal_id`; *nothing errors*
- `docs/103` — there is **no writer at all**

> **P-C stays open.** Recommendation for the writer: **no reassignment
> operation**, because a principal's identity is entangled with attribution that
> `SET NULL` already erases silently. Disabling and creating anew is lossless in
> a way reassignment is not. **Not decided.**

---

## 7. S-A — service migration between customers

**Classification: UNIMPLEMENTED — and neither supported, planned, nor
explicitly forbidden.**

| Evidence | |
|---|---|
| writer | **none** — `mt_services` is written only by `Plugin/Simulator.php` (`docs/103`) |
| any document describing it | **none found** |
| schema | `customer_id` is `NOT NULL`; no update path; `ON DELETE RESTRICT` |
| nearest statement | `docs/101` §10 — *"Nothing is deleted because a uCRM relationship ended"*, and orphan row 8 (*linked service cancelled in uCRM*) is **not representable today** |
| lifecycle that **is** named | `status: active · suspended · ended` — `suspended` has no writer either (**U-9**, open) |

**Interaction with §2, and it is favourable:** with the composite FK in place
and `ON UPDATE NO ACTION`, changing `mt_services.customer_id` is **refused while
any site references it**. Service migration stops being something a stray
`UPDATE` can do and becomes an operation someone must design.

> **Recommendation: leave it forbidden-by-constraint and do not design migration
> behaviour now.** S-A stays open. If it is ever needed, it is a definer
> function that moves the service *and* its sites *and* their devices in one
> transaction with one audit trail — which is exactly the kind of thing that
> must not be improvised inside an integrity migration.

---

## 8. I-A — idempotency, per writer

### 8.1 The existing mechanism cannot be reused — measured, two independent reasons

```sql
CREATE TABLE mt_idempotency (
  key          text NOT NULL,
  customer_id  uuid NOT NULL REFERENCES mt_customers(id) ON DELETE RESTRICT,
  …
  PRIMARY KEY (customer_id, key)
);
-- FORCE ROW LEVEL SECURITY, policy: customer_id = mt_current_customer()
```

1. **It is keyed by `customer_id`, which does not exist yet at customer-creation
   time.** A retry of "create a customer" has nothing to key on. This is
   structurally the same problem `docs/89` solved for the attempt store: *a
   check that needs tenant data cannot run in exactly the case that produces
   it.*
2. **The staff plane cannot reach the table at all.** Measured:
   `dnb_adminwrite` holds **zero** table privileges —
   `has_table_privilege('dnb_adminwrite','mt_idempotency','INSERT') = false` —
   and the Admin plane never sets a tenant context, so the RLS policy would
   evaluate `customer_id = NULL` for every row even if it could.

> **Do not copy `POST /me/vouchers`.** That was the instruction; it is also the
> measurement. The one idempotent route in the system uses a mechanism that is
> unavailable to all four spine writers.

### 8.2 Retry semantics differ per writer

| Writer | Natural key | Retry today | Required |
|---|---|---|---|
| **customer create** | **none** — `name` is not unique; `radius_ref` is generated *by the insert* | a retry creates a **second customer** | an explicit key, in a store that does **not** require a `customer_id` |
| **principal create** | `phone`, **when non-NULL** — globally unique | duplicate phone → unique violation, which is an **error, not a replay** | must distinguish *"already created, identical request"* from *"different person, same number"*. **A NULL-phone principal has no natural key at all** |
| **service create** | **none whatsoever** — `customer_id` + `kind`, and `kind` has exactly one legal value | a retry creates a **second indistinguishable service** | an explicit key. This is the worst case of the four |
| **site create** | **none** — `(customer, service, name)`; `name` is not unique | a retry creates a **second site** | an explicit key |
| **device assign** | the device | **idempotent in state, not in record** — see below | decide whether a repeat is a replay or a reassignment |

**`mt_device_assign` measured:** it is an absolute-value `UPDATE`
(`customer_id = p_customer, site_id = p_site, name = p_name`), not a delta. Re-running
with identical arguments leaves identical state — but it also sets
`claimed_at = now()` and writes **another audit row**. And re-running with
*different* arguments is a legitimate **reassignment**, not a retry.

> So a blind *"same key ⇒ replay"* rule would be wrong for the one operation
> that looks most idempotent. `p_device` returns `NULL` rather than raising when
> the device does not exist, which a retry handler must not read as success.

### 8.3 Design conclusion

**I-A stays open and is a prerequisite for the writers, not a detail of them.**
What the evidence forces:

- the store **must not require tenancy** (reason 1) and **must be reachable by
  `dnb_adminwrite`** (reason 2) — so it is a new non-tenant store, the same
  shape of answer `docs/89` reached for a different reason;
- the key must be **supplied by the caller**, since four of five writers have no
  natural key;
- the digest must cover the request, as `mt_idempotency.request_digest` already
  does — *"so the same key with a different body is caught"*;
- **a unique violation is not a replay** until the stored request matches.

**Do not build it yet.** It is listed in §11.

---

## 9. U-1 — the remaining boundary

**U-1 is NOT closed here.** What follows is the boundary the evidence permits,
for approval.

### 9.1 What is now established

| | |
|---|---|
| a customer can exist with **no** uCRM link | `ucrm_client_id` nullable; measured |
| a device can exist with **no** customer | measured; Q5 = C, both workflows |
| a site **requires** a service | `service_id NOT NULL` |
| a site does **not** require a device | measured: 5 sites, 1 with none |
| commercial voucher activity does **not** require a device | `mt_vouchers` has no device column |
| voucher redemption must **never** consult uCRM | `docs/102`; forbidden, not merely unnecessary |
| **new:** audit must never be gated on a link | `docs/102` |
| **new:** a service has **no** uCRM reference column at all | U-5 |

### 9.2 Operation by operation

| Operation | uCRM **customer** link | uCRM **service** link | Why |
|---|---|---|---|
| `mt_customer_create` | **no** | — | equipment-first is real; gating here recreates the placeholder-customer defect `docs/102` forbids |
| principal create | **no** | — | a Domain-B login is an authentication artifact, not a commercial one. It grants access to an estate that may legitimately be empty |
| device register / stage / ship | **no** | — | *inventory is not ownership* (`docs/102`, L-1 closed) |
| `mt_device_assign` | **proposed: yes** | no | the first act that asserts *this customer owns this hardware*. **But it is not the only gate** — `docs/102` measured that a sale completes without it |
| **`mt_service_create`** | **proposed: YES — the first hard requirement** | **proposed: yes, at or immediately after creation** | a service is the first object that asserts a **billable relationship**. It is also where `docs/101`'s coherence rule lands: *the uCRM service's `clientId` must equal the client linked to that service's customer* — which cannot be checked unless both links exist |
| `mt_site_create` | **inherited** | inherited | a site cannot exist without a service, and §2 binds it to that service's customer. **No separate gate is needed or wanted** |
| plan create, voucher issue | **~~proposed: yes~~ — WITHDRAWN by `docs/110` §1** | **~~yes~~ — WITHDRAWN** | a stored uCRM link is **not** a prerequisite for HotSpot network operation. The error was treating commercial representation and technical capability as one requirement: a voucher sale is revenue to the **operator**, and becomes DishNet revenue only where DishNet bills that operator |
| voucher redemption | **FORBIDDEN** | forbidden | `docs/89`, `docs/102` |
| accounting ingest | **FORBIDDEN** | forbidden | `dnb_radius` holds one EXECUTE and no table privileges |
| any audit write | **FORBIDDEN** | forbidden | or the least-established records become the least recorded |

### 9.3 What the evidence rules out

- **A blanket `ucrm_client_id NOT NULL` (U-2).** Three independent reasons: it
  breaks equipment-first at the moment the customer first appears; `docs/100`
  proved an unlinked customer with history **cannot be deleted**; and it is
  gated on E-2 regardless.
- **Gating at `mt_customer_create`.** It is the one identity operation that must
  stay unconditional, or there is nowhere to put a customer who exists but is
  not yet papered.
- **Gating only at `mt_device_assign`.** Measured in `docs/102`:
  `service → site → plan → voucher` completes a sale with no device.

> **The proposed line: the uCRM link becomes mandatory at `mt_service_create`,
> and again at the commercial writes (B-2).** Everything before it — customer,
> principal, device possession — stays unconditional. **This is a proposal. U-1
> remains open**, and the service link (U-5) must be decided with it, because
> the coherence check needs both.

---

## 10. Site creation authorization model

### 10.1 The rule: **derive, never accept**

```
mt_site_create(p_service uuid, p_name text, p_location text, p_actor text) RETURNS uuid
                ▲
                └── there is NO p_customer parameter
```

`customer_id` is **read from the service row** inside the function and written
from that. The forgery is not rejected — it is **unrepresentable**, because the
caller has no way to express it.

### 10.2 Three layers, weakest last

| | Layer | Stops |
|---|---|---|
| 1 | **the composite FK (§2)** | every path, including a direct `UPDATE` by the owner. Proved to bind below RLS |
| 2 | **the function derives the customer from the service** | a caller naming the wrong customer |
| 3 | **the route never carries a customer** | the request shape itself |

This is the security evidence hierarchy from `docs/103` applied deliberately:
the constraint is evidence class 2–3, the function class 3, the route class 5.
**The route is the weakest and is never cited as the control.**

### 10.3 Per plane

**Staff plane** (`dnb_adminwrite`, definer function, **no tenant context**):
staff legitimately act across customers, so "the authenticated customer" does
not bound them. The bound is §10.1 — the service names the customer, and the
actor is a parameter from the Admin identity boundary, `actor_kind = 'staff'`,
with a W-1 audit row in the same transaction.

**Customer plane** (`dnb_app`, if ever exposed — *not proposed here*): the
service must be resolved **under the caller's own RLS context**, so a
`service_id` naming another customer's service resolves to **no row** and the
call refuses. It must never be resolved by a definer function that can see all
services and then checked afterwards.

> **The browser rule, explicitly:** a `service_id` arriving from a browser is
> *untrusted input naming a candidate*. It may only ever be **resolved within
> the caller's own visibility** and then used; it may never be trusted
> independently of the authenticated identity, and it may never be accompanied
> by a `customer_id` the server believes. This is the same discipline `docs/88`
> D-1a applies to `nas_claimed`: **untrusted context may reject early; it may
> never establish authority.**

### 10.4 Order

```
Domain-B customer  →  Domain-B service  →  site
                          ▲
                   U-1's gate lands here (§9.2), not on the site
```

### 10.5 What the writer must not do

- must not accept or trust a `customer_id`
- must not create a service implicitly when one is missing — **refuse**
- must not create a site for a service whose `status = 'ended'` without an
  explicit decision (unspecified today; flag, do not invent)
- must not be bound to an HTTP route — **W-4 is still open**

---

## 11. Exact implementation prerequisites

Nothing may be written until every one of these is true.

| # | Prerequisite | State |
|---|---|---|
| 1 | O-1 remediation (§2) **approved** | awaiting |
| 2 | the O-1 census (§4) has run against production and reports **CLEAR** | awaiting operator |
| 3 | **E-2** — the production census — has run | **still the gate**; `docs/79` |
| 4 | a per-row remediation decision exists for any mismatch | conditional on 2 |
| 5 | migration 020's own production authorization | still not authorized |
| 6 | **I-A** (§8) decided — the non-tenant idempotency store | open |
| 7 | **U-1** (§9) decided — where the link becomes mandatory | open |
| 8 | **U-5** decided with it — the service link column | open |
| 9 | **C6/C16** decided before any writer branches on `mt_principals.kind` | open since `docs/47` |
| 10 | **P-B** decided before the principal writer treats a duplicate phone | open |
| 11 | **W-4** — before any of this is reachable over HTTP | open |
| 12 | the §5 test battery, **including T-9**, written first | not started |

**The order is: O-1 → census → decisions 6–10 → writers.** `mt_site_create`
is step 3 of the spine and cannot be step 1.

---

## 12. Open items

| # | Item | Status | Waits on |
|---|---|---|---|
| **O-1** | composite FK `mt_sites → mt_services` | **designed, not authorized** | approval + census |
| **P-A** | `owner` vs `operator` | **RETIRED — duplicate of C6/C16** | C6 |
| **P-B** | globally unique phone | open, genuinely new | approval |
| **P-C** | principal reassignment | open; **recommendation: do not build it** | approval |
| **S-A** | service migration | **UNIMPLEMENTED**; forbidden-by-constraint once O-1 lands | approval |
| **I-A** | idempotency store | open — **existing mechanism proved unusable** | approval |
| **U-1** | where the uCRM link becomes mandatory | **open**; boundary proposed in §9.2 | approval |
| **U-2** | `ucrm_client_id NOT NULL` | open | **E-2** |
| **U-5** | service link | open | decide with U-1 |
| **U-6** | authoritative source for the OTP phone | open | approval |
| **U-9** | does a uCRM suspension suspend the service? | open | approval |
| **C6 / C16** | owner/operator roles; where staff records live | open since `docs/47` | PWA freeze |
| **W-4** | staff identity | open | approval |

---

**Stopping here. Suite unchanged: 1,599 assertions, 27 suites. No code, no
schema change, no migration, nothing installed, no gate moved. One question
retired, one defect fully characterised, nothing built.**
