# 73 — N10 remediation design, and Q2 held open

**Status: DESIGN AND EVIDENCE. Nothing implemented, nothing chosen.**

No migration edited, no project table, role or privilege created, no application
code changed, no production change. `dnb_site_nas` does not exist. The
provisioning writer is not built. F6 is not started.

**The invariant.**

```
  mt_devices.customer_id  =  mt_sites.customer_id     (for the device's own site_id)
```

It must hold **authoritatively in the control-plane database**, because the
provisioning writer is only a projector (docs/71 §2) and must never be
responsible for validating an upstream relationship it merely copies.

**How the evidence was obtained, stated so it can be objected to.** Measuring
NULL semantics, RLS interaction and migration safety is not possible by reading;
each candidate was therefore added to a **disposable** database built from the
real migrations with the real roles and real RLS, exercised, and the database
dropped. `tools/audit/n10_run.sh` does this end to end. No project migration,
table, role or privilege was created or altered outside that throwaway database.
If that crosses the line this gate drew, say so and the measurements can be
re-framed as proposals instead.

---

## 1. Corrections this gate forces

### 1.1 N10 is reachable from the REQUEST role, not only from `dnb_admin`

docs/71 §1.2 and docs/72 §A.5 described P-1 as an *administrative* path, while
saying admin-only was not a mitigation. **That description was too narrow.**

Measured (`n10_candidates3.php` §6′): `dnb_app` — the customer-facing request
role — holds `UPDATE` on `mt_devices` from migration 006's blanket grant
(`has_table_privilege('dnb_app','mt_devices','UPDATE') = true`). The tenant
policy is `USING/WITH CHECK (customer_id = mt_current_customer())`, which
constrains **`customer_id` and says nothing about `site_id`**. So inside its own
tenant context, `dnb_app` ran

```sql
UPDATE mt_devices SET site_id = <customer B's site> WHERE id = <customer A's device>
```

→ **`ALLOWED (rows=1)`**, invariant violated, **without** `mt_device_assign`
(`has_function_privilege('dnb_app', 'mt_device_assign…') = false`).

So the exposure is not a trusted-caller problem at all. A customer can point its
own device at another customer's site through an ordinary authenticated request
path. Under the projector this becomes AAA authorization. **This raises P-1's
severity and removes the last reason to treat function-level validation as
sufficient.**

### 1.2 My first measurement pass contained silent no-ops

`n10_candidates.php` ran several steps as the `owner` connection. `mt_devices`
and `mt_sites` have `FORCE ROW LEVEL SECURITY`, which binds the owner too, so
those `UPDATE`/`DELETE` statements **matched zero rows and measured nothing**,
while printing `ALLOWED`. One step also printed `REFUSED (N10)` where the real
error was *"constraint already exists"* — my matcher caught the constraint's
name. Nothing from those steps is used below. Passes 2–4 print **row counts** on
every act so a no-op cannot be mistaken for a pass, and the results reported here
come only from those passes. Recorded rather than quietly re-run, per the
standing practice in this project.

---

## 2. The decisive measurements

| | Measured | Result |
|---|---|---|
| **M1** | Can a **plain trigger** read `mt_sites` during assignment? | **No — `permission denied`.** `dnb_def_prov` has no grant on `mt_sites` at all (it is absent from migration 017's spec list). A plain trigger **breaks legitimate assignment**, matching *and* mismatching alike |
| **M2** | Can a **`SECURITY DEFINER` trigger owned by the table owner** read `mt_sites`? | **No — `N10: site INVISIBLE to dnb`.** Function owner measured as `dnb`; `mt_sites` has `FORCE ROW LEVEL SECURITY`, which binds the owner, and with no `app.customer_id` the predicate matches nothing. It refuses **matching** assignments too |
| **M3** | Does a **composite FK** refuse the mismatch through `mt_device_assign`? | **Yes — `23503 foreign key violation`.** RI checks are not subject to RLS, which is why the existing single-column `site_id` FK works at all. **Only the FK can validate against `mt_sites` without granting anyone new read access** |
| **M4** | `MATCH SIMPLE` and the partial-NULL hole (`site_id` set, `customer_id` NULL) | **ALLOWED (rows=1)** — the hole docs/72 §A.7 flagged is real |
| **M5** | Does `MATCH FULL` close it? | **Yes** — refused. And both-NULL unassigned stock is still allowed |
| **M6** | Does `MATCH FULL` survive **site deletion**? | **No — `REFUSED (23503)`.** The existing `site_id … ON DELETE SET NULL` nulls `site_id` and leaves `customer_id` set, which `MATCH FULL` forbids. **`MATCH FULL` breaks site deletion** |
| **M7** | Does a one-table **CHECK** close the hole without that cost? | **Yes.** `CHECK (site_id IS NULL OR customer_id IS NOT NULL)` refuses the partial-NULL state, and site deletion still succeeds with `customer_id` intact |
| **M8** | Can `dnb_admin` get around a trigger? | `SET session_replication_role='replica'` → **refused**; `DISABLE TRIGGER ALL` → **refused** (must be owner); `DROP TRIGGER` → **refused**; `SET ROLE dnb_def_prov` → **refused** |
| **M9** | Can `dnb_admin` bypass **function-level** validation? | **Yes.** It holds `UPDATE` and `INSERT` on `mt_devices` directly, and a direct `UPDATE` succeeded |
| **M10** | **Migration safety:** does `ADD FOREIGN KEY` validate existing rows? | ***No.*** Owner sees **0 rows** under FORCE RLS; `ADD` returned `ALLOWED`, `convalidated = true`, **the violating row survived**, and a BYPASSRLS count confirmed **1 violation still present**. The constraint reports success and enforces nothing for pre-existing rows |

**M10 is the finding most likely to be missed in implementation.** A remediation
migration written the obvious way would pass, be marked validated, and silently
bless every existing violation.

### 2.1 The combination, measured against every reachable state

Composite FK (`MATCH SIMPLE`) **+** `CHECK (site_id IS NULL OR customer_id IS NOT NULL)`:

| | State | Result | Why it must be that |
|---|---|---|---|
| 1 | unassigned stock — both NULL | **ALLOWED** | `mt_device_register` inserts this; stock belongs to nobody |
| 2 | assign matching, via the function | **ALLOWED** | the legitimate path must keep working |
| 3 | assign **mismatched**, via the function | **REFUSED (FK)** | N10 |
| 4 | `site_id` set, `customer_id` NULL | **REFUSED (CHECK)** | closes M4's hole |
| 5 | `customer_id` set, `site_id` NULL | **ALLOWED** | claimed but not yet sited is legal |
| 6 | reassign to another site of the **same** customer | **ALLOWED** | ordinary operations |
| 7 | **delete** the site a live device points at | **ALLOWED**, `customer_id` survives | M6's cost avoided |
| 8 | `dnb_app` re-points its own device at another customer's site | **REFUSED (FK)** | closes §1.1 |

---

## 3. The three candidates across the required dimensions

**C1** = composite FK (+ CHECK). **C2** = trigger. **C3** = function-level validation only.

| Dimension | C1 composite FK + CHECK | C2 trigger | C3 in-function only |
|---|---|---|---|
| **NULL behaviour** | `MATCH SIMPLE` allows partial NULL (M4) — closed by the CHECK (M7). `MATCH FULL` closes it but costs deletion (M5, M6) | whatever is written; easy to get wrong silently (`IF NOT FOUND THEN RETURN NEW` passes everything) | same |
| **Existing foreign keys** | coexists with `site_id → mt_sites ON DELETE SET NULL` and `customer_id → mt_customers`; needs `UNIQUE (id, customer_id)` on `mt_sites`, which is additive | unaffected | unaffected |
| **RLS / FORCE RLS** | **the deciding dimension. RI checks are not subject to RLS**, so the FK validates `mt_sites` with no new read grant (M3) | **fails** — no role can read `mt_sites` without a tenant context; even a definer trigger owned by the table owner is blocked by FORCE RLS (M1, M2). Needs **new privilege** on `mt_sites` | reads `mt_sites` from inside `mt_device_assign`, owned by `dnb_def_prov`, which has **no grant on `mt_sites`** — so it has the same problem as C2 |
| **Unassigned stock** | preserved (state 1) | preserved if coded for it | preserved |
| **Device registration** | unaffected — `mt_device_register` writes both NULL | must special-case NULL | unaffected |
| **Device assignment** | mismatch refused at the table (state 3) | **matching assignments also refused** as measured (M1, M2) | refused only through this one function |
| **Reassignment** | same-customer reassign allowed (state 6) | fires on every UPDATE | only via the function |
| **Deletion** | site deletion works, `customer_id` survives (state 7); `MATCH FULL` would break it (M6) | fires on UPDATE, not on the parent DELETE — the `SET NULL` cascade is invisible to it | not involved |
| **Migration safety** | **`ADD` validates nothing under FORCE RLS and is marked valid (M10)** — a backfill/validation step is mandatory | `CREATE TRIGGER` validates no existing row either, and never claims to | nothing to migrate; nothing fixed |
| **Bypassable by an authorized caller** | **No.** Constraints are not privileges; no role short of the table owner can drop them | **No** for `dnb_admin` (M8) — but moot, since it blocks legitimate writes | **Yes (M9)** — `dnb_admin` holds direct `UPDATE`/`INSERT`; and **`dnb_app` does too (§1.1)** |
| **Audit implications** | a refusal is a database error, so the act never happens and needs no audit row. Does **not** fix the separate gap that `mt_device_assign` writes no audit row (docs/72 §A.4) | could log, but only by writing from a trigger — a new write path | could log in the function, which is where audit belongs — its one real advantage |
| **Interaction with `dnb_def_prov`** | none — no grant, policy or role change (its `USING (true)` policy stays as designed) | requires **relaxing** the boundary: read access to `mt_sites` for the provisioning role | same requirement |
| **Interaction with the `dnb_site_nas` projector** | the projector reads a relationship the database guarantees; N3 (projection faithfulness) becomes meaningful | same if it worked | the projector would inherit whatever a non-function path wrote |

---

## 4. Recommended mechanism

**C1 — the composite foreign key, `MATCH SIMPLE`, plus the one-table CHECK,
plus a validating backfill step.** In full:

```
  mt_sites   ADD UNIQUE (id, customer_id)
  mt_devices ADD FOREIGN KEY (site_id, customer_id)
               REFERENCES mt_sites (id, customer_id)          -- MATCH SIMPLE
  mt_devices ADD CHECK (site_id IS NULL OR customer_id IS NOT NULL)
```

**Why, on the evidence:**

1. **It is the only candidate that can see `mt_sites` at all.** RI checks are not
   subject to RLS; every procedural candidate is (M1, M2). C2 and C3 would each
   require granting the provisioning role read access to `mt_sites` — widening the
   boundary in order to protect it.
2. **It is the only candidate that cannot be bypassed.** C3 is bypassed by
   `dnb_admin` (M9) *and* by `dnb_app` (§1.1). A constraint is not a privilege.
3. **It refuses exactly the wrong states and nothing else** — all eight states in
   §2.1 behave as the lifecycle requires, including unassigned stock and site
   deletion.
4. **It needs no new role, grant or policy.** `dnb_def_prov`'s deliberate
   `USING (true)` exemption stays exactly as migration 017 designed it.
5. **`MATCH SIMPLE` + CHECK is strictly better than `MATCH FULL`**, measured:
   same protection, without breaking site deletion (M5/M6 vs M7).

**Two conditions that are part of the recommendation, not footnotes:**

- **A validating backfill is mandatory (M10).** The migration must find existing
  violations through a path not blinded by FORCE RLS, decide what to do with each
  (the safe default being to null `site_id`, since a wrong site is worse than no
  site), and only then add the constraint — or add it `NOT VALID` and `VALIDATE`
  deliberately. A migration that simply adds the constraint will report success
  and enforce nothing for existing rows.
- **C3 remains worth adding on top, for two non-security reasons:** a clearer
  error than `23503` at the one path operators actually call, and the natural
  place to write the audit row that docs/72 §A.4 found missing. It is **not** the
  enforcement and must never be described as such.

**What the recommendation does not fix:** `mt_device_assign` still writes no audit
row, and `dnb_app` still holds a blanket `UPDATE` on `mt_devices` that no
requirement has justified. Both are separate findings, neither closed by N10.

---

## 5. Q2 — held open, unchanged

**`Can one physical MikroTik HotSpot/NAS serve multiple DishNet sites?` —
NOT ESTABLISHED.** Nothing in this gate touched it; docs/72 Part B stands.

**The smallest real-world evidence that would answer it** — one question, to the
operator, about intent rather than about current stock:

> Will DishNet ever install **one** MikroTik HotSpot gateway serving **two or more
> separately-billed DishNet sites** — two unrelated businesses sharing one
> building's connection, or an ISP customer putting several downstream businesses
> behind a single box? If it happens today, in how many places?

**C20 is not altered**, per the instruction: no demonstrated reason has appeared.
docs/72 §B.2 records that C20 as written cannot answer Q2 — it counts locations
and assesses one router — but a question that cannot be answered by an instrument
is not itself a reason to change the instrument. If the operator answers directly,
C20 never needs touching.

### 5.1 Does Q2 change the eventual `dnb_site_nas` cardinality?

**Yes — that is precisely why it blocks.**

| | Q2 = **NO** | Q2 = **YES** |
|---|---|---|
| Key | `PRIMARY KEY (nas_ip)` | `PRIMARY KEY (site_id, nas_ip)` |
| N4 (a NAS in at most one site) | **enforced by the schema** | **abandoned** — it becomes legal |
| Reassignment | one `UPDATE`, atomic, inherent (docs/70 S3) | delete + insert in a transaction; stale removal rests on the writer |
| P-2 (shared NAS) | closed by construction | becomes a **first-class authorization question**, needing its own decision |
| Control-plane change | none — `mt_devices.site_id` already matches | a device↔site relation is required (docs/72 §B.3.1), materially larger |

N10 is **independent of Q2** and can be authorized first: the invariant is
`device → site`, not `site → NAS`.

---

## 6. Approvals required before any implementation

| | Approval | Scope |
|---|---|---|
| **1** | **The N10 mechanism** — composite FK (`MATCH SIMPLE`) + `UNIQUE (id, customer_id)` on `mt_sites` + the CHECK | A **control-plane schema change**. Not part of Decision 7 |
| **2** | **The backfill policy** (M10) — how existing violations are found and what is done with each | Decides whether the constraint enforces anything for existing rows |
| **3** | **A guard test** asserting each of §2.1's eight states, including the `dnb_app` path from §1.1 | Additions to the suite; changes the assertion count |
| **4** | *(optional, separable)* the in-function check and its audit row | Clearer errors and the missing audit trail; **not** the enforcement |
| **5** | **Q2's answer**, then the `dnb_site_nas` key | Still blocking the provisioning writer |

Items 1–3 are the N10 remediation and can proceed on their own. Item 5 remains the
blocker for `dnb_site_nas` and the writer.

**Unchanged:** F1–F13 frozen. Decisions 1, 2a, 2b, 3, 7 as recorded. **F6 NOT
AUTHORIZED.** Production FreeRADIUS and production PostgreSQL untouched. docs/71
remains an unapproved design.
