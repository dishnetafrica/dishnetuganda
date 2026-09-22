# 89 — Decision record: attempt store, site authority, and the Decision 5 gate

**Status:** DECISION RECORD. **Documentation only.** No schema, migration, code,
role, privilege, configuration or deployment changed. Suite unchanged at
**1,228 assertions green**. Production untouched.

Two requirements are **CLOSED** here. One decision **remains OPEN** and is given
an explicit gate so that it cannot be closed by anyone picking plausible numbers.

---

## 1. Attempt store / failed-redemption record — **CLOSED (requirement)**

**`docs/65` §8 remains authoritative.** Unauthenticated redemption attempts are
recorded in a **separate non-tenant attempt store**, never in `mt_audit_log`.

### 1.1 The recorded requirements

| # | Requirement |
|---|---|
| **AS-1** | **The raw voucher code is never stored.** A code is a live bearer credential; a guest who mistypes one character would otherwise write *someone else's valid code* into a table support staff can read and search (`docs/65` §8). |
| **AS-2** | Store **`code_prefix`** (the first block — enough to see a guessing run) and **`code_hash`** (enough to correlate repeat attempts on one code). Nothing else derived from the code. |
| **AS-3** | **Classify the outcome.** `docs/65` §8's set: `unknown_code`, `unknown_nas`, `already_active`, `foreign_tenant`, `wrong_site`, `unbound_voucher`, `already_bound_elsewhere`, `race_lost`. **The guest is told `invalid` for every one of them; the record keeps the distinction.** |
| **AS-4** | **The rate-limit counters live in this store** (`docs/65` §9.5). PostgreSQL is authoritative for the per-code and per-source counters, transactional with the attempt — *"a counter that can disagree with the audit trail is worse than none"* — and the check belongs **inside** the redemption function, where a second caller cannot bypass it. |
| **AS-5** | **This is operational/security telemetry, not `mt_audit_log`.** Three independent grounds, all from `docs/65` §8: **volume** — a guessing run would fill a tenant-facing business record; **tenancy** — `mt_audit_log` is RLS + FORCE and organised by customer, so an unknown-code attempt *"would be written and readable by nobody"*; **retention** — business audit and attempt telemetry want opposite lifetimes (Decision 6). |
| **AS-6** | **Tenant/customer data must not be required to perform the anti-enumeration check.** This is load-bearing rather than a convenience: for an **unknown code there is no customer to resolve**, so a check that needed one could not run in exactly the case enumeration produces. The store is non-tenant, carries no RLS tenant predicate, and the counter lookup must complete without `mt_current_customer()` being set. |

### 1.2 `guest` is **not** added to `actor_kind`

`mt_audit_log.actor_kind` stays `CHECK (actor_kind IN ('principal','staff','system'))`.

`docs/64` §10 offered two ways to satisfy `docs/65` §8: *"extend `mt_audit_log`
with a `guest` actor kind and a write policy, **or give redemption its own log
table**."* **The attempt store is that log table.** `docs/65` §8 then chose it
for failed attempts on the three grounds in AS-5.

`docs/65` §8's proposal to add `guest`, `worker` and `radius` to the enum is
therefore **superseded**: the requirement it served is met by the second of the
two options `docs/64` §10 offered. A future reader finding §8's **SETTLED**
heading should read this section alongside it.

The successful activation — the only row that reaches `mt_audit_log` — is
performed with **no identity presented**, so `system` describes it accurately
and `guest` would hold a constant.

### 1.3 No actor kind is not no attribution

**Recorded because the two are easy to conflate.** Declining to add a `guest`
actor kind does **not** mean unauthenticated attempts go unattributed.

The attempt store records whatever the abuse controls need — source address,
`nas_claimed`, `code_prefix`, `code_hash`, outcome class, timestamps — **without
asserting that an unauthenticated guest is a DishNet `principal` or `staff`
actor.** Attribution for abuse control and identity in the tenant audit model
are different things, and the attempt store is where the first belongs.

---

## 2. Site resolution — **CLOSED / superseded**

### 2.1 What is superseded

`docs/65` §8 records that a successful redemption stores *"the NAS; the site
**resolved from** that NAS."* `docs/65` §9.3 built its primary rate-limit
dimension on the same reading.

**Both are superseded**, by `docs/68` §2.2, which is a measured correction and
not a preference:

> *"In `docs/65` §9.3 I wrote that the NAS is 'the most trustworthy dimension
> available — asserted by infrastructure DishNet provisioned, not by the guest.'
> **That is true of a RADIUS packet and false of a portal POST.** … A router
> asserts its identity to FreeRADIUS over a shared-secret channel; a browser
> asserts whatever it is told to."*

### 2.2 The authoritative rule

```
voucher.site_id  ->  that site's authorized dnb_site_nas mapping  ->  authorization decision
```

`nas_claimed` from the portal is **untrusted request context only** (D-1a). It
may cause an early rejection. It may never establish, select or widen the set of
NAS devices a voucher is valid at.

**Never:**

```
nas_claimed  ->  therefore the voucher belongs to this NAS
```

### 2.3 The security invariant

> **SI-1. Changing `nas_claimed` alone must never change the site to which a
> voucher is authorized.**

Enforced by construction: `dnb_cred_site` is written from `voucher.site_id`
(`docs/87` §B.2), and the guest supplies no input to that column. Proven by
execution at implementation time — `docs/87` **T-13**, which already asserts
exactly this, and **T-18**, the mandatory C1 guard that no client-asserted value
reaches the authorize query (`docs/70` §5.3).

### 2.4 D-1a unchanged

D-1a is recorded exactly as decided: the guest-supplied NAS is
`detail.nas_claimed`; `source` is not overloaded; authorization derives from
`voucher.site_id` and that site's authorized NAS mapping; the portal may never
choose or establish a voucher's site.

---

## 3. Decision 5 — **REMAINS OPEN**

**No resolution is chosen. R-a, R-b, R-c and R-d remain candidates.**
**No numeric limit is invented.**

### 3.1 The eight parameters

Each row carries: documented constraint → proposed range/value **if supportable**
→ security rationale → operational rationale → **evidence still required**.
Where the evidence does not support a value the cell reads **UNMEASURED / OPEN**,
not a number.

| # | Parameter | Documented constraint | Proposed range / value | Security rationale | Operational rationale | Evidence still required |
|---|---|---|---|---|---|---|
| **1** | **Bounded synchronous wait** | `docs/66` §2.4 *"short and bounded… belongs with decision 5's numbers"*; §3 rejects no bounded wait — *"a guest at a reception desk is not a provisioning queue"* | **UNMEASURED / OPEN** | An open unauthenticated request held server-side is a resource-exhaustion surface | Too short and every guest falls through to a ticket and a poll — the outcome the bounded wait exists to avoid. Must sit below the web server and proxy timeouts | **Publication latency p50/p99**, measured end-to-end against the **disposable** FreeRADIUS/`radius` environment, once the publisher exists and is exercised. **Not measurable now: the publisher does not exist and this application has never written to a `radius` database.** |
| **2** | **Publication deadline** | `docs/66` §2.7 retry *"until the publication deadline"*; §2.4 *"minutes, not days"*; `mt_intents`' `deadline_at = now() + 7 days` **explicitly not inherited** | **Minutes, not days** — a bound the documents support. **The specific value is UNMEASURED / OPEN** | Bounds the window in which a voucher is consumed but unusable, i.e. in which the commercial and AAA planes disagree | Past it the voucher becomes `activation_failed`, operator-visible and resolvable. Short enough that a receptionist can act while the guest is present | Parameter 1's measurement; the deadline must sit well above p99 publication latency |
| **3** | **Activation-ticket lifetime** | `docs/67` §3.4: *"short — it only has to outlive the publication deadline. Minutes, not hours."* Stored **hashed**; binds one publication row; authorises exactly one status read | **Publication deadline + a margin.** Relative bound only; **the absolute value is UNMEASURED / OPEN** because it derives from parameter 2 | A bearer token for one status read; longer life is exposure for no gain | Must outlive the deadline, or a guest holds a ticket that expires before the answer exists | Parameter 2, from which this derives. House precedent for order of magnitude: `Authenticator::CODE_TTL = PT10M` |
| **4** | **Maximum redemption attempts (per code)** | `docs/65` §9.2: `mt_auth_verify_code` caps at 4 with the counter incremented **before** the check — **but the same section states the property does not transfer**: *"A per-code counter does not slow an enumeration run at all."* `docs/65` §9.4: *"Refusing the third makes the product unusable at a reception desk"* | **Lower bound supported: more than 3.** **Upper bound UNMEASURED / OPEN** | Bounds **replay and sharing of an observed code** — the realistic attack per `docs/65` §9.1. It does **not** bound enumeration and must never be described as if it does | A guest mistyping a code off paper needs three or four tries | §3.2's gate. Until the primary dimension is settled, a per-code cap is one leg of a mechanism whose other legs are undetermined |
| **5** | **Rate limit per source** | `docs/65` §9.3: source IP **"not primary"** — behind the customer's NAT every guest at a site shares one address, *"so a low limit locks out a whole venue. Coarse volumetric use only"* | **UNMEASURED / OPEN** | As a gate it is a denial-of-service primitive against a paying customer's whole venue; as a breaker it bounds blast radius | `docs/65` §9.5: volumetric shedding *"belongs in front of the application"* — expendable, not per-packet disk writes | **Typical concurrent guest count per venue**, which nobody has measured, and §3.2's gate. A ceiling cannot be set without knowing what a busy lobby looks like |
| **6** | **Rate limit per voucher / code** | `docs/67` §3.8: re-retrieval while `active` and unexpired is permitted and *"is rate-limited per code (decision 5)"* | **UNMEASURED / OPEN** | Re-retrieval is deliberate — a voucher is a bearer instrument — but must not become a free credential-distribution endpoint | A guest who closes the tab must be able to recover the credentials, possibly more than once on a poor network | Observed re-retrieval behaviour, which requires the portal to exist |
| **7** | **Retry / backoff** | `docs/66` §2.7: transient → *"retry with backoff until the publication deadline"*; permanent → **no retry**, marked failed. `mt_intents`' `max_attempts = 5` **not inherited** | **Shape supported, not values:** exponential, capped, **bounded by the deadline rather than by an attempt count**. Intervals **UNMEASURED / OPEN** | A fixed low attempt count abandons a publication a brief `radius` outage would have allowed; a fixed high one hammers a database that is down | The deadline is already the bound (parameter 2); a second independent bound is a way for the two to disagree. Attempts must be **recorded** for `docs/66` §2.11's drift surfaces | Parameter 1's measurement, plus observed `radius`-side failure modes |
| **8** | **Publication-timeout behaviour** | **Already decided — not a number.** `docs/66` §2.7: the guest-wait timeout is *"not a failure. The redemption stands, the publication keeps retrying, the voucher stays `activating`, and the guest holds a ticket."* Past the **publication deadline** → `activation_failed` (`docs/66` §4), operator-visible, resolvable by retry, release to `unused`, or revoke-and-reissue, *"never resolved silently."* `docs/67` §4.1: `{"state":"failed","reference":…}` at HTTP **200**, never 500 | **No value required.** Only parameters 1 and 2 introduce numbers; this is their consequence | Collapsing `activation_failed` into a 500 would destroy the distinction `docs/66` §4 created the state for | Operator surface is the count of failed publications (`docs/66` §2.15) | **None.** This row is closed |

### 3.2 THE DECISION 5 GATE

> **Decision 5 cannot close until the remaining trustworthy/effective
> portal-rate-limit dimension is established.** The current documents establish
> that **per-NAS and per-code alone are insufficient**, while **source-IP alone
> is operationally problematic**. Therefore the next required evidence is
> **whether the MikroTik redirect/portal path can provide a verifiable
> infrastructure-bound signal**, or whether **another anti-enumeration mechanism
> must be designed.**

The gate rests on three statements already in the record, none of them new:

| Dimension | Documented verdict |
|---|---|
| per-NAS | **evadable** — the portal's NAS is guest-asserted (`docs/68` §2.2) |
| per-code | *"does not slow an enumeration run at all"* (`docs/65` §9.2) |
| per-source-IP | *"not primary"* — a NAT'd venue shares one address (`docs/65` §9.3) |

**This is a hardware/RouterOS evidence question and cannot be answered from
documentation.** It belongs with the unverified hardware register (H1–H7,
`docs/80` §7) and, if it needs measurement, with the **disposable** environment
— never production.

**Not chosen, and not to be chosen without that evidence:** R-a (source IP with
a venue-sized ceiling), R-b (make the NAS verifiable), R-c (per-code plus a
global breaker, gap accepted in writing), R-d (defer the portal endpoint).
R-b is the only candidate that would restore the property the earlier design
assumed, which is why it is the one worth investigating rather than settling for
one of the others.

### 3.3 Two properties that are not parameters and are not tradeable

- **Response uniformity.** `docs/65` §9.4 calls it *"non-negotiable"*: a
  rate-limited attempt returns the same `invalid` as a wrong code. `docs/67`
  §4.1 encodes it — rate-limited and unknown are byte-identical, HTTP 200 both.
- **Decaying windows, never hard locks.** `docs/65` §9.4: *"A hard per-NAS lock
  is a denial-of-service primitive against a paying customer's whole venue."*

---

## 4. Resulting decision state

| Decision | Status |
|---|---|
| **D-1a** — `nas_claimed` is untrusted context | **CLOSED** |
| **P-1** — dedicated `dnb_portal` boundary | **CLOSED** |
| **Voucher site authority** — `voucher.site_id` | **CLOSED** (§2) |
| **Attempt store** | **CLOSED requirement** (§1) |
| **`guest` actor kind** | **Not required** (§1.2) |
| **Decision 4** — second redemption entry point | **No second path required** |
| **Decision 5** — numeric / rate-limit parameters | **OPEN** (§3, gated by §3.2) |
| **Decision 5 implementation** | **BLOCKED** |
| **F6-B** | **NOT AUTHORIZED** |
| **C-b production deployment** | **NOT AUTHORIZED** |

Unchanged elsewhere: Decisions 1, 2a, 2b, 3, 7 closed; F1–F13 frozen; Decision 6
(retention) open; Q2 open; `dnb_site_nas` cardinality open;
`mt_voucher_batches.site_id NOT NULL` an open schema decision; W-4, W-5, W-6
open; the production census still required before any production migration.

---

## 5. What this record does not do

Implements nothing. Does not build the portal redemption path, the attempt
store, the publisher, the rate limiter, or any database migration. Chooses no
Decision 5 value and no Decision 5 resolution. Does not apply migration 020 to
production. Does not deploy C-b. Does not create `dnb_site_nas` or
`dnb_cred_site`. Does not verify MikroTik hardware. Does not decide Q2 or
`dnb_site_nas` cardinality. Does not start F6-B. Contacts no production system.
Suite unchanged at **1,228 assertions green**.
