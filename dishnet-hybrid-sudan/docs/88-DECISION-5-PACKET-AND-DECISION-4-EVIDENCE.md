# 88 — Decision 5 packet, and Decision 4 evidence

**Status:** EVIDENCE / DECISION PACKET. **Nothing implemented.** No schema,
migration, code, role, privilege, configuration or deployment changed. Suite
unchanged at **1,228 assertions green**. Production untouched.

**D-1a and P-1 are recorded as CLOSED** (§C.1). This document decides nothing
else: it extracts what the authoritative documents already constrain, proposes
values where a proposal is honest, and names the two places where a proposal
would not be.

---

## PART A — DECISION 5 PACKET

### A.0 Read this first: one premise of Decision 5 has been refuted

Decision 5 cannot be closed by choosing numbers, because the documents disagree
with each other about **which dimension the rate limit is keyed on**, and the
disagreement is not a matter of taste — it was a measured correction.

| Source | Statement |
|---|---|
| `docs/65` §9.3 | **NAS / site is the primary dimension** — *"the most trustworthy signal available: asserted by infrastructure DishNet provisioned, not by the guest."* |
| `docs/68` §2.2 | **That is true of a RADIUS packet and false of a portal POST.** *"A router asserts its identity to FreeRADIUS over a shared-secret channel; a browser asserts whatever it is told to."* Consequence stated there: **"per-NAS rate limiting is evadable by varying the claimed NAS. `docs/65` §9.3 made per-NAS the primary dimension on the strength of the same wrong claim. That is an input to decision 5, recorded here, not re-decided."** |
| `docs/65` §9.2 | **Per-code does not close the gap:** *"A per-code counter does not slow an enumeration run at all. That gap is why per-source dimensions are mandatory."* |
| `docs/65` §9.3 | **Source IP is explicitly not primary** — behind the customer's NAT every guest at a site shares one address, *"so a low limit locks out a whole venue."* |

So: the documented **primary** dimension is refuted, the documented **exact**
dimension is documented as useless against the main attack, and the only
remaining non-guest-asserted dimension is documented as unsuitable for the role.
**There is currently no dimension that is both trustworthy and effective.**

This is not a gap I can close by picking a number. It needs a prior decision.

#### A.0.1 The four candidate resolutions — presented, not chosen

| # | Resolution | What it costs | What it needs |
|---|---|---|---|
| **R-a** | Accept **source IP** as primary with a venue-sized ceiling | Contradicts `docs/65` §9.3 explicitly; a NAT'd venue shares one address, so the ceiling must be high enough not to lock out a busy lobby — which is also high enough to permit a slow enumeration run | A stated venue-size assumption. No new hardware facts |
| **R-b** | **Make the NAS non-guest-asserted** — the router carries a verifiable NAS identity into the portal redirect (a per-NAS secret, or a walled-garden path only that router can produce), so `docs/65` §9.3's premise becomes true again | Restores the architecture as designed, and is the only option that does | **Depends on physical MikroTik verification (H1–H7, all unverified, `docs/80` §7).** Cannot be designed from documents alone |
| **R-c** | **Per-code + a global breaker only**, accepting enumeration is bounded only volumetrically | Accepts the gap `docs/65` §9.2 names. Honest, but it is a decision to tolerate a known weakness | An explicit acceptance, recorded |
| **R-d** | **Defer the portal endpoint** until R-b is verified | Blocks F-7 implementation entirely | Nothing; it is the null option |

**My reading, offered as a recommendation and not a decision:** R-b is the only
one that restores the design, and it is the one this session cannot specify,
because it turns on what a MikroTik HotSpot redirect can actually be made to
carry — a hardware fact in the unverified register. If R-b proves impossible,
R-a + R-c together are the realistic floor, and that combination should be
recorded as an accepted weakness rather than presented as a rate limit that
works.

**Every number in §A.1 is conditional on this question.** They bound cost and
abuse; none of them makes an evadable dimension unevadable.

### A.1 The eight parameters

> **SUPERSEDED by `docs/89` §3.1.** That table is authoritative: it carries a
> fifth column, **evidence still required**, and replaces every proposed number
> below with **UNMEASURED / OPEN** where the evidence does not support one. The
> version below is kept for its reasoning, not for its values. **Do not take a
> number from this section.**

Precedents cited from code are measured, not remembered:
`Authenticator::CODE_TTL = PT10M`; `TOKEN_TTL = P30D`;
`mt_auth_issue_code` → 5 codes per phone per **15 minutes**, `DN429`;
`mt_auth_verify_code` → `IF r.attempts >= 4 THEN RETURN`, counter incremented
**before** the check; `mt_intents` → `max_attempts = 5`,
`deadline_at = now() + 7 days` — **explicitly refused** for publication.

| # | Parameter | Documented constraint | Proposed value / range | Security reason | Operational reason |
|---|---|---|---|---|---|
| **1** | **Maximum synchronous activation wait** | `docs/66` §2.4: *"short and bounded… the exact value is operational and belongs with decision 5's numbers."* `docs/66` §3 rejects *"fully asynchronous publication with no bounded wait"* — *"a guest at a reception desk is not a provisioning queue."* | **2–5 s**, and **NOT MEASURABLE FROM DOCUMENTS** — see note below | The wait holds an open unauthenticated request; too long and the endpoint is a cheap resource-exhaustion target | Too short and every guest falls through to a ticket and a poll, which is the UX the bounded wait exists to avoid. Must sit below the web server and any proxy timeout |
| **2** | **Publication deadline** | `docs/66` §2.7: transient failures *"retry with backoff until the publication deadline."* §2.4: *"minutes, not days, because under B the voucher's validity window is tied to publication and a guest is waiting."* `mt_intents`' 7 days explicitly not inherited | **2–10 minutes** | Bounds how long a voucher sits `activating` — a state in which it is consumed but unusable. A long deadline widens the window in which the commercial and AAA planes disagree | Past it the voucher becomes `activation_failed`, which is operator-visible and resolvable (`docs/66` §4). Short enough that a receptionist can act while the guest is still there |
| **3** | **Activation ticket lifetime** | `docs/67` §3.4: *"short — it only has to outlive the publication deadline. Minutes, not hours."* Stored **hashed**; binds to one publication row; authorises exactly one thing | **publication deadline + 5 min** (so 7–15 min). House precedent: `CODE_TTL = PT10M` | A bearer token for one status read. Longer life is more exposure for no gain | Must outlive the deadline or a guest can be left holding a ticket that expires before the answer exists |
| **4** | **Maximum redemption attempts (per code)** | `docs/65` §9.2: `mt_auth_verify_code` caps at 4, counter first. **But the same section says this property does not transfer**: *"An OTP is bound to the phone that requested it… A voucher code is bound to nobody — the attacker picks the code. A per-code counter does not slow an enumeration run at all."* | **4–5 failed attempts per code per hour**, decaying | Bounds **replay and sharing of an observed code** — which `docs/65` §9.1 names as the realistic attack. It does **not** bound enumeration, and must not be described as if it does | A guest mistyping a code off paper needs three or four tries. `docs/65` §9.4: *"Refusing the third makes the product unusable at a reception desk"* |
| **5** | **Rate limit per source / IP** | `docs/65` §9.3: **"not primary"** — *"behind the customer's NAT every guest at a site shares one address, so a low limit locks out a whole venue. Coarse volumetric use only."* | A **high volumetric ceiling only** — order 10²–10³ attempts/hour per address, not a per-guest gate. **Conditional on A.0** | As a gate it is a denial-of-service primitive against a paying customer's whole venue. As a breaker it bounds blast radius | `docs/65` §9.5: volumetric shedding *"belongs in front of the application"* — Redis or an edge layer, expendable, not per-packet disk writes |
| **6** | **Rate limit per voucher** | `docs/67` §3.8: re-retrieval of credentials while `active` and unexpired is permitted and *"is rate-limited per code (decision 5)"* | **Same counter as #4**, separate budget for the `active` re-retrieval case: **~10 per hour** | Re-retrieval is deliberate — a voucher is a bearer instrument, and `docs/67` §3.8 argues it does not widen the model. The limit stops it becoming a free credential-distribution endpoint | A guest who closes the tab must be able to get the credentials back, more than once if the network is poor |
| **7** | **Retry / backoff** | `docs/66` §2.7: transient → *"retry with backoff until the publication deadline"*; permanent → **no retry**, marked failed. `mt_intents`' `max_attempts = 5` not inherited | **Exponential from ~250 ms, capped at ~15 s, bounded by the deadline rather than by an attempt count** | A fixed low attempt count would abandon a publication that a brief `radius` outage would have allowed; a fixed high one would hammer a database that is down | The deadline is already the bound (#2), so a second bound adds a way for the two to disagree. Attempts should be **recorded**, for the drift and failure surfaces of `docs/66` §2.11 |
| **8** | **Behaviour after publication timeout** | **Already decided — this is not a number.** `docs/66` §2.7: the guest-wait timeout is *"not a failure. The redemption stands, the publication keeps retrying, the voucher stays `activating`, and the guest holds a ticket."* Past the **publication deadline** → `activation_failed` (`docs/66` §4), operator-visible, resolvable by retry, release to `unused`, or revoke-and-reissue, *"never resolved silently."* `docs/67` §4.1: `{"state":"failed","reference":…}` at HTTP **200**, never 500 | **No new value required.** Only #1 and #2 need numbers; this row is their consequence | Collapsing `activation_failed` into a 500 would destroy the distinction `docs/66` §4 created the state for | The operator surface is the count of failed publications (`docs/66` §2.15) |

#### A.1.1 Why row 1 says "not measurable from documents"

The bounded wait should be *just longer than a normal publication*. Nobody has
measured a publication, because the publisher does not exist and the `radius`
database has never been written to by this application. **2–5 s is an
engineering guess, and I am labelling it as one.**

The measurement that would replace it: time `publish()` end-to-end against a
disposable `radius` instance, p50 and p99, then set the wait above p99 and the
deadline well above it. That measurement belongs to the F6-B gate. Until then,
**treat #1 as provisional and make it configurable**, not compiled in.

#### A.1.2 Two properties that are not parameters and must not be traded

- **Response uniformity.** `docs/65` §9.4 calls it *"non-negotiable"*: a
  rate-limited attempt returns the same `invalid` as a wrong code. `docs/67`
  §4.1 encodes it — rate-limited and unknown are byte-identical, both HTTP 200.
- **Decaying windows, never hard locks.** `docs/65` §9.4: *"A hard per-NAS lock
  is a denial-of-service primitive against a paying customer's whole venue."*

#### A.1.3 Where the counters live — already settled, and it has a dependency

`docs/65` §9.5 settles it: **PostgreSQL is authoritative** for the per-code and
per-source counters, *"transactional with the redemption attempt and its audit
record — a counter that can disagree with the audit trail is worse than none"*,
inside the redemption function *"where a second caller cannot bypass them."*
Redis/edge is for volumetric shedding only.

**The dependency:** §9.5 says the counters live in *"§8's attempt store"*, which
*"must exist anyway."* That store does not exist. See §C.2 — it is a
prerequisite for Decision 5, not a consequence of it.

---

## PART B — DECISION 4 EVIDENCE

**Recommendation: keep it CLOSED-as-NO, or keep it OPEN — but do not build it.**
The evidence below answers the six questions asked. Nothing here argues for a
second entry point; it argues that if one is ever wanted, its shape is already
determined.

| Question | Evidence |
|---|---|
| **1. What is the second entry point?** | A **front-desk "activate this for a guest" button**: an authenticated customer principal (a receptionist) activating a voucher on behalf of a guest who is standing at the desk. Named in `docs/64` §Q5 and `docs/65` §7. |
| **2. Why is it required?** | **It is not established that it is.** `docs/64` Q5's recommendation is explicit: **"no."** *"A customer issues vouchers and hands them over; that is the operation the four planes already give them. Redemption is what the recipient does."* No document states a business requirement; it appears only as a possibility. |
| **3. Who invokes it?** | An authenticated customer principal — a `mt_principals` row — not DishNet staff and not a guest. |
| **4. What actor identity?** | **`principal`**, with the principal id. This is the one case where `principal` is correct, and it is exactly why the portal path is `system` (§C.1): the two paths differ in whether an identity is present. |
| **5. Does it create a second security path?** | **Yes — and the danger is measured, not theoretical.** `docs/65` §7: *"the first draft of that function took the customer as an argument and crossed the tenant boundary on its first call; the corrected form derives the tenant from the caller's session and refuses."* `docs/64` Q5 carries the prototype output: `...and Q's voucher with Q's uuid supplied → t  <-- TENANT CROSSED`, versus the corrected form: `P's session activates Q's voucher → f`, `caller names Q's uuid as its session key → DENIED: no customer authority`. |
| **6. Does the portal path already cover it?** | **Functionally, yes.** A receptionist can type the code into the guest's device, or into the portal on any device attached to that venue's NAS, and hand over the resulting credentials. The portal path already permits re-retrieval while the voucher is `active` (`docs/67` §3.8), which is exactly the "the guest lost it" case a front-desk button would otherwise serve. What the portal path does **not** give is a record attributing the activation to a named staff member — which is the only distinct thing a front-desk button buys. |

### B.1 The constraint that binds it if it is ever built

`docs/65` §7, stated as a prohibition and worth repeating verbatim in effect:

> **A separate function with a separate grant.** *"If not required, it is not
> built. Under no circumstance does `dnb_app` get generic redemption authority
> because front-desk activation might exist."*

So Decision 4 does **not** block F-7. It cannot widen the portal function, it
cannot add a flag to it, and it cannot grant `dnb_app` redemption authority.
`docs/65` §13 lists its build cost as *"the operator function, its grant and its
tests — or nothing."*

**Conclusion offered:** Decision 4 is genuinely independent of F-7 and may stay
open indefinitely without blocking implementation. The only reason to decide it
now would be if the answer were **yes**, because then §A.1 row 6's re-retrieval
budget and the actor model would both need a second case.

---

## PART C — THREE ITEMS THE EVIDENCE FORCES ME TO RAISE

These are not new decisions I am asking for. They are places where the documents
disagree with something recently decided, and I would rather surface that than
let an implementation quietly pick a side.

### C.1 Recorded as closed

- **D-1a — CLOSED.** The guest-supplied NAS is recorded as
  `detail.nas_claimed`, as untrusted request context. `source` is not
  overloaded. Authorization derives from `voucher.site_id` and that site's
  authorized NAS mapping. The portal can never choose or establish a voucher's
  site. Tests required: changing `nas_claimed` cannot change the voucher's
  owning site (this is `docs/87` T-13, which already asserts it).
- **P-1 — CLOSED.** `dnb_portal` is a distinct unauthenticated-path role with
  the minimum possible privilege: EXECUTE on one narrowly scoped redemption
  function and the ticket-status function, and **no** table privileges. The
  eleven prohibitions are recorded in `docs/87` §C.2 R-2 and will be asserted by
  execution (`docs/87` T-14).

### C.2 `docs/65` §8 requires an attempt store that `docs/87` did not carry

`docs/65` §8 is marked **SETTLED** and specifies more than `docs/87` §E did:

- **Failed unauthenticated attempts are recorded — in a separate non-tenant
  store, not `mt_audit_log`.** Three reasons given: volume (a guessing run would
  fill a tenant-facing business record), tenancy (`mt_audit_log` is FORCE RLS
  and organised by customer, so an unknown-code attempt *"would be written and
  readable by nobody"*), and retention (business audit and attempt telemetry
  want opposite lifetimes).
- The outcome is classified: `unknown_code`, `unknown_nas`, `already_active`,
  `foreign_tenant`, `wrong_site`, `unbound_voucher`, `already_bound_elsewhere`,
  `race_lost` — *"the guest is told `invalid` for every one of them; the record
  keeps the distinction."*
- **The presented code is never stored raw** — `code_prefix` (the first block)
  and `code_hash` only.
- **`docs/65` §9.5 puts the rate-limit counters in this same store.**

**This is consistent with the instruction "do not write audit rows for rejected
redemption attempts"** — the attempt store is not `mt_audit_log`, and no tenant
audit row is written. But `docs/87` §E said a refused redemption *"writes no
audit row at all"*, which reads as *no record at all*. **That is wrong**, and
the correction matters twice over: the attempt store is **required** by the
settled design, and **Decision 5's counters cannot exist without it.**

Recorded here as a correction to `docs/87` §E. No action requested beyond
noting that the attempt store is a prerequisite, not an optional extra.

### C.3 `docs/65` §8 SETTLED adds `guest` — the actor decision points the other way

`docs/65` §8, under the heading *"Audit actor taxonomy and the attempt record —
**SETTLED**"*, proposes extending the taxonomy:

> | `guest` | an unauthenticated portal caller | **unknown until the code resolves, and never for an unknown code** |
>
> *"`guest`, `worker` and `radius` are additions. `customer_id` already being
> nullable is what makes `guest` representable."*

`docs/64` §10 lists the same as an open item: *"extend `mt_audit_log` with a
`guest` actor kind and a write policy, **or give redemption its own log table**.
Either is a migration."*

**I reported in `docs/86` that the taxonomy should not be invented, and the
decision taken was not to add `guest`. I should have surfaced `docs/65` §8 at
that point; I did not, and the decision was taken without it in view.**

The decision still holds up, and I am not asking to reopen it — but the reason
is now different and worth stating:

- `docs/64` §10 offered two ways to satisfy `docs/65` §8: **add `guest` to
  `mt_audit_log`**, *or* **give redemption its own log table**.
- `docs/65` §8 then chose the second for failed attempts, on three independent
  grounds (volume, tenancy, retention).
- The successful activation is the only row that lands in `mt_audit_log`, and it
  is performed with **no identity presented** — so `system` describes it
  accurately, and `guest` would hold a constant.

So **`docs/65` §8's requirement is satisfied by the attempt store of §C.2, not
by a new `actor_kind`** — the second of the two options `docs/64` §10 offered.
That reading reconciles the documents without a schema change to the audit
contract.

**One line of direction would settle it:** either confirm that reading, or say
that `docs/65` §8's `guest` addition is superseded. Without one, a future
session will find `docs/65` §8 marked SETTLED and conclude the opposite.

### C.4 One superseded sentence, for the record

`docs/65` §8 says a successful redemption records *"the NAS; the site **resolved
from** that NAS."* That is superseded by `docs/68` §2.2 and by D-1a: the site
comes from `voucher.site_id`, and the NAS is recorded as a claim. Noted so the
SETTLED heading does not later be read as authority for deriving the site from
the NAS.

---

## What this document does not do

Decides nothing beyond recording D-1a and P-1 as closed. Implements nothing.
Changes no schema, migration, code, role, privilege, configuration or
deployment. Does not pick Decision 5's values. Does not answer Decision 4. Does
not apply migration 020 to production. Does not deploy C-b. Does not create
`dnb_site_nas` or `dnb_cred_site`. Does not verify physical MikroTik hardware.
Does not decide Q2 or `dnb_site_nas` cardinality. Does not start F6-B. Contacts
no production system. Suite unchanged at **1,228 assertions green**.
