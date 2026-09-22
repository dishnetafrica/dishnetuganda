# 77 — Voucher site decision (CLOSED), corrected scope, and the census protocol

**Status: DECISION RECORD + OPERATOR PROTOCOL. No migration written or
authorized.** No constraint installed, no table, role or privilege created, no
application code changed, no production contact. `dnb_site_nas` is not designed
or created and its cardinality is not chosen. F6 is not authorized.

---

## 1. Decision — CLOSED

> **Voucher site-null domain question — CLOSED (approved 2026-09-21).**
> Under Decision 2b (SITE-BOUND), **a voucher may not be issued without an
> issuing site.** **Option A — reject at creation.**

**Rationale, as recorded:**

1. No legitimate pre-site voucher lifecycle is documented or implemented.
2. The existing four voucher states contain no *"unsited"* state.
3. C20 Q3 says vouchers are generated **per site**.
4. The existing suite's site-less vouchers are **test debt**, not evidence of a
   legitimate product workflow.
5. Under Model B a site-less voucher can be created, printed and sold but can
   **never** obtain the site authorization required for AAA publication.
6. Defaulting to a site would **infer ownership**, violating the established
   *never guess ownership* rule.
7. An explicit holding state introduces lifecycle complexity with no evidence of
   a business requirement.

**Resulting invariant — for issued vouchers, both must hold:**

```
  mt_vouchers.site_id IS NOT NULL
  mt_vouchers.customer_id = mt_sites.customer_id   (for that site_id)
```

**This is a decision, not a migration.** Nothing is implemented.

---

## 2. Correction — `mt_voucher_batches.site_id` is **NULLABLE**, not NOT NULL

The approved scope note recorded *"mt_voucher_batches — site_id already NOT
NULL."* **That is not the case.** Measured directly from `pg_attribute` on a
freshly migrated schema:

| Table | `customer_id` | `site_id` |
|---|---|---|
| `mt_devices` | NULLABLE | NULLABLE |
| `mt_plans` | **NOT NULL** | NULLABLE |
| `mt_voucher_batches` | **NOT NULL** | **NULLABLE** |
| `mt_vouchers` | **NOT NULL** | **NULLABLE** |

The NOT NULL column on those three tables is **`customer_id`**, not `site_id`.
`mt_voucher_batches.site_id` is declared
`uuid REFERENCES mt_sites(id) ON DELETE SET NULL` — nullable, exactly like
`mt_vouchers.site_id`. Consistent with docs/76 §A.5, where all three fixture
batches carried `site_id NULL`.

### 2.1 A consequence that needs confirming, not assuming

`issueBatch()` takes **one `$siteId`** and writes it to the batch row *and* to
every voucher in it. So under §1:

- a batch with `site_id NULL` can only ever produce vouchers with `site_id NULL`
- and those are now forbidden
- therefore **a NULL-site batch can no longer produce a single valid voucher**

**This is an argument for `mt_voucher_batches.site_id NOT NULL`. It is not a
consequence of §1 and must not be treated as one.** §1 was approved on evidence
about *vouchers*; extending a NOT NULL to a second table on a propagation
argument is the inference this process refuses.

**`mt_voucher_batches.site_id NOT NULL` is an OPEN SCHEMA DECISION**, pending
production evidence and explicit approval — docs/78 §4.2 states both options and
their consequences. It is not "likely", not "pending", and not scheduled. A
NULL-site batch may already contain vouchers, some sold or used, so adding the
constraint blindly would turn a data-cleanup question into a migration failure.
The census counts exactly that, and the decision waits for the number.

---

## 3. Migration scope — corrected

| | Table | Constraints | Notes |
|---|---|---|---|
| — | `mt_sites` | `UNIQUE (id, customer_id)` | shared prerequisite for every composite FK below; additive |
| **T1** | `mt_devices` | composite FK `MATCH SIMPLE` **+** `CHECK (site_id IS NULL OR customer_id IS NOT NULL)` | `customer_id` is NULLABLE, so the partial-NULL state is reachable (docs/73 M4/M7) |
| **T2** | `mt_vouchers` | **`site_id NOT NULL`** (§1) **+** composite FK `MATCH SIMPLE` | no CHECK needed — `customer_id` is NOT NULL, so partial-NULL is unrepresentable |
| **T3** | `mt_voucher_batches` | composite FK `MATCH SIMPLE`; **`site_id NOT NULL` pending §5 confirmation** | `site_id` is **NULLABLE today** — see §2 |
| **T4** | `mt_plans` | **NONE — explicitly OUT OF SCOPE** | not in the AAA chain (docs/75 §2). Must not be added because the column names match |

Each constraint carries docs/73 **M10**'s requirement: `ADD FOREIGN KEY` as the
table owner scans **zero rows** under `FORCE ROW LEVEL SECURITY` and is still
marked `convalidated = true`, so each must be added `NOT VALID`, backfilled,
then `VALIDATE`d with the result asserted.

---

## 4. The production census protocol

**`tools/audit/production_census.sql`** — run by an operator with access:

```
psql "<production control-plane DSN>" -f tools/audit/production_census.sql
```

### 4.1 What makes it safe to run on production

- **The whole run is inside `BEGIN; SET TRANSACTION READ ONLY;` and ends in
  `ROLLBACK`.** The database refuses any write the file could contain — verified:
  an `UPDATE` in such a transaction returns
  `ERROR: cannot execute UPDATE in a read-only transaction`. Read-only is a
  property the engine enforces, not a promise the author makes.
- **It repairs nothing.** No row is altered, quarantined or deleted.
- **It discloses nothing sensitive.** Output is counts only — no customer names,
  phone numbers, voucher codes, credentials, addresses or secrets.

### 4.2 What it reports

| Section | Contents |
|---|---|
| **0** | server, database, user, and **whether that role can bypass RLS** |
| **1** | **deployment evidence from the database itself** — whether each `mt_*` table exists, whether `mt_migrations` exists, and every migration filename applied |
| **2** | `mt_devices`: total, unsited, **partial-null**, **cross-customer**, orphaned, decommissioned-but-sited, sited-without-`tunnel_ip` |
| **3** | `mt_vouchers`: total, **NULL site**, **cross-customer site**, orphaned, and how many site-less vouchers are **already sold or used** — those cannot simply be voided. `mt_voucher_batches`: total, NULL site, cross-customer site |
| **4** | for **each proposed constraint**, the number of rows that would block it from validating today |
| **5** | what the census cannot establish (§4.4) |

### 4.3 The blinding guard

Section 0 checks `rolsuper` / `rolbypassrls` and, if neither, prints:

> *** WARNING — THIS CENSUS MAY BE BLINDED *** … Without superuser or BYPASSRLS
> the counts below can read 0 while rows exist. **A zero from this run is NOT
> evidence of an empty table.**

This is not defensive decoration. It is the specific failure this project has
already hit twice — docs/72 §A.4 (an empty `mt_audit_log` that was not empty) and
docs/73 M10 (a constraint that validated nothing). **Verified: the warning fires
for a non-bypassing role.**

### 4.4 What the census cannot establish

It speaks for **one database**. If the schema is absent, that is authoritative
for *that* database only. To establish that **no control plane is deployed
anywhere**, the operator must also confirm, and state how:

- **(a)** no other database on any host holds these `mt_*` tables;
- **(b)** no application or container is running against such a database;
- **(c)** the DSN used for the run is the one any deployment would use.

**Absent (a)–(c), production state stays `NOT ESTABLISHED`.** Per instruction,
an undeployed production must be established through **authoritative deployment
evidence, not inferred from documentation** — so docs/00 §546's *"Not yet
allowed"* and docs/55 §416's *"not deployable until answered"* remain **context,
not evidence**, however strongly they point.

### 4.5 Self-tests run before handing it over

| | Case | Result |
|---|---|---|
| A | migrated database with fixtures | reports every section correctly |
| B | database with **no `mt_*` tables** | reports schema absent, and correctly limits the claim to that database |
| C | role **without** BYPASSRLS | blinding warning fires |
| D | a write inside the read-only transaction | **refused by the engine** |

---

## 5. Remaining blockers

| | Item | State |
|---|---|---|
| **1** | **Production census output** — §4, run by someone with access | **BLOCKING.** This session cannot reach it |
| **2** | **Confirm or reject `mt_voucher_batches.site_id NOT NULL`** (§2.1) — a consequence of §1, deliberately not assumed | **pending** |
| **3** | Backfill treatment applied per table, under *never guess ownership* (docs/74 §7) — including what happens to **site-less vouchers already sold or used**, which the census counts separately because voiding them is not free | pending |
| **4** | Validation proof per constraint (docs/73 M10) | pending |
| **5** | **Test debt** — every site-less `issueBatch` call site must pass a real site once `NOT NULL` lands. **This will move the assertion count off 718** | pending |
| **6** | Guard tests — docs/73 §2.1's eight states, plus the cross-customer insert measured in docs/75 §Q5 for T2/T3 | not written |
| **7** | **Q2** — *can one physical MikroTik HotSpot/NAS serve multiple DishNet sites?* | **NOT ESTABLISHED**; C20 unaltered; `dnb_site_nas` cardinality **not chosen** |

## 6. State

| | |
|---|---|
| Decision 2b | **CLOSED — site-bound** |
| **Voucher site-less creation** | **CLOSED — rejected (Option A)** |
| Decision 2a | **CLOSED — C-b, site-keyed** |
| Integrity scope | `mt_devices` + `mt_vouchers` + `mt_voucher_batches`; `mt_plans` **out** |
| Migration | **NOT AUTHORIZED** |
| Production data state | **NOT ESTABLISHED** |
| Q2 / `dnb_site_nas` cardinality | **NOT ESTABLISHED / not chosen** |
| provisioning writer | not designed, not created |
| F6 | **NOT AUTHORIZED** |
| F1–F13 | **FROZEN** |
| Decisions 1, 2a, 2b, 3, 7 | unchanged |
