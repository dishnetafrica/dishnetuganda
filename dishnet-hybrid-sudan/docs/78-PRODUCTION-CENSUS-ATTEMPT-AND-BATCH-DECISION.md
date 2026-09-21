# 78 — Production census: attempted, not obtained. And decision B, framed.

**Status: EVIDENCE REPORT. No migration, no schema change, no production
contact achieved.** Nothing implemented. `dnb_site_nas` not designed or created,
cardinality not chosen. F6 not authorized.

---

# 1. PRODUCTION EVIDENCE

## 1.1 Result: **NOT OBTAINED. Production state remains `NOT ESTABLISHED`.**

The census was to be run against the control-plane DSN the deployed Domain B
service would use. **This session cannot reach it, and the reason is structural,
not incidental.** Measured now, not recalled:

| Check | Result |
|---|---|
| `ssh`, `scp`, `sshpass`, `autossh` | **all ABSENT** |
| `~/.ssh/` | **empty** |
| Any production DSN configured in this environment | **none.** No `DNB_*` or `PG*` variable is set; the only `.env` files in the tree belong to `dishnet-mail`, a different project; every `DNB_DSN` reference in the repo is the test harness or `Database.php` itself |
| HTTPS to the Phase 0 host | **`CONNECT tunnel failed, response 403`** — denied by the egress policy |
| Direct PostgreSQL to that host on 5433 | **`timeout expired`** |
| Is it even reachable in principle? | **No.** `dn-phase0-postgres` is documented as `127.0.0.1:5433` — **loopback only** (docs/00 §493). It is not remotely reachable by design, so no credential or network route would let this session run the census. It can only be run **on that host** |

**Conclusion: there is no path by which this session can produce the census.**
It is an operator action, and §1.3 is the runbook.

## 1.2 What is *not* claimed

- **Not claimed:** that production is clean.
- **Not claimed:** that production is undeployed.
- **Not claimed** that docs/00 §546 (*"Not yet allowed: production control
  plane… production migrations"*) or docs/55 §416 (*"not deployable until
  answered"*) settle it. Per instruction, an undeployed production must be
  established by **authoritative deployment evidence**, not inferred from what a
  document permits. Those remain context.

Every figure in docs/74 and docs/76 came from disposable databases built minutes
earlier. **None of them carries any information about production.**

## 1.3 The runbook — what the operator runs, and what comes back

```
psql "<production control-plane DSN>" -f tools/audit/production_census.sql
```

Run it as a role with **`BYPASSRLS` or superuser**. The seven requested items map
to the script's sections:

| | Requested | Where |
|---|---|---|
| **1** | is the Domain B schema actually deployed | §1 — existence of each `mt_*` table |
| **2** | migration / deployment evidence from the database | §1 — `mt_migrations` existence and every filename applied |
| **3** | `mt_devices`: total, NULL `site_id`, partial NULLs, mismatches, orphans, decommissioned-but-sited, **duplicate/shared tunnel IPs** | §2 — including a distinct-vs-total `tunnel_ip` comparison, whether the `UNIQUE` constraint is still present, and a note that **reuse of a retired tunnel IP is not detectable from current rows** (no history), which is T10 |
| **4** | `mt_voucher_batches`: total, NULL `site_id`, mismatches, **orphans** | §3 — plus **NULL-site batches that already have vouchers**, since every such voucher is necessarily site-less |
| **5** | `mt_vouchers`: total, NULL site, mismatches, orphans, **NULL-site unused**, **NULL-site sold/active/expired/revoked** | §3 — a per-state breakdown, the voidable count, the not-unused count, and how many carry a `sold_at` |
| **6** | role / RLS visibility evidence | §0 warns before the counts; §5 **restates it after them**: *every count above is a LOWER BOUND, not a total* |
| **7** | exact database/DSN identity, no credentials | §0 — database, user, server address and port. **No password or connection string is read or printed** |

**Safety, verified rather than asserted:** the whole run is inside
`BEGIN; SET TRANSACTION READ ONLY;` and ends in `ROLLBACK`. An `UPDATE` inside
such a transaction returns `ERROR: cannot execute UPDATE in a read-only
transaction` — tested. It repairs nothing, quarantines nothing, alters no
constraint, and prints counts only.

**Self-tested** against: a fixture database; a database with **no** `mt_*`
tables (reports the schema absent and limits the claim to that database); a role
**without** `BYPASSRLS` (both warnings fire); a database containing **real
site-less vouchers** (the per-state breakdown resolves correctly).

---

# 2. BLOCKERS

| | Blocker | Owner |
|---|---|---|
| **B1** | **The census output.** Not obtainable here (§1.1) | **operator with host access** |
| **B2** | The deployment questions §5 of the script cannot answer: no other database holds these tables; nothing runs against one; the DSN used is the one a deployment would use | **operator** |
| **B3** | **Decision B** — batch `site_id` nullable or NOT NULL (§4) | **you** |
| **B4** | Backfill treatment for whatever the census finds — in particular **site-less vouchers that are not `unused`** | pending B1 |
| **B5** | Test debt: every site-less `issueBatch` call site must pass a real site. **Moves the assertion count off 718** | pending |
| **B6** | Guard tests — docs/73 §2.1's eight states, plus the cross-customer inserts from docs/75 | pending |
| **B7** | **Q2** — one MikroTik, several sites? | **NOT ESTABLISHED**; `dnb_site_nas` cardinality **not chosen** |

---

# 3. MIGRATION / BACKFILL PLAN

**Conditional on B1. Not authorized. The numbers below are unknown until the
census runs — the plan is the shape, not the execution.**

**Step 0 — census.** Record the output verbatim. If the role could not bypass
RLS, stop: the figures are lower bounds.

**Step 1 — prerequisite.** `mt_sites ADD UNIQUE (id, customer_id)`. Additive;
blocked only by a duplicate `(id, customer_id)`, which the primary key makes
impossible.

**Step 2 — `mt_devices`.** Add the composite FK `NOT VALID`; add
`CHECK (site_id IS NULL OR customer_id IS NOT NULL)` `NOT VALID`; backfill; then
`VALIDATE` both and **assert `convalidated`**. Backfill per docs/74 §7 — *never
guess ownership*: every violating row has `site_id` set to NULL and a quarantine
record written; `customer_id` is never adopted from a site reference.

**Step 3 — `mt_vouchers`.** Composite FK the same way. Then `site_id NOT NULL`,
**which is where the census matters most**:

- site-less **`unused`** vouchers — voidable, since nothing was sold. Treatment
  is a decision, but the options are real (void, or attach a site if one can be
  determined from the batch *without inference*).
- site-less **not-`unused`** vouchers — `active`, `expired`, `revoked`, or
  carrying `sold_at`. **These cannot simply be voided**: something was sold, or a
  guest is using it. The census counts them separately precisely so this is not
  discovered mid-migration.

**Step 4 — `mt_voucher_batches`.** Composite FK. Whether `site_id NOT NULL`
joins depends on **Decision B**.

**Step 5 — tests.** Guard tests for every state; fix the site-less call sites;
re-run. **The assertion count will change from 718, and that is expected.**

**Ordering note.** Step 3's `NOT NULL` and Step 2's `CHECK` are the only steps
that can fail on legitimate existing data. Both go last within their table, after
backfill, never as the opening move.

---

# 4. REMAINING OPEN DECISIONS

## 4.1 Decision A — voucher site invariant: **CLOSED** (docs/77 §1)

`mt_vouchers.site_id IS NOT NULL` **and**
`mt_vouchers.customer_id = mt_sites.customer_id`. Recorded, not migrated.

## 4.2 Decision B — batch site invariant: **OPEN**

**The fact, corrected and confirmed:** `mt_voucher_batches.site_id` is
**NULLABLE** today. `mt_voucher_batches.customer_id` is NOT NULL; `site_id` is
not.

**The mechanical link, measured:** `issueBatch()` takes **one `$siteId`** and
writes it to the batch row **and** to every voucher in that batch. The census
counts **NULL-site batches that already have vouchers**, because every voucher in
such a batch is necessarily site-less.

### Option B1 — leave `mt_voucher_batches.site_id` NULLABLE

| | |
|---|---|
| **What it means** | A batch may exist with no site. Its *vouchers* may not (Decision A), so such a batch can never legitimately produce one |
| **Consequence** | The two tables disagree. A batch row records an issuing act with no site, while every voucher it produced must have one. The contradiction is caught **at the voucher insert** — `NOT NULL` fires — so the batch is created, then voucher creation fails. **A half-made batch, with the error attributed to the wrong row** |
| **Migration cost** | Lowest — composite FK only, no `NOT NULL`, no backfill of batches |
| **Residual** | The application must reject a site-less batch itself, or every such batch becomes a failed issue. That is enforcement in application code — the thing this process has repeatedly declined to rely on |

### Option B2 — make `mt_voucher_batches.site_id` NOT NULL

| | |
|---|---|
| **What it means** | A batch cannot exist without a site. The failure moves to the **batch** insert, where the operator actually made the choice |
| **Consequence** | The tables agree, and the error names the right thing: *"a batch needs a site"* rather than a voucher failing downstream. `issueBatch()`'s single `$siteId` becomes a required argument |
| **Migration cost** | Higher — needs its own backfill for existing NULL-site batches, and **those batches may already have vouchers** (the census counts this). Voiding a batch is not free if its vouchers were sold |
| **Residual** | None structurally. It does extend a `NOT NULL` to a second table, which is why it needs approval rather than inference |

### What is *not* being done

**B2 is not recorded as decided.** Decision A was approved on evidence about
**vouchers**; extending its `NOT NULL` to a second table on the strength of a
propagation argument would be exactly the inference this process refuses. The
argument is strong — and it is still an argument, not an approval.

**One genuinely unknown input:** whether production holds NULL-site batches that
already carry vouchers. That number is in the census, and it is the difference
between B2 being a formality and B2 requiring a treatment for sold stock.

## 4.3 Q2 — untouched

**`Can one physical MikroTik HotSpot/NAS serve multiple DishNet sites?` —
`NOT ESTABLISHED`.** Not touched by this gate. C20 unaltered. **`dnb_site_nas`
cardinality is not chosen**, both branches remain open (docs/73 §5.1), and Q2 is
independent of everything above.

---

## 5. State

| | |
|---|---|
| Decision A — voucher site invariant | **CLOSED** |
| **Decision B — batch site invariant** | **OPEN** — §4.2 |
| Integrity scope | `mt_devices` + `mt_vouchers` + `mt_voucher_batches`; `mt_plans` **out** |
| Migration | **NOT AUTHORIZED** |
| **Production data state** | **NOT ESTABLISHED** — census attempted, unreachable, runbook handed over |
| Q2 / `dnb_site_nas` cardinality | **NOT ESTABLISHED / not chosen** |
| provisioning writer | not designed, not created |
| F6 | **NOT AUTHORIZED** |
| F1–F13 | **FROZEN** |
| Decisions 1, 2a, 2b, 3, 7 | unchanged |
