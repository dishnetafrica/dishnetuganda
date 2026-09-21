# 74 — N10 backfill: data state, and what it does not establish

**Status: EVIDENCE. The migration is NOT authorized and NOT written.**

No migration created or edited, no constraint installed anywhere persistent, no
project table, role or privilege created, no application code changed, no
production contact. `dnb_site_nas` does not exist and its cardinality is
untouched.

---

## 0. The distinction this gate turns on

**Two different things were measured, and only one of them exists.**

| | What it is | State |
|---|---|---|
| **Fixture / disposable state** | Databases this session builds from the migrations and drops again | **MEASURED** below |
| **Production state** | Whatever rows a deployed control plane actually holds | **NOT ESTABLISHED** |

**A zero in the fixture is not a zero in production.** The census below reports
zero violations; that is a fact about a database this session created minutes
ago, and it carries **no** information about production.

### 0.1 Why production state cannot be established here

- **No authoritative production evidence exists in the repository.** The only
  recorded production row counts are RADIUS-side — `radcheck`, `radreply`,
  `radacct`, `nas` all at 0 rows (docs/00 §525). **Nothing anywhere records a
  production count of `mt_devices` or `mt_sites`.**
- **This session cannot reach the Phase 0 server** — no SSH client, `~/.ssh/`
  empty, egress to the host denied. Unchanged from earlier gates.

### 0.2 What the repository says, and why it is still not evidence

Two statements bear on whether a production control plane exists at all:

- **docs/00 §546, "Not yet allowed":** *production control plane · production
  voucher system · customer deployment · reseller deployment · application
  deployment · **production migrations***
- **docs/55 §416:** where the control plane runs is *"Not answered… Buildable
  deployment-agnostic; **not deployable until answered.** The first
  infrastructure decision."*

Together these make it **likely** that no production control-plane database
exists, and therefore likely that the backfill has nothing to act on. But both
are statements of **permission and intent, not measurements of data.** This
project's standing rule — do not assume functionality exists just because there
is code, and do not assume it is absent just because a document forbids it —
applies in both directions. **Production state remains NOT ESTABLISHED, and the
backfill must be designed as though violations could exist.**

### 0.3 How production state would be established

`tools/audit/n10_backfill_census.php` reads `DNB_DSN` and is **read-only** — it
counts and classifies, and writes nothing. It was deliberately written so the
same script can be run by an operator against a production control plane, if one
exists, and its output pasted back. It connects as the **inspector** identity
(`BYPASSRLS`) and says why: *a census blinded by RLS is not a census* — the same
trap that produced a wrong reading in docs/72 §A.4 and a silently empty
validation in docs/73 M10.

---

## 1. Current `mt_devices` state

**Census A — freshly migrated schema, no fixtures.** The empty baseline, proving
the census reports zero on an empty database rather than failing silently.

**Census B — the fixture state `tests/run.sh` leaves in `dnb_test`.**

| | Census A (empty) | Census B (fixture) |
|---|---|---|
| total devices | 0 | **2** |
| unassigned (both NULL) | 0 | 0 |
| claimed, unsited (customer set, site NULL) | 0 | 0 |
| **partial NULL (site set, customer NULL)** | 0 | **0** |
| fully assigned | 0 | 2 |
| no `tunnel_ip` | 0 | 0 |

## 2. Existing N10 violations

| | Census A | Census B |
|---|---|---|
| devices whose site belongs to **another customer** | **0** | **0** |
| devices whose `site_id` points at no site | 0 | 0 |

**Zero in both — and that is a statement about these two databases only.** §0
governs what it means for production: nothing.

## 3. Partial-null cases

**Zero** in both censuses. The `CHECK (site_id IS NULL OR customer_id IS NOT
NULL)` would therefore reject nothing that currently exists in either database.
It remains necessary: docs/73 M4 measured that the state is *reachable* under
`MATCH SIMPLE`, and reachable-but-absent is exactly the condition a constraint is
for.

## 4. Ambiguous relationships

| State | Census B | What it means |
|---|---|---|
| fully assigned but **no `tunnel_ip`** | 0 | **not an N10 violation** — but unprojectable: the projector has no address to publish. A projector gap, not a backfill case |
| **decommissioned yet still holding a site** | 0 | the T10 shape (docs/71 §5). Would leave a site authorizing a retired router |
| sited but **not `active`** | **2** | both fixture devices. Raises a question the design has not answered: **does the projector publish a site→NAS row for a device that is not yet `active`?** Not an N10 matter; recorded for the writer's design |
| two devices sharing a `tunnel_ip` | 0 | prevented by `tunnel_ip UNIQUE` |
| **sites with more than one device** | **1** | informational. This is *many routers per site* — the question still open at docs/68 §2.6b — **not** Q2, which asks the converse. Noted because it already occurs in fixtures |

## 5. Fixture and test impact — measured

`tools/audit/n10_suite_impact.sh` builds a disposable database, applies all three
proposed constraints, then runs **every** suite against them.

```
  18 suites, all ok
  TOTAL assertions under the N10 constraints: 718
  SUITE RESULT: ALL PASSED with the constraints in place
```

Identical to the unconstrained baseline (718, green). **The constraints break no
fixture, no seeding path and no existing assertion.** `seed_two_customers()`
builds customers, sites and devices through the real application paths, and all
of it satisfies N10 already.

This measures *compatibility*, not *coverage*: nothing in the suite currently
asserts that a violation is refused. That guard test is docs/73 §6 item 3 and
still needs writing.

## 6. A scope finding: the same defect class on three more tables

Four tables carry **both** `customer_id` and `site_id`, and **none** has any
constraint tying them:

| Table | composite FK | rows violating the same rule (Census B) |
|---|---|---|
| `mt_devices` | **NO** | 0 |
| `mt_plans` | **NO** | 0 |
| `mt_voucher_batches` | **NO** | 0 |
| **`mt_vouchers`** | **NO** | 0 |

`mt_vouchers` matters most and is **not** covered by N10 as written. Under
Decision 2b a voucher is bound to its issuing site; a voucher row whose `site_id`
belongs to a different customer than its `customer_id` is that binding broken at
the source — the same class of defect, one table over, on the object the whole
site-binding decision is about.

**Not proposed here.** N10 as approved in principle is `mt_devices` only. Whether
to extend the same mechanism to the other three is a **separate decision** and is
listed in §9. Recording it now because a remediation that fixes one table and
leaves the other three is a partial fix that will read as a complete one.

## 7. Proposed backfill treatment, per violation class

**Governing rule: never guess ownership.** Each violation has two readings —
*wrong customer* or *wrong site* — and they are not distinguishable from the data.
Changing `customer_id` would silently move a device between customers; changing
`site_id` to NULL only removes an authorization. **A wrong site is worse than no
site**, so every automatic treatment below removes the site and nothing else.

| | Class | Automatic treatment | Why |
|---|---|---|---|
| **V1** | fully assigned, site belongs to another customer | **`site_id := NULL`**, one quarantine record per row (device, old site, both customers, timestamp) | Fails closed: the device stops being projectable, nothing is silently reassigned. Requires operator adjudication afterwards |
| **V2** | `site_id` set, `customer_id` NULL | **`site_id := NULL`** + quarantine record | The site's owner *is* knowable, but adopting it would infer device ownership **from a site reference** — the exact direction of trust N10 exists to refuse |
| **V3** | `site_id` references a missing site | **`site_id := NULL`** | Should be impossible under the existing FK; treated anyway, because the constraint may have been added `NOT VALID` or the row may predate it |
| **V4** | decommissioned device still holding a site | **`site_id := NULL`** | A retired router must not keep a site authorized (T10) |
| **V5** | sited device with no `tunnel_ip` | **no change** — report only | Not an N10 violation. It is a projector input gap and belongs to the writer's design |

**Three requirements on the migration itself, all consequences of measurement:**

1. **It must not run blind.** docs/73 M10 measured that `ADD FOREIGN KEY` as the
   table owner scans **zero rows** under `FORCE ROW LEVEL SECURITY`, marks the
   constraint `convalidated = true`, and leaves every existing violation in
   place. The census and the backfill must both run through a path that is not
   RLS-blinded, and the migration must **prove** it validated — e.g. add
   `NOT VALID`, run the backfill, then `VALIDATE CONSTRAINT` and assert it.
2. **The census must run before and after**, with both outputs recorded. A
   backfill whose effect nobody counted is the swallowed-error pattern this
   project keeps finding.
3. **Quarantine records must survive the migration.** If V1/V2 rows exist, what
   was removed must be recoverable — otherwise the fix destroys the evidence of
   what it fixed.

**If the census returns all zeros in the target database**, the backfill is a
no-op and the migration reduces to adding the three constraints plus the
validation proof in requirement 1. That is the *likely* production case per §0.2
— and it must still be **run and recorded**, not assumed.

## 8. Q2 and `dnb_site_nas` — confirmed untouched

- **Q2 — *can one physical MikroTik HotSpot/NAS serve multiple DishNet sites?* —
  remains `NOT ESTABLISHED`.** Nothing in this gate touched it. The one question
  that would answer it is docs/73 §5; **C20 remains unaltered.**
- **`dnb_site_nas` cardinality is untouched.** No key chosen, both branches still
  open exactly as docs/73 §5.1 records. The table does not exist.
- §4's *"1 site with more than one device"* is the **converse** question (many
  routers per site, docs/68 §2.6b) and is **not** evidence about Q2.
- **N10 remains independent of Q2** and can be authorized without it.

## 9. What is still required before a migration is authorized

| | Item | State |
|---|---|---|
| **1** | **Production census output** — `n10_backfill_census.php` run against the production control plane, if one exists, by someone who can reach it | **BLOCKING.** Not obtainable in this session |
| **2** | Approval of the **backfill treatment table** in §7, especially V1/V2 nulling the site rather than adopting a customer | pending |
| **3** | Approval of the **three migration requirements** in §7 — validation proof, before/after census, durable quarantine | pending |
| **4** | The **guard test** for docs/73 §2.1's eight states, including the `dnb_app` path | not written; will change the assertion count |
| **5** | A decision on **§6** — whether `mt_plans`, `mt_voucher_batches` and especially `mt_vouchers` get the same treatment | **new; not previously on any list** |
| **6** | **Q2**, then the `dnb_site_nas` key | still blocking the provisioning writer |

**Unchanged:** F1–F13 frozen. Decisions 1, 2a, 2b, 3, 7 as recorded. **F6 NOT
AUTHORIZED.** Production FreeRADIUS and production PostgreSQL untouched. docs/71
remains an unapproved design; docs/73's mechanism is approved in principle and
its migration is **not** authorized.
