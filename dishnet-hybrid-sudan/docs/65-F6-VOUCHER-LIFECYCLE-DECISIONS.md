# 65 — F6: voucher lifecycle decision document

**Status: DECISION DOCUMENT. Nothing implemented. F6 not fixed. No production
object changed. No captive portal, no Admin API, no change to F1–F13, no
contact with Domain A or Starlink.**

**Revision note.** The first version of this document (commit `f42b5af`) framed
Decision 1 as *"when is `mt_hotspot_users` written?"*. That framing was
incomplete: it treats a control-plane registry row as though it were the
authentication credential. This revision restates the decision as the complete
credential lifecycle, adds the AAA layer as an explicitly separate third thing,
and measures all three layers at every lifecycle event. §11 is rewritten: the
earlier version proposed amending **F11** under one of the models, and that is
withdrawn — F11 describes what a voucher *is*, not the event at which the AAA
credential is published. The prior version remains in git history and is not
edited away.

---

## 0. The factual distinction, frozen before anything is reconciled

Recorded as separate statements. **None of these is to be silently rewritten to
match another.** The chosen lifecycle decision must resolve the discrepancy
explicitly — see §11.

> **DOCUMENTED / FROZEN** — redemption creates the RADIUS identity.
> docs/45 §2.1: *HotSpot user — Created by: **Redeeming a voucher***.

> **IMPLEMENTED** — issue creates the `mt_hotspot_users` row.
> `VoucherService::issue()` inserts one per voucher at batch creation, with the
> stated reason *"a code works the moment it is handed over rather than when a
> queue gets to it."*

> **CURRENTLY OBSERVED** — voucher redemption state is not consumed by the
> session or accounting path. `mt_session_account` resolves customer and
> voucher from `mt_hotspot_users` by `radius_username` and never reads
> `mt_vouchers`. The only reader of `mt_vouchers.state` in `src/` is
> `revoke()`, guarding its own `UPDATE`. No view exists. No HTTP route performs
> redemption.

> **AND — established in this gate** — neither model is wired to anything.
> No AAA credential has ever been published. §1 and §2 measure this.

---

## 1. Three layers, which are not the same thing

The word "identity" has been doing the work of three separate objects. They are
separated here and kept separate for the rest of the document.

| | **1. `mt_vouchers`** | **2. `mt_hotspot_users`** | **3. `radcheck` / `radreply`** |
|---|---|---|---|
| What it is | the commercial / control-plane **voucher record** | a control-plane **mapping/registry** — voucher ↔ `radius_username` ↔ customer | the **actual AAA credential and enforcement state** |
| Lives in | control-plane database (`DNB_DSN`) | control-plane database (`DNB_DSN`) | database `radius`, user `radius`, container `dn-phase0-postgres` — **a separate PostgreSQL instance** |
| Holds | code, plan, price, `state`, `activated_at`, `expires_at`, `site_id` | `voucher_id`, `customer_id`, `radius_username`, `created_at` | `Cleartext-Password`, `Expiration`, reply attributes such as `Mikrotik-Rate-Limit` |
| Holds an authentication secret? | the code, as a commercial instrument | **no — no password column exists** | **yes** |
| Can FreeRADIUS read it? | no | **no** | yes — this is the only one it reads |
| Written by | `VoucherService` | `VoucherService::issue()` | **nothing, today** |

**`mt_hotspot_users` is a registry, not a credential.** It has no password
column, no expiry column and no reply attributes, and it sits in a database
FreeRADIUS has no connection to. It records *which RADIUS username belongs to
which voucher and customer*, which is what `mt_session_account` needs when
accounting comes back. It cannot authenticate anyone.

### 1.1 The measurements behind that table

| Measurement | Result |
|---|---|
| `mt_hotspot_users` columns | `voucher_id, customer_id, radius_username, created_at` |
| What FreeRADIUS reads | `radcheck` / `radreply` — docs/30 §346, docs/33 §135, docs/36 §105 |
| Where those live | database `radius`, user `radius`, container `dn-phase0-postgres` — docs/36 §66–67, §155 |
| Do they exist in the control-plane database? | `SELECT count(*) … relname IN ('radcheck','radreply','radacct','nas')` → **0** |
| Does the control plane hold a second DSN? | `Database.php:77` — one DSN. **No connection to the `radius` database exists anywhere in `src/`** |
| What `voucher.publish` does | `RouterOsDelivery::deliver()` → `assertRadiusBacked()` — checks the **router's** hotspot profile. Writes no AAA row |
| What `voucher.revoke` does | the same `assertRadiusBacked()` |
| Live AAA row counts | docs/00 §525: `radcheck`, `radreply`, `radacct`, `nas` — **all 0 rows** |

The delivery handler records the belief that produced this: *"With RADIUS the
credential lives in our database and FreeRADIUS reads it; a router holds no
per-voucher state. So publishing a batch is not a router operation."* The first
clause is true of the **`radius`** database. The control plane does not write
there, so the conclusion does not hold.

**No voucher in this system has ever produced an AAA credential.** That is why
`mt_vouchers.state` has no consumer: the consumer is the publication step, and
the publication step was never built.

---

## 2. The complete credential lifecycle

```
  CREATE VOUCHER                  mt_vouchers row, state='unused'
        |
        v
  PUBLISH / ACTIVATE              <-- the step that does not exist today
        |
        v
  AAA CREDENTIAL EXISTS           radcheck: Cleartext-Password [, Expiration]
        |                         radreply: rate limit and other reply items
        |
        +-- unused      the credential may exist before anyone redeems (Model A)
        +-- active      redeemed; validity window running
        +-- expired     Expiration passed
        +-- revoked     withdrawn by the customer
        |
        v
  AUTHENTICATION / SESSION        Access-Accept, then radacct
        |
        v
  EXPIRY / REVOCATION
        |
        v
  AAA CREDENTIAL REMOVED OR DISABLED   <-- also does not exist today
        |
        +-- removal does not end a session already established (§4.2)
```

Two questions are separable here, and the gate is right that collapsing them is
what caused the earlier ambiguity:

1. **At which event is the AAA credential published?** — Decision 1, §3.
2. **Is publication the same event as voucher creation?** — the same decision
   seen from the other side: Model A says yes, Model B says no.

A third question is not a product choice at all and is settled in §4: **the
credential must be removable and must be able to expire.**

---

## 3. Decision 1 — A or B, measured across all three layers

`mt_vouchers` and `mt_hotspot_users` are measured in the committed prototype.
The AAA layer is measured against a simulation of `radcheck`/`radreply` whose
row shape is taken verbatim from docs/33 §416–419 and docs/36 §214–215, with the
lookup FreeRADIUS performs (fetch the check items for the username; apply
`Cleartext-Password` as the secret; apply `Expiration` as a check item). The
simulation is reproduced in the appendix.

**Fidelity limits, stated plainly.** The simulation is a schema in one instance;
the real boundary is a separate instance with its own credentials — which is
exactly what open decision 7 exists to settle. FreeRADIUS itself is not run
here; there is no FreeRADIUS in this environment and the production server is
not to be touched. What is measured is *which rows exist*, and what a faithful
implementation of the documented lookup returns for them.

### A — AAA credential published at voucher issue

```
  event                      | voucher | registry| radcheck                    | RADIUS answers
  ---------------------------+---------+---------+-----------------------------+---------------
  1 printed, unsold          | unused  | present | Cleartext-Password          | Access-Accept
  2 unused, revoked          | revoked | present | no rows                     | Access-Reject (no such user)
  3 unused, shelf life passed| unused  | present | Cleartext-Password          | Access-Accept
  4 redeemed                 | active  | present | Cleartext-Password+Expiration| Access-Accept
  5 active, revoked          | revoked | present | no rows                     | Access-Reject (no such user)
  6 active, expiry passed    | active  | present | Cleartext-Password+Expiration| Access-Reject (Expiration passed)
```

### B — AAA credential published only at redemption / activation

```
  event                      | voucher | registry| radcheck                    | RADIUS answers
  ---------------------------+---------+---------+-----------------------------+---------------
  1 printed, unsold          | unused  | absent  | no rows                     | Access-Reject (no such user)
  2 unused, revoked          | revoked | absent  | no rows                     | Access-Reject (no such user)
  3 unused, shelf life passed| unused  | absent  | no rows                     | Access-Reject (no such user)
  4 redeemed                 | active  | present | Cleartext-Password+Expiration| Access-Accept
  5 active, revoked          | revoked | present | no rows                     | Access-Reject (no such user)
  6 active, expiry passed    | active  | present | Cleartext-Password+Expiration| Access-Reject (Expiration passed)
```

Rows 2, 5 and 6 above assume the removal and expiry mechanisms of §4 exist. They
do not exist today. **Without them, rows 2, 5 and 6 read `Access-Accept` under
Model A and, for row 5 and 6, under Model B as well** — which is the measured
result recorded in the prior version of this document and preserved in §14.

### 3.1 What the comparison shows

| | **A — published at issue** | **B — published at redemption** |
|---|---|---|
| **Unsold paper** | every printed code is a live credential. Row 1: **Access-Accept for a code nobody bought** | no credential exists. Row 1: rejected |
| **Shelf life** | row 3 is indistinguishable from row 1 — an unsold code stays accepted indefinitely | nothing to leak |
| **Where the guest's access begins** | at printing | at redemption |
| **Publication events per voucher** | **two** — create at issue (no `Expiration` yet, since `expires_at` is null), then amend at redemption to add it | **one** |
| **Failure on the guest's path** | none — the credential is already there | redemption becomes provisioning; the portal must handle publication failure |
| **Revocation before redemption** | must reach the AAA store to be real | nothing published, so nothing to withdraw |
| **`mt_vouchers` / registry** | identical in both, except the registry row's timing | identical |
| **Blast radius of a leaked batch** | the whole batch is usable immediately | only codes someone redeems |

### 3.2 A hazard specific to Model A, measured

Model A needs a *second* publication at redemption to attach `Expiration`.
Implemented naively as another insert, it silently duplicates check items:

```
  after one publish:              2 rows: Cleartext-Password+Expiration
  after a second naive publish:   4 rows: Cleartext-Password+Cleartext-Password+Expiration+Expiration
  what RADIUS answers now:        Access-Accept
  after unpublish-then-publish:   2 rows: Cleartext-Password+Expiration
```

The duplication is **silent** — authentication still succeeds, so nothing
surfaces the fault. And the simulation is *more* forgiving than the real thing:
it reads one row per attribute, whereas FreeRADIUS applies every check item it
finds, so two `Expiration` rows disagreeing would both be applied. Publication
under Model A must therefore be idempotent by construction (delete-then-insert,
or an upsert keyed on username+attribute), not an insert.

### 3.3 What the evidence does not decide

It does not decide A versus B. The honest trade:

* **A** makes a code work the instant it is handed over, with no queue between
  printing and use — the reason the current code gives. Its costs are a stock of
  live credentials for paper nobody bought, no shelf-life concept, two
  publication events, and a revoke that must reach the AAA store to mean
  anything.
* **B** makes the credential's existence mean something: it exists because
  someone redeemed. Its cost is that redemption becomes a provisioning step on
  the guest's critical path, and the portal must survive its failure.

That is a product and operations judgement. It stays open — §13, decision 1.

---

## 4. What both models require regardless — not optional product behaviour

**This is not a choice.** Under either model the system is incorrect without
all three of the following.

### 4.1 Expiry must act on the AAA credential

docs/32 §A13 requires *"Wait for expiry: access stops **with no
intervention**."* Today nothing writes `mt_vouchers.state='expired'`, and
`expires_at` is read by nothing. The mechanism docs/33 §428 already specifies is
the `Expiration` check item in `radcheck`. Row 6 in both tables above is
`Access-Reject (Expiration passed)` **only because the simulation published one**.

### 4.2 Revocation must remove or disable the AAA credential

`VoucherService::revoke()` flips `mt_vouchers.state` and touches nothing else.
Measured in the prior gate and preserved in §14: a revoked voucher — whether
revoked while unused or while active — was still served. Rows 2 and 5 above are
`Access-Reject` **only because the simulation deleted the rows**.

**A caveat that is derived, not measured:** removing a credential stops the next
*authentication*. It does not tear down a session already established — that
requires a Disconnect-Request / CoA to the NAS. The intent kind
`session.disconnect` already exists in `RouterOsDelivery`, so the mechanism for
that half is present; what is absent is anything connecting revocation to it.
Revoking an **active** voucher therefore needs both actions, not one.

### 4.3 Publication must be idempotent

Per §3.2. Republication must converge on one credential, not accumulate check
items.

---

## 5. Site binding — A, B or C

Measured in the committed prototype. Voucher issued for P's Site 1; P also owns
Site 2; Q is a different customer.

```
  mode A  issued@S1 used@S1: ok         issued@S1 used@S2: refused
          unbound  used@S1: refused     P's code at Q's NAS: refused
  mode B  issued@S1 used@S1: ok         issued@S1 used@S2: refused
          unbound  used@S1: ok          P's code at Q's NAS: refused
  mode C  issued@S1 used@S1: ok         issued@S1 used@S2: ok
          unbound  used@S1: ok          P's code at Q's NAS: refused
```

**Cross-customer redemption is refused under all three.** That is a floor, not
an option: it sits before the binding logic, and Q's router never serves P's
guest on Q's uplink.

| | **A — bound at issue** | **B — bound at first use** | **C — roaming in the estate** |
|---|---|---|---|
| Guest experience | works only where printed for | works at the first AP reached, then only there | works anywhere the customer operates |
| Operator experience | stock is per-site; moving paper is revoke-and-reissue | one pool serves every site | one pool, no site discipline |
| Revenue attribution | exact, from issue | exact, from first use | **impossible** — no site is ever fixed |
| Support | answerable from the voucher row | answerable, but the answer changes after first use | few site questions, and no site answers |
| Security | tightest: a leaked code is useless away from one AP | a leaked code is useful once, anywhere, then pinned | a leaked code is useful estate-wide for its lifetime |
| Guest moves site | refused | refused after first use | allowed |
| Printed for the wrong site | dead paper until revoked and reissued | self-corrects on first use | never an issue |

A product decision, not a database-security one. Open — §13, decision 2.

---

## 6. Portal response contract — P1, P2 or P3

**Security baseline, settled:** the unauthenticated guest never receives
`customer_id`. The prototype's return shape carries no uuid:
`ok, reason, radius_username, duration_s, expires_at`.

docs/33 §416–417 shows the intended `radcheck` row as
`('t1-TESTCODE01', 'Cleartext-Password', ':=', 't1-TESTCODE01')` — **username
and password identical**.

| | **P1 — `ok` + `expires_at` only** | **P2 — return the AAA username** | **P3 — the backend completes login** |
|---|---|---|---|
| How login completes | the guest or the portal page posts the **voucher code** to the router's HotSpot login form | the portal posts the returned username/password | the control plane posts to the router |
| Requires | `radcheck.username` **is** the code, dropping the `radius_ref-` prefix | the current prefixed username | a synchronous router interaction |
| Discloses | nothing the guest did not already type | `radius_ref` — an opaque, stable per-customer label | nothing |
| Namespace | codes are already `UNIQUE`, so a global username space is sound | per-customer namespace retained | either |
| Cost | `radius_username` stops carrying the customer, so `mt_session_account`'s tenant resolution moves to the voucher (which it already reaches by primary key) | a guest learns a stable label; two guests at two venues could tell the venues share an owner | **see below** |

### 6.1 P3 conflicts with F2 — recorded, not resolved

**F2: *"The intent queue is the only management path to a router. No screen
issues a synchronous router command."*** (docs/53 §2, from docs/42 §0.)

P3 has the control plane contact a router synchronously, on a guest's request,
outside the intent queue. Whether a HotSpot *login* POST is a "management
command" is arguable — it is not configuration — but the architecture's stated
rule is about the *path*, not the payload, and F3 reinforces it (*"the Customer
PWA never talks to a router"*).

**This conflict is recorded, not resolved here.** P3 is not to be chosen without
an explicit decision on whether it falls inside F2, and if it does, an amendment
to F2 under docs/53 §5. It is not resolved implicitly by picking P3.

**`radius_ref` is an opaque identifier, not a customer identifier.** Measured:
the DEFAULT is `'c' || substr(replace(gen_random_uuid()::text,'-',''),1,10)` — a
*fresh* random uuid, not the row's own id — and 0 of 2 rows derive from the
customer id. Migration 010's one-time backfill *did* derive from the id, so rows
predating it are an exception to check before relying on this in production.

---

## 7. Front-desk activation

**Not decidable from the codebase.** No route, no screen, no intent kind and no
document describes such a workflow. It is a business question.

The test that settles it: *does a customer's staff ever hand a guest internet
access without the guest touching a portal?* A hotel typing the code into a
lobby tablet is the same guest flow with different hands and needs nothing new.
A hotel activating a code at check-in so the guest's phone simply works is a
different operation and needs its own capability.

**If required**, the shape is fixed by measurement from docs/64 §2 Q5:

```
  operator (session credential, never a supplied uuid)
     -> an explicit voucher id from their own list
     -> a site they are authorized for
     -> activation, audited as 'principal'
```

— a **separate function with a separate grant**. The reason is measured: the
first draft of that function took the customer as an argument and crossed the
tenant boundary on its first call; the corrected form derives the tenant from
the caller's session and refuses.

**If not required, it is not built.** Under no circumstance does `dnb_app` get
generic redemption authority because front-desk activation *might* exist.

---

## 8. Audit actor taxonomy and the attempt record — SETTLED

Measured, current: `mt_audit_log.actor_kind` is
`CHECK (actor_kind IN ('principal','staff','system'))`, `customer_id` is
nullable, and the table is RLS **enabled + FORCE**.

| kind | who | tenant |
|---|---|---|
| `principal` | a customer's authenticated user or operator | always known |
| `guest` | an unauthenticated portal caller | **unknown until the code resolves, and never for an unknown code** |
| `staff` | a DishNet admin under a named identity | known and named |
| `worker` | the intent worker on a claimed intent | from the intent |
| `radius` | the AAA ingestion path | from the AAA identity |
| `system` | migrations, sweepers, scheduled jobs | often none |

`guest`, `worker` and `radius` are additions. `customer_id` already being
nullable is what makes `guest` representable.

**A successful redemption records** actor kind and actor; the NAS; the site
*resolved from* that NAS; voucher and customer; outcome `redeemed`; time; and in
`detail` the binding mode and resulting `expires_at`.

**A failed attempt records the same shape minus what did not resolve.**
`voucher_id` and `customer_id` stay NULL for an unknown code. Outcome is one of
`unknown_code`, `unknown_nas`, `already_active`, `foreign_tenant`, `wrong_site`,
`unbound_voucher`, `already_bound_elsewhere`, `race_lost`. **The guest is told
`invalid` for every one of them**; the record keeps the distinction.

**The presented code is never stored raw.** `code_prefix` (the first block —
enough to see a guessing run) and `code_hash` (enough to correlate repeat
attempts on one code), never the code. A code is a live bearer credential until
it expires, and a guest who mistypes one character would otherwise write
*someone else's valid code* into a table support staff can read and search.

**Failed unauthenticated attempts go to a separate non-tenant store**, not
`mt_audit_log`. Three reasons: volume — a guessing run would fill a tenant-facing
business record; tenancy — `mt_audit_log` is FORCE RLS and organised by
customer, so an unknown-code attempt would be written and readable by nobody;
retention — business audit and attempt telemetry want opposite lifetimes. That
store is also where §9's counters live.

---

## 9. Rate-limit architecture — SETTLED in shape, open in numbers

### 9.1 The keyspace is not the answer

32 symbols, length 10, CSPRNG, 1.1 × 10¹⁵ — blind guessing is not the realistic
attack. The realistic one is **an attacker obtains or observes a legitimate
code**. Rate limiting bounds three different things: replay and sharing of an
observed code; an enumeration run that would otherwise be free and invisible;
and denial of service against an unauthenticated endpoint that writes on every
call.

### 9.2 The precedent already in this codebase

`mt_auth_verify_code` (migration 007):

```sql
UPDATE mt_auth_codes SET attempts = attempts + 1 WHERE id = r.id;
IF r.attempts >= 4 THEN RETURN; END IF;
-- "Returns zero rows on any failure … The caller cannot distinguish
--  these, which is the point."
```

Three properties transfer: the counter increments **before** the check, so a
wrong guess always costs; the failure shape is uniform; the state is in the same
transaction as the thing it protects.

One does **not**. An OTP is bound to the phone that requested it, so a
per-credential counter is a per-attacker counter. A voucher code is bound to
nobody — the attacker picks the code. **A per-code counter does not slow an
enumeration run at all.** That gap is why per-source dimensions are mandatory.

### 9.3 Dimensions, with per-NAS primary

| Dimension | Bounds | Notes |
|---|---|---|
| **NAS / site** | **primary** — a run mounted from inside one venue | the most trustworthy signal available: asserted by infrastructure DishNet provisioned, not by the guest |
| code / code-hash | hammering one code | the OTP pattern; cheap and exact; useless against enumeration |
| source IP | **not primary** | behind the customer's NAT every guest at a site shares one address, so a low limit locks out a whole venue. Coarse volumetric use only |
| device / MAC | one phone retrying | guest-supplied, trivially spoofed; telemetry, never a gate |
| global portal | total blast radius | a last-resort breaker |

### 9.4 Shape

* **Burst** — a few rapid attempts is a human mistyping a code off paper.
  Refusing the third makes the product unusable at a reception desk.
* **Sustained** — the limit that matters, per NAS over minutes.
* **Lockout** — a decaying window, never a hard lock. A hard per-NAS lock is a
  denial-of-service primitive against a paying customer's whole venue.
* **Response uniformity** — non-negotiable: a rate-limited attempt returns the
  same `invalid` as a wrong code. A distinguishable reply tells an attacker
  their probe worked.

### 9.5 Where the state lives

Measured: the PHP Redis extension is present (`php -m` → `redis`;
`class_exists('Redis')` → true). The control plane holds one DSN and no Redis
client in `src/`.

**PostgreSQL is the authoritative enforcement**, for the per-code and per-NAS
counters: they must be transactional with the redemption attempt and its audit
record — a counter that can disagree with the audit trail is worse than none —
they must survive a restart, and they need no new dependency, since §8's attempt
store must exist anyway. **Redis or an edge layer may be used for volumetric
shedding**, which is expendable, must not write to disk per packet, and exists
to stop load before it reaches PHP.

The transactional limits are **transaction logic** and belong inside the
redemption function, where a second caller cannot bypass them. Volumetric
shedding is **infrastructure** and belongs in front of the application.

---

## 10. Privilege model — SETTLED

Every line has a measured denial behind it in `tools/audit/proto_f6_cases.sh`.

```
dnb_portal   (new, LOGIN)   unauthenticated guest / captive portal
  EXECUTE    exactly one narrowly scoped redemption capability
  no table privileges at all
  cannot set customer context   -- measured: setting app.customer_id changed nothing
  cannot choose customer_id     -- the signature is (code, nas); no such argument exists
  cannot choose a site          -- the site is resolved from the NAS, never supplied
  cannot call voucher management functions

dnb_app      customer-authenticated request role
  NO redemption privilege unless §7 is explicitly approved
  measured: denied even for its own voucher
  the current EXECUTE on mt_voucher_redeem is revoked; that revocation IS F6

dnb_radius   AAA ingestion
  NO redemption authority -- measured: denied.  Accounting only.

dnb_admin    DishNet support
  NO ordinary guest-redemption privilege
  a separate support/recovery path, reason mandatory, staff-audited

PUBLIC       nothing. Every function REVOKEd from PUBLIC by name first.
```

---

## 11. The docs/45 §2.1 discrepancy — preserved, not amended

**F11 is not amended.** F11 — *"A voucher is a RADIUS user. A HotSpot user is
not a DishNet account"* — describes **what a voucher is**, not the lifecycle
event at which the AAA credential is published. It is true under both models and
is untouched by this decision. The earlier version of this document proposed
amending it under Model A; that proposal is withdrawn.

The discrepancy is recorded as an architectural discrepancy and preserved:

* **current implementation** creates `mt_hotspot_users` at **ISSUE**;
* **docs/45 §2.1** says redemption creates the RADIUS identity;
* **neither statement is silently rewritten.**

**The chosen lifecycle decision must resolve it explicitly.**

* If **B** is chosen, the code changes to match docs/45 §2.1 and the
  `mt_hotspot_users` table comment becomes true. No document is amended.
* If **A** is chosen, docs/45 §2.1's creation-time cell is superseded by a dated
  amendment in docs/45 itself, stating what replaced it and why, and the table
  comment is corrected to match. F11 stays as written, and docs/53 needs no
  amendment because no frozen item changes.

Separately and under both models, §4's requirements — publication, expiry and
revocation acting on the AAA credential — are **new architecture** rather than an
amendment to anything, and belong in their own document.

---

## 12. Exact changes required, once the decisions are made

Nothing here is written yet.

**Unconditional:**

| # | Change |
|---|---|
| M1 | migration 019: revoke `EXECUTE ON mt_voucher_redeem` from `dnb_app`; create role `dnb_portal`; create the portal redemption function; `REVOKE … FROM PUBLIC` by name; grant to `dnb_portal` only |
| M2 | migration 020: extend `mt_audit_log.actor_kind` to §8's taxonomy, with the write policy the new actors need under FORCE RLS |
| M3 | migration 021: §8's attempt store, not tenant-scoped, with retention, carrying §9's per-code and per-NAS counters |
| A1 | `Database::portal()` — a sixth identity alongside the existing five |
| A2 | `VoucherService::redeem()` retargeted at the portal function and its new return shape; the old signature removed |
| A3 | tests: docs/64 §8's ten cases as a committed suite, plus the lifecycle comparison and the concurrency case |
| D1 | an architecture document for AAA publication, `Expiration`, idempotent republication, and revocation-plus-disconnect (§4) |
| D2 | the answer to decision 7 — the publication and reconciliation mechanism (§13) |

**Conditional on decision 1:**

| | Model A | Model B |
|---|---|---|
| documents | dated amendment to docs/45 §2.1; table comment corrected; F11 untouched | none — instead `issue()` stops writing `mt_hotspot_users` and the portal function writes it |
| publication | two events: create at issue, **idempotent** amend at redemption | one event |
| revoke | must reach the AAA store for unused **and** active vouchers | must reach it for vouchers revoked after redemption |
| shelf life | needs a concept that does not currently exist (§3.1 row 3) | not needed |

**Conditional on decision 2:** the site check inside the portal function; under
A only, a `NOT NULL` on `mt_vouchers.site_id`.

**Conditional on decision 3:** under P1, `radcheck.username` becomes the code and
`mt_session_account`'s tenant resolution moves to the voucher; under P2, nothing
structural; under P3, the F2 question of §6.1 must be settled first.

**Conditional on decision 4:** the operator function, its grant and its tests —
or nothing.

---

## 13. Open decisions

| # | Decision | Blocking |
|---|---|---|
| **1** | **Model A or Model B** — at which event the AAA credential is published, and whether that is the same event as voucher creation | M1's function body, D1, and §11's resolution |
| **2** | **Site binding A, B or C** | the portal function's site check; a possible `NOT NULL` |
| **3** | **Portal contract P1, P2 or P3** | the return shape; under P3, the F2 conflict of §6.1 first |
| **4** | **Front-desk activation: required or not** | whether an operator capability exists at all |
| **5** | **Rate-limit numbers** — burst, sustained, window, per NAS | §9 fixes the shape and dimensions; the values need a real venue's traffic |
| **6** | **Retention** for the attempt store | M3 |
| **7** | **Where does the control plane publish to the separate RADIUS database, and what is the authoritative synchronization / reconciliation mechanism?** | **everything downstream.** See below |

### Decision 7 is now a first-class architecture question

The control plane holds one DSN and no connection to the `radius` database. Any
design must answer:

* **who writes** — the application directly, a dedicated publisher service, a
  worker acting on the existing `voucher.publish` / `voucher.revoke` intents, or
  database-level replication;
* **which store is authoritative** when they disagree, and how drift is
  *detected* rather than assumed — a voucher marked `active` whose `radcheck`
  row is missing, and a `radcheck` row with no live voucher, are both silent
  today;
* **what happens when publication fails** — under Model B this is on the guest's
  critical path;
* **what credential the publisher holds** in the `radius` database, and why that
  does not become a new lateral path between the two databases.

Until this is answered, a redemption path could be built that is correct in
every respect and whose "published" state is not authoritative at the AAA layer.

§8's taxonomy, hashing and attempt separation, §9's dimensions and placement,
and §10's privilege model are **security decisions and are settled**. They are
not on this list.

---

## 14. Regression state and corrections

`tests/run.sh`: **all suites passed, 718 assertions.** No project code changed in
this gate or the previous one.

**Correction retained from the previous gate.** The first lifecycle run reported
*"one voucher revoked"* while the counts on the same line read `revoked=0`. The
prototype's `pf_vouchers` had no `revoked_at` column, so the revocation errored
into a discarded stream. **That earlier result is explicitly discarded: it was
not evidence of anything.** The column was added, the revoke result is printed
rather than swallowed, and the corrected run stands — a revoked voucher *is*
still served when nothing removes the AAA credential, which is §4.2.

**A second instance of the same mistake, this gate.** The first run of the AAA
simulation showed `radcheck` empty at every event. `pf_publish` had no privilege
on the simulated `rad` schema and every call was failing into a `>/dev/null`.
Schema ownership was corrected and the harness was rewritten so a failing
statement prints instead of vanishing. **The tables in §3 are from the corrected
run.** The lesson is the same one this project keeps relearning: a step that
produces no error because nobody looked is not a step that ran.

**`tools/audit/s1_s2_probe.php` remains non-runnable** and is deliberately not
repaired here. The eventual repair must satisfy: the production security model
stays non-superuser and `NOBYPASSRLS`; fixture setup uses the trusted seed path
(`mt_customer_create` and tenant contexts); observation uses the explicit
`Database::inspector()` identity; and the adversarial semantics are unchanged —
the probe must still be capable of failing the way it originally could. It
belongs in a test-maintenance change of its own.

---

## Appendix — the AAA simulation

Not committed as a tool, because this gate commits only this document. It is
reproduced here so §3 is reproducible. It loads on top of the committed
`tools/audit/proto_f6.sql` and `tools/audit/proto_f6_lifecycle.sql`.

```sql
CREATE SCHEMA rad;
CREATE TABLE rad.radcheck (id bigserial PRIMARY KEY, username text NOT NULL,
       attribute text NOT NULL, op text NOT NULL, value text NOT NULL);
CREATE TABLE rad.radreply (id bigserial PRIMARY KEY, username text NOT NULL,
       attribute text NOT NULL, op text NOT NULL, value text NOT NULL);
CREATE INDEX ON rad.radcheck (username);
ALTER SCHEMA rad OWNER TO proto_def6;          -- the publisher must own it,
ALTER TABLE rad.radcheck OWNER TO proto_def6;  -- or every publish fails silently
ALTER TABLE rad.radreply OWNER TO proto_def6;

-- What rlm_sql does: fetch the check items for the username, apply
-- Cleartext-Password as the secret and Expiration as a check item.
CREATE FUNCTION rad.authorize(p_user text, p_pass text) RETURNS text
LANGUAGE plpgsql AS $$
DECLARE pw text; exp text;
BEGIN
  SELECT value INTO pw  FROM rad.radcheck WHERE username=p_user AND attribute='Cleartext-Password';
  IF NOT FOUND THEN RETURN 'Access-Reject (no such user)'; END IF;
  SELECT value INTO exp FROM rad.radcheck WHERE username=p_user AND attribute='Expiration';
  IF FOUND AND exp::timestamptz <= now() THEN RETURN 'Access-Reject (Expiration passed)'; END IF;
  IF pw IS DISTINCT FROM p_pass THEN RETURN 'Access-Reject (wrong password)'; END IF;
  RETURN 'Access-Accept';
END $$;

-- The publication step the control plane does not have.
CREATE FUNCTION pf_publish(p_voucher uuid) RETURNS void
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, rad, pg_temp AS $$
DECLARE u text; v record;
BEGIN
  SELECT h.radius_username INTO u FROM pf_hotspot_users h WHERE h.voucher_id = p_voucher;
  IF NOT FOUND THEN RETURN; END IF;
  SELECT * INTO v FROM pf_vouchers WHERE id = p_voucher;
  INSERT INTO rad.radcheck (username,attribute,op,value) VALUES (u,'Cleartext-Password',':=',u);
  INSERT INTO rad.radreply (username,attribute,op,value) VALUES (u,'Mikrotik-Rate-Limit',':=','5M/5M');
  IF v.expires_at IS NOT NULL THEN
    INSERT INTO rad.radcheck (username,attribute,op,value) VALUES (u,'Expiration',':=',v.expires_at::text);
  END IF;
END $$;
ALTER FUNCTION pf_publish(uuid) OWNER TO proto_def6;

CREATE FUNCTION pf_unpublish(p_voucher uuid) RETURNS void
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, rad, pg_temp AS $$
DECLARE u text;
BEGIN
  SELECT h.radius_username INTO u FROM pf_hotspot_users h WHERE h.voucher_id = p_voucher;
  IF NOT FOUND THEN RETURN; END IF;
  DELETE FROM rad.radcheck WHERE username = u;
  DELETE FROM rad.radreply WHERE username = u;
END $$;
ALTER FUNCTION pf_unpublish(uuid) OWNER TO proto_def6;

-- Idempotent republication, per 3.2. A second pf_publish() duplicates
-- check items silently; this converges instead.
CREATE FUNCTION pf_republish(p uuid) RETURNS void LANGUAGE sql
SECURITY DEFINER SET search_path=public,rad,pg_temp
AS $$ SELECT pf_unpublish(p); SELECT pf_publish(p); $$;
ALTER FUNCTION pf_republish(uuid) OWNER TO proto_def6;

CREATE FUNCTION rad_state(p_user text) RETURNS text LANGUAGE sql STABLE AS $$
  SELECT CASE WHEN count(*)=0 THEN 'no rows'
              ELSE count(*)||' rows: '||string_agg(attribute,'+' ORDER BY attribute) END
    FROM rad.radcheck WHERE username = p_user;
$$;
```
