# 65 — F6: voucher lifecycle decision document

**Status: DECISION DOCUMENT. Nothing implemented. F6 not fixed. No production
object changed. No captive portal, no Admin API, no change to F1–F13, no
contact with Domain A or Starlink.**

Gate input: *"Therefore we must resolve the voucher lifecycle before
implementing the privilege fix… Do not silently reconcile docs/45 and current
code… Commit the decision document separately from `113b970`, then STOP."*

---

## 0. The factual distinction, frozen before anything is reconciled

As instructed, these are recorded as three separate statements. None is
adjusted to fit another.

> **DOCUMENTED / FROZEN** — redemption creates the RADIUS identity.
> docs/45 §2.1: *HotSpot user — Created by: **Redeeming a voucher***.
> Carried into the freeze as **F11**, *"A voucher is a RADIUS user"*
> (docs/53 §2, sourced to docs/32 §A and docs/45 §2.1).

> **IMPLEMENTED** — issue creates the RADIUS identity.
> `VoucherService::issue()` inserts one `mt_hotspot_users` row per voucher at
> batch creation, with the stated reason *"a code works the moment it is handed
> over rather than when a queue gets to it."*

> **CURRENTLY OBSERVED** — voucher redemption state is not consumed by the
> session or accounting path. `mt_session_account` resolves customer and
> voucher from `mt_hotspot_users` by `radius_username` and never reads
> `mt_vouchers`. The only reader of `mt_vouchers.state` in `src/` is
> `revoke()`, guarding its own `UPDATE`. No view exists. No HTTP route
> performs redemption.

**A fourth statement, established in this gate, must sit beside them:**

> **NEITHER MODEL IS WIRED** — `mt_hotspot_users` is not, and cannot be, what
> FreeRADIUS reads. §1.1 measures this.

---

## 1. The AAA lifecycle: issue versus redemption

### 1.1 The question as posed is not the whole question

The gate asks when `mt_hotspot_users` is written. Measuring what would consume
it shows the table is not the credential at all.

| Measurement | Result |
|---|---|
| `mt_hotspot_users` columns | `voucher_id, customer_id, radius_username, created_at` — **no password, no expiry, no reply attributes** |
| What FreeRADIUS actually reads | `radcheck` / `radreply`, per docs/30 §346, docs/33 §135, docs/36 §105 |
| Where those tables live | database `radius`, user `radius`, container `dn-phase0-postgres` (docs/36 §66–67, §155) — **a different PostgreSQL instance** |
| Do they exist in the control-plane database? | `SELECT count(*) … WHERE relname IN ('radcheck','radreply','radacct','nas')` → **0** |
| Does the control plane hold a second DSN? | `Database.php:77` — one DSN, `DNB_DSN`. **No connection to the `radius` database exists anywhere in `src/`** |
| What `voucher.publish` does | `RouterOsDelivery::deliver()` → `assertRadiusBacked()` — checks the **router's** hotspot profile is RADIUS-backed. Writes nothing to any RADIUS store |
| What `voucher.revoke` does | the same `assertRadiusBacked()` |
| Live RADIUS row counts | docs/00 §525: `radcheck`, `radreply`, `radacct`, `nas` — **all 0 rows** |

The delivery handler states the belief that produced this: *"With RADIUS the
credential lives in our database and FreeRADIUS reads it; a router holds no
per-voucher state. So publishing a batch is not a router operation."* The first
half is right and the conclusion does not follow. The credential does live in a
database — **the `radius` one, which nothing writes to.**

**No voucher in this system has ever produced a RADIUS credential.** That is
the root cause of the symptom docs/64 reported: `mt_vouchers.state` has no
consumer because its consumer is the publication step, and the publication step
was never built.

So the real axis is not *which control-plane table gets a row*. It is:

> **At which lifecycle event is the `radcheck` / `radreply` row published, and
> what carries `Expiration`?**

`mt_hotspot_users` is a control-plane *record of intent to publish*. Under both
models it must either gain publication state or be joined by something that
holds it.

### 1.2 The two models, measured

`tools/audit/proto_f6_lifecycle.{sql,sh}` runs the same five events under each
model. Both were run; neither is argued from reading code.

```
  == AAA identity created at ISSUE ==            == at REDEMPTION ==
  5 printed, none sold                           5 printed, none sold
    vouchers=5 aaa_identities=5                    vouchers=5 aaa_identities=0
    RADIUS serves an UNSOLD code:  yes             RADIUS serves an unsold code: NO IDENTITY
  revoke an UNUSED voucher                       revoke an UNUSED voucher
    revoked=1, identity remains                    revoked=1, no identity ever existed
    RADIUS still serves it:        yes             RADIUS serves it:            NO IDENTITY
  redeem one                                     redeem one
    identity already existed                       identity created here
  its expiry an hour in the past                 its expiry an hour in the past
    RADIUS still serves it:        yes             RADIUS still serves it:      yes
  revoke that ACTIVE voucher                     revoke that ACTIVE voucher
    RADIUS still serves it:        yes             RADIUS still serves it:      yes
```

**Model B closes exactly two holes and leaves two open.** Expiry and
post-redemption revocation fail identically under both, because both depend on
a mechanism neither model supplies: `Expiration` in `radcheck`, and deletion of
the `radcheck` row on revoke (docs/33 §428 specifies the first).

### 1.3 The ten aspects the gate asked for

| | **Model A — identity at issue** | **Model B — identity at redemption** |
|---|---|---|
| **Printed but never sold** | a batch of *n* creates *n* live identities. Measured: RADIUS served an unsold code | no identity exists. Measured: `NULL — no identity` |
| **Does the RADIUS username already exist?** | yes, from issue | no — created at redemption, and the portal must obtain it (see §3) |
| **Can accounting exist before redemption?** | **yes — measured.** A session can be accounted for a code nobody bought | no. Accounting cannot resolve an identity that has not been created |
| **When does validity start?** | `activated_at` at redemption — but *access* is available from issue, so the recorded start and the real start differ | both at redemption. The record and the access agree |
| **How does revoke work?** | today a state flip only; the identity survives. Measured: RADIUS served an unused-then-revoked code, **and** an active-then-revoked one | before redemption there is nothing to remove; after redemption it fails exactly as Model A does |
| **How does expiry work?** | **nothing implements it.** Nothing writes `state='expired'`; `expires_at` is read by nothing. docs/32 A13 requires *"access stops with no intervention"* | identical. Model B does not help |
| **How does replay work?** | single use is enforced by `UPDATE … WHERE state='unused'`; proved single-winner under concurrency. But with no consumer of state, a code is reusable at the network layer regardless | same enforcement, and the network layer genuinely stops replay once the identity is the gate |
| **A lost printed voucher** | the code is already live. A finder has access, and revoking does not remove it | the code is inert. A finder can still redeem it, but revoke *before* redemption is effective |
| **How does the portal obtain the AAA identity?** | look it up — it exists | redemption returns or publishes it. This is why §3 is a contract, not a detail |
| **Must `mt_hotspot_users` change?** | **yes** — it holds no password, no expiry, no reply attributes, and is not what FreeRADIUS reads | **yes**, identically. Plus it must be created transactionally with the redemption |

### 1.4 What the evidence supports, and what it does not decide

The evidence supports one structural conclusion: **whichever model is chosen,
publication into the RADIUS store and an `Expiration` mechanism must be built,
and revoke must remove the published credential.** Those three are unavoidable
and are not a product choice.

The evidence does **not** decide between A and B. The honest trade is:

* **A** makes a code work the instant it is handed over, with no queue latency
  between printing and use — the reason the code gives. Its cost is a stock of
  live credentials for paper nobody has bought, and a revoke that must reach
  into the RADIUS store to be real.
* **B** makes the credential's existence mean something: it exists because
  someone redeemed. Its cost is that redemption becomes a provisioning step on
  the guest's critical path, and the portal must survive its failure.

That is a product and operations judgement about how DishNet's customers sell
vouchers, and §10 leaves it open.

---

## 2. Site binding

Measured under the prototype, unchanged from docs/64 §4 and repeated here so
this document stands alone. Voucher issued for P's Site 1; P also owns Site 2;
Q is a different customer.

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
| **Guest experience** | works only where the code was printed for; a guest sent to the wrong desk is stuck | works at the first AP they reach, then only there; a guest who moves loses access | works anywhere the customer operates |
| **Operator experience** | stock is per-site; moving paper between sites is a revocation-and-reissue | one pool of paper serves every site | one pool, no site discipline at all |
| **Revenue attribution** | exact, from issue | exact, from first use | **impossible** — no site is ever fixed |
| **Support implications** | "this code doesn't work here" is answerable from the voucher row | answerable, but the answer changes after first use | few site questions; no site answers either |
| **Security implications** | tightest: a leaked code is useless away from one AP | a leaked code is useful once, anywhere in the estate, then pinned | a leaked code is useful across the whole estate for its lifetime |
| **Portability** | none | one move, implicitly | full |
| **Guest moves between sites** | refused | refused after first use | allowed |
| **Printed for the wrong site** | dead paper until revoked and reissued | self-corrects on first use | never an issue |

**This is a product decision, not a database-security decision.** The security
floor is identical under all three. Undecided — see §10.

---

## 3. Portal response contract

**Security baseline, locked:** the unauthenticated guest never receives
`customer_id`. The prototype's return shape carries no uuid of any kind:

```
  ok:boolean, reason:text, radius_username:text, duration_s:integer, expires_at:timestamptz
```

The open question the gate raises — *does the portal actually need the AAA
username* — turns out to depend on how login completes, and there are three
shapes. docs/33 §416–417 shows the intended `radcheck` row as
`('t1-TESTCODE01', 'Cleartext-Password', ':=', 't1-TESTCODE01')` — **username
and password identical**.

| | **P1 — return nothing but `ok` + `expires_at`** | **P2 — return the AAA username** | **P3 — the backend completes login** |
|---|---|---|---|
| How login completes | the guest (or the portal page) posts the **voucher code itself** to the router's HotSpot login form | the portal posts the returned username/password | the control plane posts to the router |
| Requires | `radcheck.username` **is** the code, dropping the `radius_ref-` prefix | the current prefixed username | a synchronous router interaction |
| Discloses | nothing the guest did not already type | `radius_ref` — an opaque, stable per-customer label | nothing |
| Namespace | codes are already `UNIQUE` across the estate, so a global username space is sound | per-customer namespace retained | either |
| Cost | the AAA username stops identifying the customer, so `mt_session_account`'s lookup must resolve the tenant another way | a guest learns a stable label; two guests at two venues could tell the venues share an owner | **conflicts with F2** — *the intent queue is the only management path to a router*. Flagged, not decided |

**Assessment.** P1 is the smallest disclosure and the simplest guest flow, and
it is what docs/33's own example implies. Its one real cost is that
`radius_username` stops carrying the customer, which today is how
`mt_session_account` resolves the tenant — so accounting would resolve through
`mt_hotspot_users` on the code instead, which it already does by primary key.
P2 is defensible and changes nothing structural. P3 should not be chosen
without an F2 amendment.

**`radius_ref` is to be treated as an opaque identifier, not a customer
identifier.** Measured support for that: the column DEFAULT is
`'c' || substr(replace(gen_random_uuid()::text,'-',''),1,10)` — a *fresh*
random uuid, not the row's own id, and 0 of 2 rows in the migrated database
derive from the customer id. The one-time backfill in migration 010 *did*
derive from the id, so any row predating that migration is an exception and
should be checked before this property is relied on in production.

---

## 4. Front-desk activation

**Not decidable from the codebase.** There is no evidence either way: no route,
no screen, no intent kind, and no document describes a front-desk activation
workflow. It is a business question.

The test that settles it: *does a DishNet customer's staff ever hand a guest
internet access without the guest touching a portal?* A hotel that types the
code into a lobby tablet on a guest's behalf is the same guest flow with a
different pair of hands, and needs nothing new. A hotel that activates a code
at check-in so the guest's phone simply works is a genuinely different
operation and needs its own capability.

**If it is required**, the shape is fixed by the measurement in docs/64 §2 Q5:

```
  operator (session credential, never a supplied uuid)
     -> an explicit voucher id from their own list
     -> a site they are authorized for
     -> activation, audited as 'principal'
```

and it is a **separate function with a separate grant**. The reason is
measured, not stylistic: the first draft of that function took the customer as
an argument and crossed the tenant boundary on its first call. The corrected
form derives the tenant from the caller's session and refuses.

**If it is not required, it is not built,** and `dnb_app` keeps no redemption
privilege of any kind. Under no circumstance does `dnb_app` get generic
redemption authority because front-desk activation *might* exist.

---

## 5. Audit actor taxonomy and the attempt record

### 5.1 The taxonomy

Measured, current: `mt_audit_log.actor_kind` is
`CHECK (actor_kind IN ('principal','staff','system'))`, `customer_id` is
nullable, and the table is RLS **enabled + FORCE**.

Proposed taxonomy, one entry per actor that can cause a recorded act:

| kind | who | tenant |
|---|---|---|
| `principal` | a customer's authenticated user or operator | always known |
| `guest` | an unauthenticated portal caller | **unknown until the code resolves, and never for an unknown code** |
| `staff` | a DishNet admin acting under a named identity | known, and named |
| `worker` | the intent worker acting on a claimed intent | from the intent |
| `radius` | the AAA ingestion path | from the AAA identity |
| `system` | migrations, sweepers, scheduled jobs | often none |

`guest`, `worker` and `radius` are additions. `customer_id` being already
nullable is what makes `guest` representable at all.

### 5.2 Successful redemption records

actor kind and actor; the NAS presented to; the site **resolved from** that NAS;
voucher and customer; outcome `redeemed`; time; and in `detail` the binding mode
and resulting `expires_at`.

### 5.3 A failed attempt records the same shape minus what did not resolve

`voucher_id` and `customer_id` stay NULL for an unknown code — the attempt
belongs to no tenant. Outcome is one of `unknown_code`, `unknown_nas`,
`already_active`, `foreign_tenant`, `wrong_site`, `unbound_voucher`,
`already_bound_elsewhere`, `race_lost`. The guest is told `invalid` for every
one of them; the record keeps the distinction.

### 5.4 The presented code is never stored

Accepted from the prototype, and the prototype was corrected to match: the
record keeps `code_prefix` (the first block — enough to see a guessing run) and
`code_hash` (enough to correlate repeat attempts on one code), never the code.
A code is a live bearer credential until it expires, and a guest who mistypes
one character would otherwise write *someone else's valid code* into a table
support staff can read and search.

### 5.5 Failed guest attempts are security events, not business audit

**Recommended: a separate store.** Three reasons, each concrete:

1. **Volume.** A guessing run writes one row per attempt. `mt_audit_log` is a
   tenant-facing business record; filling it with attempts on codes that
   resolve to no tenant degrades it as a business record and as a security
   record at once.
2. **Tenancy.** `mt_audit_log` is FORCE RLS and organised by customer. An
   unknown-code attempt has no customer, so it is visible to nobody under the
   existing policies — the rows would exist and be unreadable by the people who
   need them.
3. **Retention.** Business audit is kept for as long as the commercial record
   requires. Attempt telemetry wants a short window and aggressive pruning.

So: successful redemptions (and admin support redemptions) go to
`mt_audit_log` with the extended taxonomy; **failed attempts go to a dedicated
attempt table** that is not tenant-scoped, is readable by DishNet operations,
and is pruned. This also gives rate limiting (§6) its natural home.

---

## 6. Rate-limit architecture

### 6.1 Why the keyspace is not the answer

The keyspace is sound — 32 symbols, length 10, CSPRNG, 1.1 × 10¹⁵ — and blind
guessing is not the realistic attack. The gate names the realistic one: **an
attacker obtains or observes a legitimate code.** Rate limiting is therefore
not primarily anti-guessing. It is there to bound three different things:

* replay and sharing of an observed code across many devices and sites;
* an enumeration run that would otherwise be free and invisible;
* denial of service against an unauthenticated endpoint that does database
  writes on every call.

### 6.2 The precedent already in this codebase

`mt_auth_verify_code` (migration 007) is the house pattern for a bearer
credential, and it is worth following where it fits:

```sql
UPDATE mt_auth_codes SET attempts = attempts + 1 WHERE id = r.id;
IF r.attempts >= 4 THEN RETURN; END IF;
-- "Returns zero rows on any failure — wrong code, expired, consumed,
--  too many attempts, or a phone that was never registered. The caller
--  cannot distinguish these, which is the point."
```

Three properties transfer directly: the counter increments **before** the
check so a wrong guess always costs; the failure shape is uniform; and the
state lives in the same transaction as the thing it protects.

One property does **not** transfer. An OTP is bound to the phone that requested
it, so a per-credential counter is a per-attacker counter. A voucher code is
bound to nobody — the attacker chooses which code to try. **A per-code counter
does not slow an enumeration run at all**, because every attempt is against a
different code. That gap is why per-source dimensions are mandatory rather than
optional.

### 6.3 Dimensions

| Dimension | Bounds | Notes |
|---|---|---|
| **code / code-hash** | hammering one code | the OTP pattern; cheap, exact; useless against enumeration |
| **NAS / site** | a run mounted from inside one venue | the most trustworthy dimension available — the NAS identity is asserted by infrastructure DishNet provisioned, not by the guest |
| **source IP** | the ordinary volumetric case | weak alone: behind the customer's NAT every guest at a site shares one address, so a low limit locks out a whole venue |
| **device / MAC** | one phone retrying | guest-supplied; trivially spoofed; usable as a signal, never as the only gate |
| **global portal** | total blast radius | a last-resort breaker |

Recommended combination: **per-code** (tight), **per-NAS** (the real limit),
and **global** (the breaker). Per-IP only as a coarse volumetric guard applied
in front of the application, never as the primary control. Per-device as
telemetry.

### 6.4 Shape

* **Burst** — a handful of attempts in quick succession is a human mistyping a
  code read off paper. Refusing the third attempt makes the product unusable at
  a reception desk.
* **Sustained** — the limit that matters, measured per NAS over minutes.
* **Lockout** — a decaying window rather than a hard lock. A hard per-NAS lock
  is a denial-of-service primitive against a paying customer's whole venue: an
  attacker who can reach one AP can lock out every guest in the building.
* **Response uniformity** — non-negotiable, and already settled in §5: a
  rate-limited attempt returns exactly the same `invalid` as a wrong code.
  A distinguishable "too many attempts" reply tells an attacker their probe
  worked and lets them calibrate.

### 6.5 Where the state lives, and what it is

Measured: the PHP **Redis extension is present** (`php -m` → `redis`,
`class_exists('Redis')` → true), so Redis is technically available. The control
plane currently holds exactly one DSN and no Redis client anywhere in `src/`.

**Recommendation: both, for different jobs.**

* **PostgreSQL** for the per-code and per-NAS counters. They must be
  transactional with the redemption attempt and with the attempt record from
  §5.5 — a counter that can disagree with the audit trail is worse than no
  counter. They must survive a restart. And they need no new dependency: the
  attempt table already has to exist.
* **Redis or an edge layer** for volumetric per-IP shedding, which is
  expendable by nature, must not touch disk on every packet, and exists to stop
  load before it reaches PHP.

**Is rate limiting security infrastructure or transaction logic?** Both, split
along that line. The per-code and per-NAS limits are **transaction logic** and
belong inside the redemption function, where they cannot be bypassed by a
second caller. Volumetric shedding is **infrastructure** and belongs in front
of the application. Putting the transactional limits in the edge layer would
make them bypassable; putting the volumetric ones in the database would make an
attack a write amplification.

---

## 7. The resulting F6 privilege model

Locked from the gate, and every line below has a measured denial behind it in
`tools/audit/proto_f6_cases.sh`.

```
dnb_portal   (new, LOGIN)   unauthenticated guest / captive portal
  EXECUTE    exactly one narrowly scoped redemption function
  no table privileges at all
  cannot set customer context   -- measured: setting app.customer_id changed nothing
  cannot choose customer_id     -- the signature is (code, nas); there is no such argument
  cannot choose a site          -- the site is resolved from the NAS, never supplied
  cannot call voucher management functions

dnb_app      customer-authenticated request role
  NO redemption privilege      -- measured: denied even for its own voucher
  the current EXECUTE on mt_voucher_redeem is revoked; that revocation IS F6
  a separate operator activation only if §4 resolves that it is required

dnb_radius   AAA ingestion
  NO redemption authority      -- measured: denied
  accounting only

dnb_admin    DishNet support
  NO ordinary guest-redemption privilege
  a separate support/recovery function, reason mandatory, staff-audited

PUBLIC       nothing. Every function REVOKEd from PUBLIC by name first --
             the hazard this codebase has hit three times.
```

This keeps redemption separate from AAA and from customer administration, which
is the property the four planes (F6 in docs/53, docs/50 §2) require.

---

## 8. Amendment required to the frozen architecture

The contradiction is **not** resolved by editing docs/45 to match the code. Per
docs/53 §5: *"An item here is unfrozen only by an explicit amendment to this
document stating which item, why, and what it is replaced by. Superseding it
silently in a later document does not count."*

A useful distinction, found while checking the sources:

* **F11's headline** — *"A voucher is a RADIUS user. A HotSpot user is not a
  DishNet account"* — is true under **both** models. It says what a voucher
  *is*, not when the row appears.
* **docs/45 §2.1's table cell** — *HotSpot user, Created by: **Redeeming a
  voucher*** — is the statement that Model A contradicts.
* **docs/32 §A**, F11's other source, says nothing about creation time. Its
  A10–A14 constrain something else entirely, and A13 — *"Wait for expiry:
  access stops **with no intervention**"* — is a requirement **neither model
  currently meets**.

So the scope of any amendment is narrow:

**If Model B (identity at redemption) is chosen** — no amendment is required.
docs/45 §2.1 stands as written, F11 stands, and the *code* changes to match the
freeze. The table comment on `mt_hotspot_users` becomes true.

**If Model A (identity at issue) is chosen** — an amendment to docs/53 is
required, naming **F11**, stating that docs/45 §2.1's creation-time cell is
replaced by *"created at issue; activated at redemption"*, and giving the
reason (a code must work the moment it is handed over). docs/45 §2.1 is then
edited with a dated note pointing at the amendment, and the `mt_hotspot_users`
table comment is corrected. F11's headline survives unchanged.

**In either case** a second, separate statement is needed, because it is true
under both: *publication into the RADIUS store is a required lifecycle step,
and expiry is carried by `Expiration` in `radcheck`.* That is new architecture
rather than an amendment, and it belongs in its own document.

---

## 9. Exact changes required, once the decisions are made

Nothing here is written yet. The list is scoped so the size of each decision is
visible.

**Unconditional — required under every combination:**

| # | Change |
|---|---|
| M1 | migration 019: revoke `EXECUTE ON mt_voucher_redeem` from `dnb_app`; create role `dnb_portal`; create the portal redemption function; `REVOKE … FROM PUBLIC` by name; grant to `dnb_portal` only |
| M2 | migration 020: extend `mt_audit_log.actor_kind` to the §5.1 taxonomy and add the write policy the new actors need under FORCE RLS |
| M3 | migration 021: the attempt table of §5.5, not tenant-scoped, with its retention/pruning, carrying the per-code and per-NAS counters of §6.5 |
| A1 | `Database::portal()` — a sixth identity, alongside the existing five |
| A2 | `VoucherService::redeem()` retargeted at the portal function and its new return shape; the old signature removed |
| A3 | tests: the ten cases of docs/64 §8 as a committed suite, plus the lifecycle comparison and the concurrency case |
| D1 | a document specifying RADIUS publication and `Expiration`, per §8's closing paragraph |

**Conditional on Decision 1:**

| | Model A | Model B |
|---|---|---|
| amendment | docs/53 amendment naming F11; docs/45 §2.1 dated note; table comment corrected | **none** — instead `VoucherService::issue()` stops writing `mt_hotspot_users`, and the portal function writes it |
| revoke | must remove the published credential — otherwise measured behaviour stands: a revoked voucher is still served | the same, for vouchers revoked after redemption |

**Conditional on Decision 2:** the site check inside the portal function, and —
under A only — a `NOT NULL` on `mt_vouchers.site_id`, since an unbound voucher
becomes a data error rather than a state.

**Conditional on Decision 3:** under P1, `radcheck.username` becomes the code
and `mt_session_account`'s tenant resolution moves to the voucher; under P2,
nothing structural changes.

**Conditional on Decision 4:** the operator function, its grant, and its tests —
or nothing at all.

---

## 10. Unresolved product decisions

| # | Decision | Blocking |
|---|---|---|
| **1** | **Model A or Model B** — when the AAA identity is created | M1's function body, D1, and whether §8's amendment is needed |
| **2** | **Site binding A, B or C** | the portal function's site check; a possible `NOT NULL` |
| **3** | **Portal response P1, P2 or P3** | the return shape; under P3, an F2 amendment |
| **4** | **Front-desk activation: required or not** | whether an operator capability exists at all |
| **5** | **Rate-limit numbers** — burst, sustained, window per NAS | §6 gives the shape and dimensions; the values are operational and need a real venue's traffic |
| **6** | **Retention** for the attempt store | M3 |

§5's taxonomy, §5.4's hashing, §5.5's separation, §6's dimensions and placement,
and §7's privilege model are **security decisions and are settled**; they are
not on this list. Decisions 1–4 are product decisions and are deliberately left
to you, per the gate.

---

## 11. Regression state

`tests/run.sh`: **all suites passed, 718 assertions**, unchanged — this gate
changed no project code.

**A correction from this gate's own work.** The first run of the lifecycle
prototype reported *"one voucher revoked"* while the counts in the same line
read `revoked=0`. The prototype's `pf_vouchers` had no `revoked_at` column, so
the revocation errored into a discarded stream and the "RADIUS still serves the
revoked code" result proved nothing. The column was added, the revoke result is
now printed rather than swallowed, and §1.2's table is from the corrected run.
The finding survived the correction — a revoked voucher *is* still served under
both models — but it was not evidenced until the second run.

**`tools/audit/s1_s2_probe.php` remains non-runnable** and is deliberately not
repaired here. Recording the requirements the eventual repair must satisfy, per
the gate:

1. the production security model stays non-superuser and `NOBYPASSRLS` — the
   probe adapts to it, never the reverse;
2. fixture setup uses the trusted seed path (`mt_customer_create` and tenant
   contexts), not a privileged INSERT;
3. observation uses the explicit `Database::inspector()` identity, which exists
   for exactly this and is asserted to be absent from `src/` and `bin/`;
4. the adversarial semantics are unchanged — the probe must still be capable of
   failing the way it originally could.

It belongs in a test-maintenance change of its own.
