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

**Second revision.** Decision 7 — the AAA publication boundary — was a single
row in the open-decisions table. It is now §12, a section of its own, because
it gates everything downstream: a redemption path can be correct in every
respect while its published state is not authoritative at the AAA layer. §2.1
is added to keep voucher redemption and AAA publication separate concepts even
where the product performs them together. No decision is made in either.

**Third revision.** §12.3 records the deployed FreeRADIUS schema, inspected on
the Phase 0 server. It answers question 8 and sharpens questions 7, 11 and 14.
Still no decision: Decision 1 and Decision 7 remain open.

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

**This terminology is permanent.** From here on these three names mean these
three things and are not used interchangeably. "The RADIUS identity" is not a
usable phrase, because it has meant all three at different points in this
project's history, which is how the discrepancy in §0 survived as long as it
did. Say *voucher record*, *registry row*, or *AAA credential*.

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

### 2.1 Redemption and AAA publication are different events

They may occur inside one transaction, and from the guest's point of view they
are one act. Architecturally they are distinct, and collapsing them is what
produced the original F6 confusion:

```
  Guest presents voucher
        |
        v
  Validate / redeem voucher          control plane: is this code real, unused,
        |                            and valid at this NAS?
        v
  Authorize activation               control plane: may THIS actor start THIS
        |                            voucher's validity window?
        v
  Publish AAA credential             control plane -> RADIUS database
        |
        v
  Guest authenticates                FreeRADIUS reads radcheck, answers
        |
        v
  RADIUS session                     radacct, then back to mt_sessions
```

Each arrow can fail independently, and they fail differently. A code that is
real but already used fails at step 2 and is a business outcome. A publication
that fails at step 4 is an infrastructure outcome: the voucher is legitimately
redeemed and the guest still cannot get online. Step 5 failing after step 4
succeeded is a third thing again. **One error shape for the guest (§8) does not
mean one failure mode for the system**, and §12 exists because steps 4 and 5
have no design at all today.

This separation also keeps the eventual captive portal honest. A portal that
performs all five steps as one opaque call becomes the place where
authentication, voucher state, AAA provisioning and customer authorization mix
together — which is precisely what the four planes exist to prevent.

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
result recorded in the prior version of this document and preserved in §15.

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

**Confirmed against the deployed schema (§12.3).** `radcheck` has no unique
constraint or unique index on `(username, attribute)` — only a surrogate `id`
primary key and a plain btree index. The duplication above is production
behaviour, not an artefact of the simulation, and nothing in the AAA schema will
prevent it.

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

That is a product and operations judgement. It stays open — §14, decision 1.

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
Measured in the prior gate and preserved in §15: a revoked voucher — whether
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
items. §12.3 establishes that the AAA schema will not help: there is no unique
key on `(username, attribute)` in either table, so every part of this must be
built.

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

A product decision, not a database-security one. Open — §14, decision 2.

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

## 12. Decision 7 — the AAA publication boundary

**Not decided here.** This section states the shape of the decision and the
questions any answer must cover. It is a section rather than a table row
because every other decision depends on it: a redemption path can be correct in
every respect and still leave a guest offline, or leave a revoked voucher
working, if the published state is not authoritative at the AAA layer.

### 12.0 Constraints accepted at this gate

These are operator decisions taken on the evidence of §12.3, recorded so the
decision session starts from them rather than reopening them. They narrow
Decision 7; they do not resolve it.

**Q8 is resolved at the evidence level.** Restated as accepted:

* `radcheck` — no UNIQUE constraint or index on `(username, attribute)`;
* `radreply` — no UNIQUE constraint or index on `(username, attribute)`;
* the only uniqueness in either table is the surrogate `id` primary key;
* `radcheck_username` is a normal lookup index and enforces nothing;
* no foreign keys connect credential rows to voucher, customer or accounting
  entities;
* duplicate publication is therefore permitted by the deployed schema;
* **publisher-side idempotency is mandatory.**

**The RADIUS schema is not to be modified.** Adding a unique constraint would
be a divergence from stock FreeRADIUS (§12.3) and a separate architectural and
operational decision. It is not taken, so idempotency has nowhere to live but
the publication mechanism.

**`nas.secret` is accepted as an architectural security consideration for
Q14** — not as licence to broaden any privilege. No publisher is to be created
and no privilege widened until Decision 7 is resolved.

**`dnb_radius` is not to be reused as the publisher.** Its trust direction is
`RADIUS → control plane`; publication is `control plane → RADIUS`. That the
role already exists is not a reason, and the two directions are not the same
grant.

---

### 12.1 The pipeline

```
  CONTROL PLANE                     database: DNB_DSN
    mt_vouchers      commercial / lifecycle state
    mt_hotspot_users voucher <-> radius_username <-> customer registry
        |
        v
  AAA PUBLISHER                     <-- does not exist
        |                               who it is, what it holds, and when it
        |                               runs are all undecided
        v
  RADIUS DATABASE                   database: radius, container dn-phase0-postgres
    radcheck         Cleartext-Password, Expiration
    radreply         Mikrotik-Rate-Limit and other reply items
        |
        v
  FreeRADIUS                        reads the two tables above; nothing else
        |
        v
  HOTSPOT AUTHENTICATION / SESSION  Access-Accept, radacct
        |
        v
  (back to the control plane)        /internal/radius/accounting -> mt_sessions
```

The last arrow is the only one that exists today, and it runs the other way:
accounting is ingested over HTTP into `mt_session_account`. **Everything
between the control plane and the RADIUS database is unbuilt.**

### 12.2 The fifteen questions any answer must settle

Each is annotated with what already exists in this codebase that bears on it,
so the decision is made against evidence rather than instinct. Existing
mechanisms are candidates to reuse *or to consciously reject* — neither is
assumed.

| # | Question | What already bears on it |
|---|---|---|
| 1 | **Which system is authoritative for voucher lifecycle state?** | `mt_vouchers` is the commercial record and the only place price, plan and ownership live. But `radcheck` is what decides whether a guest gets online. Authority for *commerce* and authority for *access* may not be the same system, and saying so explicitly is the first half of question 12 |
| 2 | **Who is permitted to publish, update and remove AAA credentials?** | The four definer roles of migration 017 and the privilege model of §10 are the pattern: one named role, one narrow capability, no table privileges beyond what it needs. `dnb_radius` is the closest precedent — LOGIN, EXECUTE on two functions, **no table privileges at all** |
| 3 | **What exact credential does the publisher hold?** | Nothing exists. Note the asymmetry with `dnb_radius`: that role is a *control-plane* identity used by the accounting ingestion path. A publisher needs an identity in the **`radius`** database, which is the opposite direction and must not be conflated with it |
| 4 | **Is publication synchronous or asynchronous?** | The intent queue is the house asynchronous mechanism, and `voucher.publish` / `voucher.revoke` already exist as intent kinds. But its timing assumptions are built for router provisioning: `max_attempts DEFAULT 5`, `deadline_at DEFAULT now() + interval '7 days'`. **A seven-day deadline is not a design for a guest standing in a lobby**, which is what Model B makes it |
| 5 | **What happens if publication fails?** | `DeliveryResult::retryable()` versus `::permanent()` already distinguishes transient from permanent failure, and `mt_intents.last_error` records why. What is undefined is the *voucher's* state when publication permanently fails after a successful redemption: the code is spent and the guest has nothing |
| 6 | **What if the RADIUS database is temporarily unavailable?** | A special case of 5, and the one that decides 4. Under Model A the answer can be "retry later, nobody is waiting." Under Model B someone is waiting |
| 7 | **What is the idempotency key for a publication?** | `mt_intents.idempotency_key` with the partial unique index `(customer_id, idempotency_key) WHERE idempotency_key IS NOT NULL` is the existing pattern. For a publication the natural key is the voucher, since one voucher yields exactly one AAA credential — but that must be stated, not assumed. **§12.3 sharpens this:** the AAA schema has no unique key on `(username, attribute)`, so idempotency cannot be delegated to it. It must be enforced by the publisher: adding a constraint to the RADIUS schema was available in principle and is **closed by the decision in §12.0** |
| 8 | **How are duplicate `radcheck` / `radreply` rows prevented?** | **Answered — §12.3.** They are not. The deployed schema carries no unique constraint or unique index on `(username, attribute)` in either table; the only uniqueness is a surrogate `id` primary key. Duplicates are structurally permitted, so the simulated result in §3.2 reproduces production behaviour rather than an artefact of the simulation. Everything that prevents duplication must be built |
| 9 | **How are expiry and revoke propagated?** | `Expiration` in `radcheck` is the documented expiry mechanism (docs/33 §428). Revocation has no mechanism. Both intent kinds exist and both currently resolve to `assertRadiusBacked()`, which writes nothing |
| 10 | **What happens to an already-established HotSpot session after revoke or expiry?** | Removing a credential stops the *next* authentication, not a running session. The `session.disconnect` intent kind already exists in `RouterOsDelivery`; nothing connects revocation to it. §4.2 |
| 11 | **How is AAA drift detected?** | The codebase already has a drift pattern for routers: `DeviceRegistry` stores `desired`, reads back `actual`, and `divergence()` compares; `confirm()` re-reads before an intent is marked confirmed. The same shape applies here — a voucher `active` with no `radcheck` row, and a `radcheck` row with no live voucher, are both silent today. **§12.3 adds:** `radcheck` and `radreply` carry no foreign keys at all — `username` is free text linked to nothing — so orphan credentials are structurally permitted and drift detection cannot lean on the database |
| 12 | **Which side wins if `mt_vouchers` and `radcheck` / `radreply` disagree?** | No precedent. F1's Domain A/B rule is about two systems sharing *nothing*; this is two stores that must agree. The safe-by-default answer and the commercially correct answer may differ — a stale `radcheck` row granting access is a revenue and security problem, while a missing one is a support problem |
| 13 | **How is reconciliation performed?** | Follows from 11 and 12. Sweep direction, frequency, and whether reconciliation may *act* or only *report* are all open. A reconciler that silently deletes AAA rows is a denial-of-service against paying guests; one that silently creates them is an authorization bypass |
| 14 | **What prevents the publisher credential from becoming a lateral access path between the two databases?** | The two databases are currently isolated by having no connection at all. A publisher deliberately breaches that. The question is what it may do on each side, and it is the reason 2 and 3 are separate questions. §10's principle applies: one narrow capability, no table privileges beyond it, and nothing that can read the control plane's tenant data. **§12.3 adds a concrete reason:** the `radius` database also holds `nas.secret` — the NAS shared secrets — so a publisher with broad rights there could read every router's RADIUS secret |
| 15 | **How is publication or audit failure surfaced to operators?** | Today the only operator surface is `error_log('[dnb] …')` from the HTTP kernel and `mt_intents.last_error`. Neither is a monitored channel. A publication that fails silently is indistinguishable from one that never ran |

### 12.3 Measured — the deployed AAA schema

**Provenance.** Obtained 2026-09-21 by the operator running four read-only
commands on the Phase 0 server and returning the output. **This session did not
read it directly and could not:** no SSH client or key exists in this
environment, and the egress policy denies the host — the proxy reported
`connect_rejected`, *"gateway answered 403 to CONNECT"*, for
`209.97.137.203:443`. It is recorded as operator-supplied evidence, which is a
weaker provenance than this project's measured results and is marked as such.
Nothing was modified: the commands were two `psql` introspections and one `cat`.

**What is actually enforced** (live introspection, the authoritative source —
the schema file is what *would* be applied, this is what *was*):

```
                           Table "public.radcheck"
  Column   |         Type         | Nullable |           Default
-----------+----------------------+----------+------------------------------
 id        | integer              | not null | nextval('radcheck_id_seq')
 username  | text                 | not null | ''::text
 attribute | text                 | not null | ''::text
 op        | character varying(2) | not null | '=='::character varying
 value     | text                 | not null | ''::text
Indexes:
    "radcheck_pkey"    PRIMARY KEY, btree (id)
    "radcheck_username"             btree (username, attribute)

                           Table "public.radreply"
  ... identical, except op default '='
Indexes:
    "radreply_pkey"    PRIMARY KEY, btree (id)
    "radreply_username"             btree (username, attribute)

Constraints across both tables:
  radcheck | radcheck_pkey | PRIMARY KEY (id)
  radreply | radreply_pkey | PRIMARY KEY (id)
  (2 rows — that is the complete constraint set)
```

**Corroboration.** The shipped
`/etc/freeradius/mods-config/sql/main/postgresql/schema.sql` declares exactly
this, with `create index radcheck_UserName on radcheck (UserName,Attribute);` —
a plain index, and its commented-out alternatives are case-insensitive variants
that are also non-unique. The lowercase column names in the live database are
PostgreSQL folding unquoted identifiers, not a divergence. **The deployed schema
matches the shipped file**, which also makes the file a reliable reference for
the other tables.

#### The seven answers

| | Question | Answer |
|---|---|---|
| 1 | UNIQUE constraint on `radcheck (username, attribute)`? | **No.** A plain btree index of that name exists and enforces nothing |
| 2 | Any relevant uniqueness on `radreply`? | **No.** Same shape |
| 3 | Primary keys | `radcheck_pkey (id)` and `radreply_pkey (id)` — surrogate `serial` integers carrying no business meaning |
| 4 | Indexes relevant to publication / idempotency | `radcheck_username` and `radreply_username`, both `btree (username, attribute)`, both **non-unique**. They make lookup fast; they constrain nothing |
| 5 | Exact DDL | above |
| 6 | Does the structure permit multiple rows for one `(username, attribute)`? | **Yes, without restriction.** Nothing in the schema prevents it |
| 7 | Other materially relevant constraints | below |

**No probe row was inserted.** The catalogue answers question 6 definitively; a
test insert would have been a write to production and was unnecessary.

#### Question 7 — the rest of what the schema says

* **`NOT NULL DEFAULT ''` on `username`, `attribute` and `value`.** A publisher
  that omits a column gets an empty string, not an error. A bug producing
  `username = ''` writes a row that fails silently rather than loudly.
* **`op` is `varchar(2)`.** The operator vocabulary is capped at two characters;
  `:=`, `==` and `=` fit, and anything longer is rejected at write time.
* **No foreign keys anywhere in either table.** `radcheck.username` is free text
  linked to nothing — not to a voucher, not to a customer, not to `radacct`.
  Orphan credentials are structurally permitted, which is why question 11 cannot
  be answered by the database.
* **The contrast is instructive.** `radacct` *does* carry
  `AcctUniqueId text NOT NULL UNIQUE`. FreeRADIUS applies a uniqueness
  discipline to accounting and deliberately none to credentials, because upstream
  expects multiple check items per user. The absence is the upstream design, not
  a local omission — so "add a unique constraint" is a divergence from stock
  FreeRADIUS, with whatever that implies for upgrades.
* **`nas.secret text NOT NULL` lives in this same database.** The NAS shared
  secrets are one `SELECT` away from anything with broad rights here. Material to
  question 14, and the reason the publisher's privileges in the `radius` database
  are a design question rather than a detail. No secret was read or printed.
* **`radpostauth` carries `pass text`.** If FreeRADIUS's post-auth SQL logging is
  enabled, every authentication attempt writes the username *and the password
  used*. Under docs/33 §416–417's model, where the password equals the username
  equals the voucher code, that table becomes a plaintext store of voucher codes
  — the same hazard §8 settled for the control plane's own attempt records.
  **Whether post-auth logging is enabled has NOT been measured**; it lives in
  `mods-enabled/sql` and `queries.conf`. It is a follow-on read-only check, not
  a finding.

#### What this does and does not settle

It settles question 8 and sharpens 7, 11 and 14. It does **not** resolve
Decision 1 or Decision 7, and nothing here favours Model A or Model B: the
schema is equally permissive under both.

---

### 12.4 Measured — post-authentication logging

**Provenance.** Operator-supplied, 2026-09-21, six read-only commands on the
Phase 0 server, returned as output. Same access limitation as §12.3: this
session cannot reach the host. Nothing was modified, restarted or written.

#### Established

| Fact | Evidence |
|---|---|
| The `sql` module **is enabled** | `mods-enabled/sql -> ../mods-available/sql` |
| It is bound to the credential and post-auth tables | `dialect = "postgresql"`, `postauth_table = "radpostauth"`, `authcheck_table = "radcheck"`, `authreply_table = "radreply"` |
| FreeRADIUS reads its NAS clients **from the database** | `read_clients = yes` — so the `nas` table, and `nas.secret` with it, is live rather than vestigial. This strengthens the Q14 consideration recorded in §12.0 |
| The post-auth query is **active, not commented** | `queries.conf:728`, `post-auth { query = … }`. The optional `logfile =` line above it is commented, so rows go to the database, not a file |
| Enabled virtual servers | `default` and `inner-tunnel`, and only those |
| `inner-tunnel`'s post-auth **actively calls** `-sql` | `sites-enabled/inner-tunnel:337`, uncommented |

The query itself:

```
INSERT INTO ${..postauth_table}
        (username, pass, reply, authdate ${..class.column_name})
VALUES( '%{User-Name}',
        '%{%{User-Password}:-%{Chap-Password}}',
        '%{reply:Packet-Type}',
        '%S.%M' ${..class.reply_xlat})
```

Three properties of it matter:

* `pass` receives `%{User-Password}`, falling back to `%{Chap-Password}` — **the
  password as supplied by the client, interpolated verbatim.** No hash, no
  truncation; the column is `text`.
* `reply` receives `%{reply:Packet-Type}`, so a row records *which* outcome it
  was. The query is outcome-agnostic wherever it is invoked.
* It writes to `radpostauth`, in the same database as `radcheck` and `nas`.

#### Where `sql` is invoked in the `default` virtual server

`default` is the virtual server a MikroTik HotSpot NAS reaches. Its `post-auth`
section opens at line 812 and its `Post-Auth-Type REJECT` subsection at line
1022, which places every invocation below:

| Line | Section | Invocation | Active? |
|---|---|---|---|
| 484 | `authorize` | `-sql` | yes — this is what reads `radcheck` / `radreply` |
| 760 | `accounting` | `-sql` | yes |
| 805 | `session` | `sql` | yes |
| **919** | **`post-auth`** | **`-sql`**, under the comment *"See 'Authentication Logging Queries' in mods-available/sql"* | **yes** |
| **1024** | **`Post-Auth-Type REJECT`** | **`-sql`**, under the comment *"log failed authentications in SQL, too."* | **yes** |

A leading `-` means the module's return code is not treated as a section
failure. **The module still runs.** Both logging invocations are uncommented.

#### The seven questions, answered

| | Question | Answer |
|---|---|---|
| 1 | Is `radpostauth` INSERT enabled? | **Yes.** The module is enabled, the query at `queries.conf:728` is active, and `default`'s `post-auth` invokes `-sql` at line 919 |
| 2 | Under what authentication outcome(s) is it written? | **Both.** Accept via `post-auth` (line 919); reject via `Post-Auth-Type REJECT` (line 1024), whose own comment states the intent. The `reply` column records which, from `%{reply:Packet-Type}` |
| 3 | Is the supplied password written to `radpostauth.pass`? | **Yes** |
| 4 | Verbatim or transformed? | **Verbatim.** `'%{%{User-Password}:-%{Chap-Password}}'` interpolates the value as supplied — no hash, no truncation, into a `text` column |
| 5 | Does the current configuration make a voucher code persist in `radpostauth.pass`? | **Yes, under any design where the voucher code is the RADIUS password.** The logging is live now and does not depend on that design: whatever a client supplies as User-Password is already being recorded on both outcomes. What the design decides is only whether that value is a voucher code |
| 6 | The exact configuration path | accept: `sites-enabled/default` post-auth §812 → `-sql` §919 → `mods-enabled/sql` → `queries.conf:728` → `INSERT INTO radpostauth (username, pass, …)`. reject: `sites-enabled/default` → `Post-Auth-Type REJECT` §1022 → `-sql` §1024 → the same query |
| 7 | Why not, if no | n/a — the answer is yes |

**A correction to §12.4 as first written.** The previous version recorded 1, 2
and 5 as unanswerable because two of my own commands were flawed: a 45-line
window that stopped inside a comment block, and a `grep -r` over a directory of
symlinks, which does not follow them where `-R` does. Re-run correctly, the
evidence was there all along. The earlier empty result was never evidence of
absence, and was not treated as such.

#### What this establishes for Decision 7

Under any design where the voucher code is the RADIUS password — which is
docs/33 §416–417's model, `('t1-TESTCODE01', 'Cleartext-Password', ':=',
't1-TESTCODE01')` — **every authentication attempt writes that code in plaintext
to `radpostauth`, failed attempts included**, in the same database as
`nas.secret`.

Failed attempts are the sharper half. §8 settled that the control plane stores a
prefix and a hash, never the presented code, precisely because *a guest who
mistypes one character can write someone else's live code into a readable
table*. That reasoning is unchanged here — and the control plane's decision has
**no reach over `radpostauth`**, which FreeRADIUS writes according to its own
configuration. A control plane that scrupulously hashes what it stores, beside
an AAA layer that logs the same secret verbatim, has not solved the problem.

This constrains Decision 7 without resolving it. Three branches exist and none
is chosen here:

* **do not make the voucher code the password** — which is the same question
  §6's P1 raises from the portal's side, now with a second reason behind it;
* **change the AAA post-auth configuration** so `pass` is not written — a
  modification to the FreeRADIUS configuration, which is a separate decision of
  the same kind as the schema decision recorded in §12.0, and **is not taken
  here**;
* **accept it** with a retention and access policy for `radpostauth`.

It applies identically under Model A and Model B, so **it does not bear on
Decision 1.**

#### Measured — `radpostauth` already holds a row, and the cleanup missed it

```
 rows |            oldest             |            newest
------+-------------------------------+-------------------------------
    1 | 2026-09-19 12:02:17.895401+00 | 2026-09-19 12:02:17.895401+00
```

Counts and timestamps only. The `pass` column was not read and is not to be.

Three dated facts bracket that row:

| | |
|---|---|
| `mods-enabled/sql -> ../mods-available/sql` | dated **Sep 19 10:27** — post-auth logging was live from then |
| the single `radpostauth` row | written **2026-09-19 12:02:17+00**, about 95 minutes later |
| the Phase 0 cleanup | *"test clients and test rows removed (docs/36 §6.6, executed 2026-09-19)"* — docs/00 §519 |

docs/00 §525 records the post-cleanup state as `radcheck`, `radreply`, `radacct`
and `nas` at 0 rows. **`radpostauth` is not in that list**, and it is the one
table that still has a row.

The timing is consistent with the documented Phase 0 test authentication —
`radtest` and `radclient` are in the image (docs/36 §307), and docs/33 §416–417
gives the test credential. The row's contents were not read, so that remains an
inference from timing rather than a measurement; what *is* measured is that one
authentication on 2026-09-19 produced exactly one post-auth row, and that the
cleanup did not remove it.

**This is not a security incident.** Whatever that row holds in `pass`, the
Phase 0 test credential is already published in plaintext in this repository at
docs/33 §417. The row is evidence, not exposure.

**What it does establish** is the mechanism working end to end, in production,
unprompted: authentication → post-auth `-sql` → `queries.conf:728` →
`radpostauth`, surviving a cleanup that was believed complete. It was missed for
exactly the reason this gate exists — **nobody knew post-auth logging was on**,
so the table was never in scope to clean.

The generalisation belongs in Decision 7: the AAA layer keeps its own records
by its own configuration, and the control plane's retention decisions do not
reach them. Whatever §14 decision 6 settles for the control plane's attempt
store, `radpostauth` needs its own answer — and any future cleanup checklist
for this server needs the table added to it. Neither is decided here, and the
existing row is left in place: removing it would be a write to production and is
not this gate's to make.

---

### 12.5 What must not happen

* Publication must not be decided by writing the first thing that works.
  Questions 12, 13 and 14 have no implementation-obvious answer, and a wrong one
  is discovered only when a revoked voucher keeps working or a paying guest is
  locked out.
* The publisher must not be given broad access to either database because it is
  convenient. §10's principle applies unchanged on both sides of the boundary.
* Questions 8 and 11 must not be answered from the standard FreeRADIUS schema
  as remembered. That is why §12.3 exists: the deployed schema was inspected
  rather than recalled, and it turned out to permit exactly what the simulation
  showed. §12.4 carried that
  through for post-auth logging and stopped where the evidence stopped, rather
  than completing the picture from a remembered stock configuration.

---

## 13. Exact changes required, once the decisions are made

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
| D2 | the answer to decision 7 — the publication and reconciliation mechanism (§12, §14) |

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

## 14. Open decisions

**Decisions 1 and 7 were closed on this evidence — see docs/66. Decision 3 was closed on top of it — see docs/67.** The rows are
struck through rather than deleted so this document still shows what was open
when the evidence was gathered. §12 stays as written: it is the question set
docs/66 answers, and deleting it would remove the reasoning from the record.

| # | Decision | Blocking |
|---|---|---|
| ~~1~~ | ~~Model A or Model B~~ — **CLOSED: Model B.** See docs/66 §1 | — |
| **2** | **Site binding A, B or C** | the portal function's site check; a possible `NOT NULL` |
| ~~3~~ | ~~Portal contract P1, P2 or P3~~ — **CLOSED: P2 with generated credentials.** See docs/67 | — |
| **4** | **Front-desk activation: required or not** | whether an operator capability exists at all |
| **5** | **Rate-limit numbers** — burst, sustained, window, per NAS | §9 fixes the shape and dimensions; the values need a real venue's traffic |
| **6** | **Retention** for the attempt store | M3 |
| ~~7~~ | ~~Where does the control plane publish…~~ — **CLOSED.** Architecture decided in docs/66 §2; §12 below remains the evidence and the question set it was decided against | — |

### Decision 7 is a first-class architecture question

Its shape, its pipeline and the fifteen questions any answer must settle are
**§12**. It is listed here only so the open set is complete in one place.

§8's taxonomy, hashing and attempt separation, §9's dimensions and placement,
and §10's privilege model are **security decisions and are settled**. They are
not on this list.

---

## 15. Regression state and corrections

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
