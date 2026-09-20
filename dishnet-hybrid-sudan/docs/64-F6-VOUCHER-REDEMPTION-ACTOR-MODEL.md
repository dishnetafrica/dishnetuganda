# 64 — F6: the voucher-redemption actor and lifecycle model

**Status: DESIGN AND PROTOTYPE ONLY. F6 is not fixed. No production object changed.**

Gate input: *"Do NOT fix F6 yet… Do not let SQL privilege structure silently
decide the product… Do not silently choose one… Commit the F6 design/prototype
separately from `b78bf73`. Then STOP."*

Everything below is either a measurement against the migrated database, or a
result produced by running the disposable prototype. Nothing is asserted from
reading code alone. Where I could not measure something, the document says so.

---

## 1. Three facts this gate established, and what they cost

docs/63 reported F6 as a privilege finding: `mt_voucher_redeem` is
`SECURITY DEFINER`, returns `customer_id`, and carries `EXECUTE` for `dnb_app`.
Designing the actor model required knowing what redemption actually *does*.
Three measurements changed the shape of the answer.

### 1.1 The RADIUS identity is created at issue, not at redemption

`VoucherService::issue()` inserts the `mt_hotspot_users` row at the moment a
batch is created. Its comment states the reasoning plainly: *"Writing the row
here means a code works the moment it is handed over rather than when a queue
gets to it."*

The table's own SQL comment says the opposite:

> `'A HotSpot user is a network identity, not a DishNet account. Redeeming a`
> `voucher creates one of these and nothing else (docs/45 §2.1).'`

And docs/45 §2.1 — a frozen document — agrees with the comment, not the code:

| | DishNet account | HotSpot user |
|---|---|---|
| Created by | Sales / onboarding | **Redeeming a voucher** |

**The code and the freeze disagree about what redemption creates.** This is not
a style difference. It decides whether redemption is a *provisioning* step or a
*bookkeeping* step, and therefore what is lost when the wrong actor performs it.

### 1.2 Nothing in the system consults voucher state

Measured, not inferred:

| Question | Method | Result |
|---|---|---|
| Any view a RADIUS server would read? | `pg_class` where `relkind='v'`, schema `public` | **no views at all** |
| Who reads `mt_vouchers.state`? | grep of `src/` | **only `VoucherService::revoke()`**, as a guard on its own `UPDATE` |
| Does accounting check it? | read `mt_session_account` body | resolves customer + voucher from `mt_hotspot_users` by `radius_username`; **never touches `mt_vouchers`** |

The prototype reproduces the consequence directly. Case 9c: the RADIUS role
successfully filed accounting for a voucher that had **never been redeemed**.

```
  9  RADIUS accounts for a redeemed code               t
  9c RADIUS accounts for an UNREDEEMED code            t
```

`state`, `activated_at` and `expires_at` are written by redemption and read by
nothing. **Redemption today has no enforcement consequence whatsoever.**

### 1.3 There is no redemption entry point of any kind

Twenty routes are registered. Three are unauthenticated: `/api/v1/auth/request-code`,
`/api/v1/auth/verify`, `/internal/radius/accounting`. None of the twenty
reference redemption. There is no guest surface, no portal surface, and no
customer surface for it.

`mt_voucher_redeem` nevertheless carries:

```
acl = {dnb_def_net=X/dnb_def_net, dnb_app=X/dnb_def_net}
```

`dnb_app` is the customer-authenticated request role. It holds EXECUTE on a
definer function that takes a bare code, crosses every tenant boundary, and
returns `customer_id` — **for a capability no product feature uses.**

### 1.4 What this means for the design

Redemption is presently a marker whose consumer does not exist. Two futures are
open:

* **(i) credential at issue** — redemption starts the paid validity clock. The
  harm of the wrong actor redeeming is *burning someone's clock early*.
* **(ii) credential at redemption** (what docs/45 §2.1 froze) — redemption
  creates network identity. The harm is *provisioning access*.

I deliberately did not resolve this here; it is a product decision and §10
puts it to you. **The actor model below is identical under both**, because in
both the operation is performed by the person holding the code at the router,
and in both the authority must come from the code and the NAS rather than from
anything the caller asserts. That is why the design could proceed without the
answer.

---

## 2. The five questions

### Q1 — Who may redeem?

**A dedicated, unauthenticated portal actor. Nobody else.**

docs/45 §2.1 freezes the guest as someone who is *not* a DishNet customer and
*cannot* sign into the PWA. The actor who performs redemption therefore cannot
be the customer-authenticated request role, because the actor has no customer
authentication to present. docs/50 §4.2 (**F10**) adds that no DishNet
commercial limit is ever experienced by a guest, which keeps the guest outside
the commercial plane entirely.

The prototype gives this role EXECUTE on one function and nothing else:

```
  6  customer role calls the portal fn                 DENIED: permission denied for function pf_portal_redeem
  7  customer role redeems its OWN code                DENIED: permission denied for function pf_portal_redeem
  9b RADIUS tries to redeem                            DENIED: permission denied for function pf_portal_redeem
  10c portal reads the voucher table                   DENIED: permission denied for table pf_vouchers
  10d portal updates a voucher directly                DENIED: permission denied for table pf_vouchers
  10e portal reads the customer table                  DENIED: permission denied for table pf_customers
  10f portal reads the redemption log                  DENIED: permission denied for table pf_redemption_log
```

Note case 7. The customer role is denied **even for its own voucher**. That is
the point: the request role has no redemption privilege at all, so there is no
path by which a compromised customer session reaches the function.

### Q2 — May redemption return a customer identifier?

**No.** The measured return shape of the portal function:

```
  10g does the guest reply carry any uuid?   ok:boolean, reason:text,
                                             radius_username:text,
                                             duration_s:integer,
                                             expires_at:timestamptz
```

No `voucher_id`, no `customer_id`. The current production function returns both
to whoever calls it; that is a property of how it was written, not a decision,
and it should not survive into the portal path.

**One qualification, measured.** The portal does need to hand the guest (or the
router) an AAA username, and `radiusUsername()` builds it from the customer's
`radius_ref`. I initially expected `radius_ref` to be a prefix of the customer
UUID, because migration 010's backfill derives it that way. It is not:

```
migrations/010_vouchers.sql:
  DEFAULT ('c' || substr(replace(gen_random_uuid()::text, '-', ''), 1, 10))
  -- "Derived from a fresh random uuid rather than the row's own id,
  --  because a DEFAULT cannot see the row being inserted."

measured: 0 of 2 refs are derived from the customer uuid
```

So the username discloses a **stable opaque tenant label**, not the UUID. It is
still a disclosure: two guests in two venues could tell whether both venues
belong to one DishNet customer. Whether that is acceptable is a small decision
listed in §10. The alternative is that the portal posts credentials to the
router itself and returns nothing but `ok` and `expires_at`.

### Q3 — Is a voucher bound to a site at redemption?

Three options, measured side by side in §4. **Not chosen here.**

### Q4 — What must redemption record?

See §5. In short: actor kind, actor, NAS, site, voucher, customer, outcome,
time — and **failures as well as successes**, because a code is a bearer
credential and failed attempts are the only visible sign of guessing.

### Q5 — May an authenticated DishNet customer redeem?

**Recommendation: no.** A customer *issues* vouchers and *hands them over*;
that is the operation the four planes already give them. Redemption is what the
recipient does.

If you want a front-desk "activate this for a guest" button, it must be a
**separate function with a separate grant**, never a flag on the portal one.
The prototype demonstrates both why, and how:

```
=== question 5: the operator grant is separable ===
  customer role activates its own voucher              DENIED: permission denied for function pf_operator_activate
  ...after an explicit grant, own voucher              t
  ...and Q's voucher with Q's uuid supplied            t         <-- TENANT CROSSED
  -- the same operation with authority derived, not supplied --
  P's session activates P's own voucher                t
  P's session activates Q's voucher                    f
  no session at all                                    DENIED: no customer authority
  caller names Q's uuid as its session key             DENIED: no customer authority
```

The first form takes the customer as an argument and **crossed the tenant
boundary on the first try** — exactly the hazard in *"Do not allow
caller-supplied customer IDs to become authority."* It is kept in the
prototype as the negative result. The second derives the tenant from a session
credential the caller holds, per docs/61, and denies.

---

## 3. The actor model

| Actor | DB role | Authenticates as | Redemption privilege | Sees `customer_id`? | Records |
|---|---|---|---|---|---|
| **Guest / captive portal** | `dnb_portal` (new, LOGIN) | nothing — unauthenticated | `EXECUTE` on the portal function only | **no** | `guest` + NAS |
| **Customer operator** | `dnb_app` | session credential (docs/61) | **none by default**; a separate grant if §10 decides so | own tenant only | `principal` + operator |
| **RADIUS / AAA** | `dnb_radius` | shared secret, out of band | **none** | only via `mt_session_account` | not a redeemer |
| **Admin support** | `dnb_admin` | admin session + capability (docs/62) | separate function, reason mandatory | yes | `staff` + reason |

Two rules make the table hold:

1. **Authority comes from what the caller presents physically, never from what
   they assert.** The portal function's whole signature is `(code, nas)`. There
   is no customer argument and no site argument to lie about.
2. **Each actor gets its own function, not a flag on a shared one.** The return
   shapes differ (the guest gets no identifiers, the admin gets them), the
   obligations differ (the admin must state a reason), and the grants are
   therefore independently revocable.

### Why not `dnb_radius`

You asked specifically that the privilege not land there by default. It does
not, and the prototype proves the denial (case 9b). The reasoning is the one
you gave: RADIUS is an enforcement/AAA actor. It answers *"may this identity
pass traffic"* — a question in the Enforcement plane. Redemption converts a
sold instrument into a live one, which is a Transaction-plane act (docs/50).
Filing them under one role would let an actor whose secret lives in a router's
configuration file change what has been sold.

Note also the present ownership: `mt_voucher_redeem` is owned by `dnb_def_net`,
the *network* definer role. That filing is consistent with docs/45's claim that
redemption creates the network identity, and inconsistent with the code, where
issue does. If §10 resolves in favour of (i), the function should move to a
transaction-side definer role.

---

## 4. Site binding — A, B and C measured, not chosen

The prototype implements all three behind a switch and runs the same four
presentations under each. Voucher issued for P's Site 1; P also owns Site 2;
Q is a different customer.

```
  mode A  issued@S1 used@S1: true|ok       issued@S1 used@S2: false|invalid
          unbound  used@S1: false|invalid  P's code at Q's NAS: false|invalid
  mode B  issued@S1 used@S1: true|ok       issued@S1 used@S2: false|invalid
          unbound  used@S1: true|ok        P's code at Q's NAS: false|invalid
  mode C  issued@S1 used@S1: true|ok       issued@S1 used@S2: true|ok
          unbound  used@S1: true|ok        P's code at Q's NAS: false|invalid
```

**The cross-tenant case is closed under all three.** P's code at Q's router is
refused in every mode, by a tenant check that sits before the binding logic:
Q's router must never serve P's guest on Q's uplink. That is not one of the
options; it is a floor beneath all of them.

The options therefore differ only *inside one customer's estate* — a hotel
group, a chain of cafés:

| | **A — bound at issue** | **B — bound at first use** | **C — unbound within the customer** |
|---|---|---|---|
| Where the code works | only the site named on the batch | the first site it is used at, then only there | any site of the issuing customer |
| Unbound voucher | **refused** — surfaces the data error | binds on use | works |
| Operational feel | stock is per-site; a code printed for the wrong desk is dead paper | one printing serves every site; the code settles itself | one printing serves every site, forever roaming |
| Fails when | reception moves stock between sites | a guest redeems at the lobby AP then walks to the annexe | a guest hands the code to someone at another branch |
| Per-site revenue attribution | exact, from issue | exact, from first use | **not possible** — no site is ever fixed |
| Best when | sites are separately managed and separately accounted | sites share stock but usage should be attributed | one owner, one pool, convenience over attribution |

`mt_vouchers.site_id` is already `uuid REFERENCES mt_sites(id) ON DELETE SET
NULL` and nullable, and `mt_voucher_batches` carries one too, so all three are
reachable without a schema change. **A is the one that makes the existing
nullable column a defect to be cleaned up; B and C make it load-bearing.**

I have not chosen. This is a product question about how your customers run
their sites, and §10 puts it to you.

---

## 5. The audit record

A redemption record must answer, months later, *"who turned this instrument
live, standing where, and what happened."*

| Field | Why |
|---|---|
| `at` | when |
| `actor_kind` | `guest` \| `principal` \| `staff` \| `system` — which of the four actors |
| `actor` | the portal instance, the operator, or the named admin |
| `nas` | the router the code was presented to — the only physical fact available |
| `site_id` | the site resolved *from* the NAS, never supplied |
| `voucher_id`, `customer_id` | **nullable** — an attempt on a code that does not exist belongs to no tenant |
| `outcome` | `redeemed`, `unknown_code`, `unknown_nas`, `already_active`, `foreign_tenant`, `wrong_site`, `unbound_voucher`, `already_bound_elsewhere`, `race_lost` |
| `detail` | jsonb: binding mode, prior `activated_at`, the admin's stated reason |

Three properties are load-bearing, and each is measured.

**Failures are recorded.** A success-only log cannot show a guessing run. In the
prototype the refusals appear alongside the successes:

```
  guest portal nas=nas-p1 code=AAAAA… cust=1111…  -> redeemed
  guest portal nas=nas-p2 code=AAAAA… cust=1111…  -> redeemed
  guest portal nas=nas-p1 code=AAAAA… cust=1111…  -> redeemed
  guest portal nas=nas-q1 code=AAAAA… cust=1111…  -> foreign_tenant
```

**The guest is told less than the log knows.** `unknown_code` and
`already_active` both return the single string `invalid`. A portal that
distinguishes them is an oracle: it tells a guesser which codes exist, which
converts a 1.1 × 10¹⁵ keyspace search into a much cheaper one. The log keeps
the distinction; the reply does not.

**The code itself is not stored.** My first draft wrote the presented code into
the log. That is wrong, and the prototype was corrected: a code is a live bearer
credential until it expires, and a guest who mistypes one character can write
*someone else's valid code* into a table support staff can read. The record
keeps `code_prefix` (the first block, enough to spot a guessing run) and
`code_hash` (enough to correlate repeat attempts on one code), and not the code.

**One constraint in the existing table.** `mt_audit_log` is close but not
sufficient as it stands:

```
customer_id  uuid   nullable          <- good: an unknown code has no tenant
actor_kind   text   NOT NULL  CHECK (actor_kind IN ('principal','staff','system'))
RLS: enabled + FORCE
```

There is **no `guest` in the actor vocabulary**, and FORCE RLS means a writer
needs a policy or a definer. Extending the CHECK and adding a policy is a
migration, so it is listed in §10 rather than done.

---

## 6. Code visibility — is the code enough on its own?

A voucher code is a bearer credential: whoever holds it can redeem it. That is
correct for a printed slip handed across a reception desk, and it puts the
weight on three properties, all measured.

| Property | Measured | Assessment |
|---|---|---|
| Keyspace | 32 symbols, length 10 → **1.1 × 10¹⁵** | sound; blind guessing is not a threat at this size |
| Source | `random_int()` — CSPRNG | sound |
| Alphabet | A–Z + 2–9, less `O` and `I` | chosen so a code read aloud and typed on a phone survives; it costs ~1 bit per symbol and is worth it |
| Uniqueness | unique index, caller retries on collision | sound — a check-then-insert would race |
| Oracle | *currently none, because no endpoint exists* | the portal endpoint **creates** one; §5's single `invalid` reply is the mitigation |
| Rate limiting | **none anywhere** | needed before any portal endpoint exists; the log's `code_prefix` and `nas` are what makes a run visible |
| In transit | portal is served before the guest has internet (docs/45 §6.1) | the code crosses the guest's own LAN to the router; out of scope here, but it is why the reply must not carry more than it must |

**Conclusion: the code is sufficient authority to redeem, provided the endpoint
is not an oracle and attempts are recorded and rate-limited.** It is *not*
sufficient authority to read anything about the customer, which is why Q2 is no.

One further note. `radiusUsername()` embeds the code in the AAA username:
`radius_ref + '-' + code-without-dashes`. Anyone who can see RADIUS usernames —
accounting logs, a router's active-user list — can read the voucher code back
out, and the code stays valid until it expires. This is not an F6 finding and I
am not fixing it; it is recorded because it bears on §10's decision about what
the portal returns, and it belongs on the list for the F8 telemetry gate.

---

## 7. The privilege boundary

```
  dnb_portal   EXECUTE on the portal redemption function.          Nothing else.
               No table privileges at all. No SELECT on vouchers,
               customers, sites, or the log.
  dnb_app      No redemption privilege. (Today it has EXECUTE on
               mt_voucher_redeem; that grant is the F6 finding and
               must be revoked.)
  dnb_radius   No redemption privilege. Accounting only.
  dnb_admin    EXECUTE on the support function only, which demands
               a stated reason and writes a staff audit row.
  PUBLIC       Nothing. CREATE FUNCTION grants EXECUTE to PUBLIC,
               so every function is revoked from PUBLIC by name
               first — the hazard this codebase has now hit three
               times.
```

The portal role's isolation is not argued, it is measured — cases 10c–10f above,
four separate denials on four separate tables.

**Caller-supplied identity carries no authority.** Case 10a: the portal role set
`app.customer_id` to Q's UUID and redeemed P's voucher anyway, because the
function never reads that GUC — the tenant came from the code and the NAS.
Setting it changed nothing, which is the intended result:

```
  10a portal sets app.customer_id to Q       SET true|ok|p-AAAAA33333
  10b portal sets app.site_id to Q's site    SET false|invalid|-
```

---

## 8. The prototype and its evidence

Disposable, in database `dnb_f6`, objects prefixed `pf_`, roles prefixed
`proto_`. It touches no `mt_*` object and nothing in `dnb_test`.

```
tools/audit/proto_f6.sql        schema, four actor functions, privilege boundary
tools/audit/proto_f6_seed.sql   two customers, three sites, seven vouchers
tools/audit/proto_f6_cases.sh   the ten required cases and the binding matrix
```

It mirrors production where production is load-bearing: the AAA row is written
at **issue** (§1.1), and the RADIUS function resolves the customer from the AAA
identity without consulting voucher state (§1.2).

| # | Case | Result |
|---|---|---|
| 1 | guest redeems P's code at P's site | `true\|ok` — username only |
| 2 | guest redeems Q's code at Q's site | `true\|ok` — the function is tenant-neutral; tenancy comes from the code |
| 3 | guest presents P's code at Q's NAS | `false\|invalid`, logged `foreign_tenant` |
| 4 | unknown code | `false\|invalid`, logged `unknown_code` |
| 5 | already-redeemed code | `false\|invalid`, logged `already_active` — **same reply string as 4** |
| 6 | customer P redeems Q's voucher | DENIED — no EXECUTE |
| 7 | customer P redeems its own voucher | DENIED — no EXECUTE |
| 8 | admin support redemption, reason given | `true` + identifiers + staff audit row |
| 8b | admin support redemption, no reason | refused |
| 9 | RADIUS downstream AAA | accounting succeeds; redemption DENIED (9b) |
| 9c | RADIUS accounts for an **unredeemed** voucher | **succeeds** — reproduces §1.2 |
| 10 | caller supplies arbitrary customer/site ids | ignored; four table denials; no uuid in the reply |

Two additional results worth keeping:

**Concurrency.** Two portals presenting the same code simultaneously:

```
  outcome A                                  false|invalid
  outcome B                                  true|ok|p-AAAAA11111
  rows that say redeemed for that voucher    1
```

The `UPDATE … WHERE state='unused'` plus the `IF NOT FOUND` branch settles it;
the loser is logged `race_lost` and told `invalid` like any other refusal.

**The negative result in §2 Q5** — the operator function that trusted a supplied
customer id crossed the tenant boundary on its first call. Kept deliberately.

---

## 9. What this proves, and what it does not

**PROVEN (measured against the real database):**
- the AAA identity is written at issue, contradicting docs/45 §2.1 and the table's own comment
- nothing reads `mt_vouchers.state`; accounting never touches the table
- no redemption route exists, while `dnb_app` holds EXECUTE on the function
- `radius_ref` is not derived from the customer UUID (0 of 2 rows)
- the voucher keyspace is 1.1 × 10¹⁵ from a CSPRNG, with no rate limiting anywhere
- `mt_audit_log` has no `guest` actor kind and is FORCE RLS

**PROTOTYPED (works in the disposable model, not in production):**
- the four-actor privilege split, with every denial negatively tested
- a portal return shape carrying no identifier
- site binding under A, B and C, with the cross-tenant floor beneath all three
- failure-inclusive audit records with the code hashed rather than stored
- single-winner concurrency on one code
- an operator activation whose authority is derived, not supplied

**UNPROVEN / NOT ATTEMPTED:**
- anything about a real MikroTik or a real FreeRADIUS. There is no NAS in this
  environment; `nas_identifier` is a string I chose. Whether a MikroTik presents
  a stable identifier that maps to a site is an open question for Phase 0 and it
  is a precondition for A and B alike.
- rate limiting. Named as required; not designed here.
- what a captive portal actually posts to a router after a successful redemption.
- the performance of any of this at scale.

---

## 10. Decisions required from you

1. **§1.1 — where is the AAA identity created?** Keep the code's behaviour
   (credential at issue; redemption starts the clock) and amend docs/45 §2.1 and
   the table comment, or keep the freeze and move the insert to redemption. Both
   are defensible; they are not both true today, and the comment currently lies.
2. **§4 — site binding: A, B or C.** The cross-tenant floor holds regardless;
   this decides behaviour within one customer's estate, and C forecloses
   per-site revenue attribution permanently.
3. **§2 Q2 — may the portal return the AAA username** (which carries the opaque
   `radius_ref`), or must it post to the router and return only `ok` + expiry?
4. **§2 Q5 — is there a front-desk activation at all?** If yes, it is a separate
   function and a separate grant, built on derived authority.
5. **§5 — extend `mt_audit_log`** with a `guest` actor kind and a write policy,
   or give redemption its own log table. Either is a migration.
6. **§6 — rate limiting** must exist before any portal endpoint does. Where it
   lives (database, application, or in front of both) is undecided.

---

## 11. Effect on the frozen architecture

| Rule | Effect |
|---|---|
| **F10** — no DishNet commercial limit is ever experienced by a guest | upheld: the guest actor never enters the commercial plane; it holds one EXECUTE and no table access |
| **C12** — suspension and guests holding paid vouchers | untouched here; still open. Note it interacts with decision 1: under (i) a suspended customer's already-issued codes already have AAA identities |
| **docs/45 §2.1** | **contradicted by the code today.** Decision 1 resolves it; this document does not |
| **docs/50 four planes** | preserved: redemption is Transaction, AAA is Enforcement, and §3 keeps the roles apart |
| **docs/41 Domain A/B** | untouched. No Starlink object, endpoint or concept is read |

---

## 12. What was not done

No production code, migration, schema, grant, route or test was changed. F6
remains open exactly as docs/63 recorded it. No fix was applied, including the
obvious one — revoking `dnb_app`'s EXECUTE — because the gate is design-only and
the revocation belongs with the rest of the change.

---

## 13. State of the regression estate

`tests/run.sh`: **all suites passed, 718 assertions.** Unchanged by this gate,
which changed no project code.

One honest exception, found while re-running the probes. `tools/audit/s1_s2_probe.php`
no longer runs:

```
SQLSTATE[42501]: new row violates row-level security policy for table "mt_customers"
  at s1_s2_probe.php:15  ->  $owner->one('INSERT INTO mt_customers ...')
```

This is **not new and not caused by this gate.** The probe was last modified at
`21f29d6`; the owner stopped being able to INSERT customers directly at
`bf5310b`, two checkpoints later, when F2 made the owner `NOBYPASSRLS` and
routed customer creation through `mt_customer_create`. The probe has been
non-runnable since then and I did not notice at the time.

The ground it covered is covered by the committed suite —
`tests/test_isolation_s1_s2.php`, 40 assertions, which seeds through
`seed_two_customers()` and therefore through `mt_customer_create`. The repair is
one line in the probe. I have not made it, because this gate is design-only;
it is listed here so the claim *"all regression probes preserved"* is not made
falsely.
