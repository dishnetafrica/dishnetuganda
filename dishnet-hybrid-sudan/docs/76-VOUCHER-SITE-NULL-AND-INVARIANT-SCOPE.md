# 76 — The site-less voucher, and the proposed invariant set

**Status: EVIDENCE AND A DOMAIN DECISION REQUEST. Nothing implemented.**

No migration written or edited, no constraint installed, no table, role or
privilege created, no application code changed, no production contact.
`dnb_site_nas` is not designed or created; the provisioning writer does not
exist; F6 is not authorized.

Two things: **Part A** — evidence on whether a site-bound voucher may exist
without a site. **Part B** — the proposed invariant set for the three
security-bearing tables, with NULL semantics, and the migration prerequisites.

---

# Part A — the site-less voucher

## A.1 Does any legitimate pre-site voucher state exist?

**No.** `mt_vouchers.state` is
`CHECK (state IN ('unused','active','expired','revoked'))` — four states, none
meaning *"issued but not yet sited."*

docs/66 §4's proposed `activating`, `activation_failed` and `revoking` are
**design-only — measured absent** from every migration and from `src/`. So no
holding state exists today, and none that has been *proposed* means "no site
yet" either.

## A.2 Are vouchers intentionally created before a site is selected?

**No documented intent anywhere.** The optionality is a code affordance and
nothing more:

- `Routes.php`: `$site = $req->body['site_id'] ?? null`, passed straight through
- **no validator requires it** — searched; `PlanValidator` and `VoucherService`
  contain no site requirement
- **nothing defaults it** — searched; no "if the customer has one site, use it"
- **docs/42 (the UX specification) never describes a site picker at issue**, nor
  an "all sites" option; `site` appears only as a *column* in a sessions table
  and in a search box
- no document in the repository describes a site-less voucher at all

Against that, the one piece of **business** evidence points the other way —
C20 Q3, operator-supplied:

> **Per site, locally generated.** Each site can independently generate, print
> and distribute its own vouchers.

If stock is generated *at* a site, the site is known at generation time.

## A.3 Can batch creation legitimately omit a site?

Same affordance, same absence of intent — and the two are **not independent**.
`issueBatch()` takes **one `$siteId`** and writes it to the batch row *and* to
every voucher in that batch. Batch-site-nullness and voucher-site-nullness are
one fact, not two.

## A.4 Do `mt_hotspot_users` / AAA publication depend on the site?

**The registry does not. Publication does.**

- **`mt_hotspot_users` carries no site at all** — `voucher_id`, `customer_id`,
  `radius_username`, `created_at`. Measured: the voucher suite produced **30
  registry rows for 30 site-less vouchers**. The registry write neither needs a
  site nor notices its absence.
- **Publication does depend on it.** Under Decision 2a/C-b the publisher must
  write `dnb_cred_site(radius_username, site_id)`, and the **only** source for
  that site is `mt_vouchers.site_id` — docs/68 §3 removed the client-supplied
  `nas` as authority, so it cannot come from the request. With a NULL site there
  is nothing to write.

## A.5 Do existing tests or workflows require site-less vouchers?

**Measured — and the answer is stronger than expected. Every voucher the suite
creates is site-less:**

| | |
|---|---|
| vouchers created by `test_vouchers.php` | **30** |
| of those, with a site | **0** |
| with `site_id NULL` | **30** |
| batches created | 3 — **all three** `site_id NULL` |
| `mt_hotspot_users` rows written | **30** |

`tests/test_vouchers.php:86` calls `issueBatch($A['customer'], $planA['id'], 2,
null, …)`. Adding `site_id NOT NULL` is **rejected by existing rows**:

```
ERROR:  check constraint "v_site_nn" of relation "mt_vouchers" is violated by some row
```

**But this is test debt, not a domain requirement.** No assertion anywhere claims
a site-less voucher is *meaningful*; the suite passes `null` because the
parameter is optional and those tests are about code generation, collision
retry and the redemption race. Nothing tests site-less behaviour on purpose.

## A.6 What happens to a site-less voucher under Model B?

Model B publishes the AAA credential at redemption/activation. With a NULL site:

1. **At issue** — `mt_vouchers` and `mt_hotspot_users` rows are written
   normally. Nothing objects. The voucher is printable and sellable.
2. **At activation** — the publisher needs a site and has none. Either
   publication fails (`activation_failed`, per docs/66 §4), or the credential is
   published with no `dnb_cred_site` row — and docs/70 **S5 measured** that a
   credential with no site row **authenticates nowhere**.
3. **What the guest sees** — under docs/67 §4 the portal deliberately cannot
   distinguish reasons: unknown, spent, revoked, expired and **wrong site** all
   return `200 {"state":"invalid"}`.

**So a site-less voucher is a sellable object that can never work, and whose
failure is indistinguishable from an invalid code — by design.** That is the
real harm. It is not a breach; the mechanism fails closed exactly as intended.
It is a **silent dead voucher**, and the deliberate opacity that protects the
system from probing also hides this from the person holding it.

## A.7 The domain decision required — options, not a choice

> **May a voucher be issued with no site, under a site-bound policy?**

| | Option | For | Against |
|---|---|---|---|
| **A** | **Reject at creation.** `site_id NOT NULL` on `mt_vouchers` and `mt_voucher_batches`; the API returns 422 when omitted | The invariant is then true everywhere, always. No dead voucher can be created. Simplest to reason about, and it matches C20 Q3's *"per site, locally generated"* | Breaks the current suite (A.5) — test debt to pay. Removes the single-site convenience unless a default is added. Needs a treatment for any existing NULL rows |
| **B** | **Default when unambiguous** — if the customer has exactly one site, use it; reject when ambiguous | Keeps the convenience that is probably why the affordance exists. Still no dead vouchers | **It infers.** The system would choose a site the operator did not name — the thing this process has refused since docs/74 §7 (*never guess ownership*). Weaker here, since the inference stays inside one customer, but it is still an inference. Multi-site customers still need the reject path, so A's work is done anyway |
| **C** | **An explicit holding state** — e.g. `unsited`, with publication refused until a site is attached | Models a genuine pre-site workflow if one exists, and makes the dead voucher **visible** instead of silent | **No evidence any such workflow exists** — C20 Q3 says the opposite. Adds a lifecycle state, and more machinery, for a case nobody has asked for |

**Recommendation — for your decision, not mine to take: Option A.** The evidence
for a legitimate site-less voucher is *absent* rather than *mixed*: no state, no
validator, no default, no UX, no document, and business evidence pointing the
other way. B's convenience is real but buys it with an inference, and the
ambiguous case still needs A. C solves a problem no evidence shows exists.

**If A is chosen, two sub-decisions follow** and are not assumed here: whether
the API gains a single-site default as a *separate, explicit* convenience, and
what happens to existing NULL rows (§B.4).

---

# Part B — the proposed invariant set

## B.1 Scope, as accepted

| | Table | In scope | Why |
|---|---|---|---|
| **T1** | `mt_devices` | **YES** | the provisioning projector depends on it |
| **T2** | `mt_vouchers` | **YES** | Decision 2b makes site the authorization scope |
| **T3** | `mt_voucher_batches` | **YES** | one `$siteId` propagates to every voucher in the batch |
| **T4** | `mt_plans` | **NO — separate** | not in the AAA chain (docs/75 §2). **Not to be added to the security migration because the column names match** |

## B.2 The invariants, with NULL semantics

**Shared prerequisite, added once:** `mt_sites ADD UNIQUE (id, customer_id)`.
Every composite FK below references it.

| | Table | `customer_id` | `site_id` | Proposed constraint | Why this shape |
|---|---|---|---|---|---|
| **T1** | `mt_devices` | **NULLABLE** | NULLABLE | composite FK `MATCH SIMPLE` **+** `CHECK (site_id IS NULL OR customer_id IS NOT NULL)` | the partial-NULL state is reachable, and `MATCH SIMPLE` permits it — measured, docs/73 M4/M7. `MATCH FULL` would close it but breaks site deletion (M6) |
| **T2** | `mt_vouchers` | **NOT NULL** | NULLABLE *(pending A.7)* | composite FK `MATCH SIMPLE` **alone — no CHECK** | with `customer_id NOT NULL` the partial-NULL state is **unrepresentable**, so the CHECK would be dead weight |
| **T3** | `mt_voucher_batches` | **NOT NULL** | NULLABLE *(pending A.7)* | composite FK `MATCH SIMPLE` **alone — no CHECK** | same reasoning |

**The two concerns stay cleanly separable.** Under `MATCH SIMPLE`, a NULL
`site_id` satisfies the FK regardless — so the FK enforces *"if there is a site,
it is this customer's"* and says nothing about whether a site must exist. Whether
one must is Part A's decision, expressed separately as `NOT NULL`. **Neither
decision forces the other**, and the FK is correct either way.

## B.3 What these invariants do *not* cover

- **`mt_plans`** — out of scope by decision (docs/75 §5).
- **The `dnb_app` blanket `UPDATE` grant** on `mt_devices` (docs/73 §1.1) — the
  constraint makes the violation unrepresentable, but the over-broad grant
  remains unjustified by any requirement. A separate finding.
- **The missing audit row** on `mt_device_assign` (docs/72 §A.4).
- **`mt_vouchers.site_id` is read by nothing today.** The invariant is protecting
  a column that becomes load-bearing only when the publisher is built.

## B.4 Exact migration prerequisites

None of these is satisfied yet.

| | Prerequisite | State |
|---|---|---|
| **1** | **The Part A decision** (A.7). It determines whether `NOT NULL` joins the migration and whether existing NULL rows need a treatment | **BLOCKING — yours to take** |
| **2** | **A production census** for all three tables. `tools/audit/n10_backfill_census.php` covers `mt_devices`; the equivalent for T2/T3 is a small extension. **Production state: NOT ESTABLISHED** and not inferable from any disposable database | **BLOCKING — needs an operator with access** |
| **3** | **Validation proof.** docs/73 **M10**: `ADD FOREIGN KEY` as the table owner scans **zero rows** under `FORCE ROW LEVEL SECURITY`, is marked `convalidated = true`, and leaves existing violations in place. Each constraint needs `NOT VALID` → backfill → `VALIDATE`, with the result asserted | required |
| **4** | **Backfill treatment**, per docs/74 §7's rule *never guess ownership*: detach (`site_id := NULL`) and quarantine, never adopt a customer from a site reference | approved in principle; per-table application pending |
| **5** | **Test debt from A.5.** If Part A chooses `NOT NULL`, `test_vouchers.php` and any other site-less call site must pass a real site. This **will change the assertion count** from 718 | pending the Part A decision |
| **6** | **Guard tests** — docs/73 §2.1's eight states for `mt_devices`, plus the cross-customer insert for T2/T3 measured in docs/75 §Q5 | not written |

## B.5 Gate state — unchanged

| | |
|---|---|
| Invariant scope | **T1+T2+T3 proposed; `mt_plans` out** |
| Part A domain decision | **required, not taken** |
| N10 / integrity migration | **NOT AUTHORIZED** |
| Production census | **NOT ESTABLISHED** — not inferred from disposable databases |
| **Q2** — one MikroTik, several sites? | **NOT ESTABLISHED**; C20 unaltered |
| `dnb_site_nas` | not designed, not created, **cardinality not chosen** |
| provisioning writer | not designed, not created |
| F6 | **NOT AUTHORIZED** |
| F1–F13 | **FROZEN** |
| Decisions 1, 2a, 2b, 3, 7 | unchanged |
