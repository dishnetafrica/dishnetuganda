# 75 — Which tables genuinely require a customer/site ownership invariant

**Status: READ-ONLY AUDIT. Nothing changed, nothing proposed for implementation.**

No migration written or edited, no constraint installed, no project table, role
or privilege created, no application code changed, no production contact. N10's
migration remains unauthorized; `dnb_site_nas` is not designed or created; the
provisioning writer does not exist; F6 is not authorized.

**The question.** docs/74 §6 found four tables carrying both `customer_id` and
`site_id` with nothing tying them. This gate asks, per table, the only question
that decides scope:

> **Does the business/domain model require `customer_id` to own `site_id` — and
> what happens if it does not?**

Shared column names do not prove shared semantics, and the answer below is
**not** the same for all four.

*(Representability was measured in a disposable database built from the
migrations, then dropped — `tools/audit/site_ownership_scope.sh`. Reading the
code establishes intent; only execution establishes what the tables permit.)*

---

## 1. The six questions, answered

### Q1 — Does voucher creation already derive customer from site?

**No, and it does not need to: it derives customer from the *session*.**

`POST /api/v1/me/vouchers` takes `customer_id` from `$who['customer_id']` — the
authenticated principal — and never from the request body. `site_id` comes from
the body. So customer is derived from authentication, and site is supplied.

### Q2 — Can voucher assignment accept customer and site independently?

**Not through the API.** `customer_id` cannot be supplied at all, and `site_id`
is validated first:

```php
if ($site !== null && $db->one('SELECT id FROM mt_sites WHERE id = ?', [$site]) === null) {
    return Response::notFound();
}
```

The sibling route on `mt_plans` states the mechanism outright: *"Filters the
derived set; a foreign site id matches nothing."*

**At the SQL level, yes — see Q5.** The two answers differ, and that difference
is this gate's main finding.

### Q3 — Does RLS protect the relationship?

**It protects the *lookup*, not the *write*. Measured.**

- The lookup **is** protected: as `dnb_app` with tenant context A,
  `SELECT id FROM mt_sites WHERE id = <B's site>` returned **NULL** — `mt_sites`
  has `FORCE ROW LEVEL SECURITY` with `USING (customer_id = mt_current_customer())`,
  so a foreign site is invisible and the route 404s. The comment is accurate.
- The write is **not** protected: the tenant policy on `mt_vouchers`,
  `mt_voucher_batches` and `mt_plans` is
  `USING/WITH CHECK (customer_id = mt_current_customer())`, which constrains
  **`customer_id` and says nothing about `site_id`** — the identical gap
  measured on `mt_devices` (docs/73 §1.1).

So the protection is **application code standing on RLS**, not a database
invariant. It holds for every path that performs the lookup, and only those.

### Q4 — Does any `SECURITY DEFINER` function bypass it?

**No — because none of these three tables is written by a definer function at
all.** `mt_vouchers`, `mt_voucher_batches` and `mt_plans` are written directly
by `dnb_app` through `VoucherService` and `PlanRepository`. The one definer
function that touches a voucher, `mt_voucher_redeem`, updates only
`state`, `activated_at` and `expires_at` — **it never reads or writes `site_id`.**

This is a real structural difference from `mt_devices`, where the write path *is*
a definer function (`mt_device_assign`) running as a role deliberately exempted
from the tenant predicate.

### Q5 — Can `mt_vouchers` currently represent a cross-customer pair?

**Yes. Measured, for all three tables**, as `dnb_app` in its own tenant context,
writing its own `customer_id` with another customer's `site_id`:

| Table | Insert with a foreign `site_id` | Rows violating afterwards |
|---|---|---|
| `mt_plans` | **ALLOWED (rows=1)** | 1 |
| `mt_voucher_batches` | **ALLOWED (rows=1)** | 1 |
| **`mt_vouchers`** | **ALLOWED (rows=1)** | 1 |

The API would have refused each of these with a 404. The database accepts all
three. **The state is representable; only application code prevents it.**

### Q6 — Do `mt_plans` and `mt_voucher_batches` have the same meaning?

**No. They differ from each other and from `mt_vouchers`.** §2 sets this out.

---

## 2. Per-table verdict: what `site_id` actually means

**What reads each column today — measured by search, not assumed:**

| Column | Written by | **Read by** |
|---|---|---|
| `mt_devices.site_id` | `mt_device_assign` | nothing yet; **the projector will** |
| `mt_vouchers.site_id` | `VoucherService::insertOne` | **nothing at all** |
| `mt_voucher_batches.site_id` | `VoucherService::issueBatch` | nothing — but see below |
| `mt_plans.site_id` | `PlanRepository::create` | **nothing**; exposed in the API's plan projection |

| | Table | Ownership required? | Consequence if violated | Class |
|---|---|---|---|---|
| **T1** | **`mt_devices`** | **YES** | Under the projector, a device at another customer's site publishes that site → this router. Becomes an **AAA authorization boundary** | **security-bearing (prospective)** — this is N10 |
| **T2** | **`mt_vouchers`** | **YES** | Decision 2b makes `site_id` the voucher's authorization scope. The publisher will read it into `dnb_cred_site`, so a foreign site means a credential authorized against **another customer's NAS set** | **security-bearing (prospective)** — the same class as N10 |
| **T3** | **`mt_voucher_batches`** | **YES, derived** | `issueBatch()` passes **one `$siteId`** to the batch row *and* to every voucher in it. A wrong batch site is not one bad row — it is **every voucher in that batch**, wrong the same way | **security-bearing by propagation** |
| **T4** | **`mt_plans`** | **YES semantically — but NOT security-bearing** | Nothing reads it. It never reaches a voucher: `issueBatch` takes the site from the **request**, never from `$plan['site_id']`. A foreign value is a data-quality defect, plus a minor id disclosure — the plan projection returns `site_id`, so a plan could surface another customer's site UUID | **commercial scoping** |

**The distinction that matters.** T1–T3 are one invariant seen at three points on
the same chain: *a router's site → a batch's site → a voucher's site* all feed the
same authorization boundary. T4 is a different column that happens to share a
name — a plan is *"WHAT THE CUSTOMER SELLS… Theirs. DishNet neither sets nor
approves any of it"* (migration 009's header), optionally scoped to a site for
presentation. It has no consumer and no path to AAA.

### 2.1 "Prospective" is precise, not a hedge

**Today `mt_vouchers.site_id` is inert — nothing reads it.** That is the same
condition this session found for `mt_hotspot_users`: written at issue, consumed
by nothing, because no publisher exists. It becomes security-bearing **the moment
the publisher is built**, which is exactly why it belongs to *this* integrity
gate rather than a later one. It is a **latent** defect, not a live exploit — the
same status N10 had before the projector was designed.

---

## 3. The Decision 2b gap this audit turned up

**`mt_vouchers.site_id` is `NULLABLE`, the API does not require it, and a
voucher with no site inserts cleanly — measured.**

Decision 2b closed as: *a voucher is bound to its issuing site and is valid only
against the NAS set authorized for that site.* A voucher with `site_id NULL` has
**no issuing site**, so 2b cannot describe it. This is reachable through the
ordinary API with no misuse whatsoever — simply omit `site_id` when issuing.

**It is not a security hole.** Under the chosen mechanism it fails *closed*:
docs/70 S5 measured that a credential with no site row authenticates nowhere. So
such a voucher would simply never work — silently.

**It is a completeness gap**, and it is a product question, not a schema one:
*may a voucher be issued with no site under a site-bound policy?* Two coherent
answers — forbid it (`site_id` becomes `NOT NULL`, and every existing NULL needs
a treatment), or permit it and define what it means. **Not answered here**; §5
lists it.

---

## 4. What a remediation would look like per table — not proposed, for scoping only

**The fix shape differs**, because of one measured fact:

| Table | `customer_id` | Consequence for the mechanism |
|---|---|---|
| `mt_devices` | **NULLABLE** | needs the composite FK **and** the CHECK — the partial-NULL state is reachable (docs/73 M4, M7) |
| `mt_plans`, `mt_voucher_batches`, `mt_vouchers` | **NOT NULL** | the partial-NULL state is **impossible**; a composite FK alone suffices, **no CHECK required** |

All four would still need `UNIQUE (id, customer_id)` on `mt_sites` — one shared
prerequisite, added once. And all four inherit docs/73 M10: **`ADD FOREIGN KEY`
validates nothing under `FORCE RLS`**, so each needs its own census and
validation proof.

---

## 5. Scope recommendation

**Recommended scope for the control-plane integrity gate: T1, T2 and T3 together
— `mt_devices`, `mt_vouchers`, `mt_voucher_batches`.** They are one invariant on
one chain, and fixing the router end while leaving the voucher end is exactly the
partial fix that reads as a complete one.

**`mt_plans` (T4): recommended OUT of this gate**, handled separately as data
quality. Including it would fix a real inconsistency, but bundling a cosmetic
column into a security gate makes the gate's purpose ambiguous and invites the
conclusion that "the four site columns" are one thing. They are not.

**Requires approval before any implementation:**

| | Item |
|---|---|
| **1** | The scope above — T1+T2+T3 in, T4 separate. Or a different split |
| **2** | That T2/T3 use the composite FK **without** a CHECK (their `customer_id` is `NOT NULL`) |
| **3** | The §3 product question: **may a voucher be issued with no site under 2b?** Blocks whether `mt_vouchers.site_id` becomes `NOT NULL` |
| **4** | A census of T2/T3 in production — the same operator-side step as docs/74 §9, and the same answer today: **production state NOT ESTABLISHED** |
| **5** | Whether the application-level lookup (Q2/Q3) is *additionally* hardened, or left as the convenience layer with the constraint as the real control |

---

## 6. Gate state — unchanged

| | |
|---|---|
| N10 remediation design | accepted candidate, **not implemented** |
| N10 backfill policy | accepted in principle; **production state NOT ESTABLISHED** |
| N10 migration | **NOT AUTHORIZED** — and now also pending the scope decision above |
| Q2 (one router, many sites?) | **NOT ESTABLISHED**; C20 unaltered |
| `dnb_site_nas` | not designed, not created, cardinality untouched |
| provisioning writer | not designed, not created |
| F6 | **NOT AUTHORIZED** |
| F1–F13 | **FROZEN** |
| Decisions 1, 2a, 2b, 3, 7 | unchanged |

**Production was not contacted.** `tools/audit/n10_backfill_census.php` remains
the operator-side collection step for `mt_devices`; T2/T3 would need the
equivalent. No production state is claimed.
