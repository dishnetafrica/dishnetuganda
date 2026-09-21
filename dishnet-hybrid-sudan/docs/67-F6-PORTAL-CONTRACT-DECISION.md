# 67 — Decision record: Decision 3, the portal contract

**Status: DECIDED. Nothing implemented.** No code, schema, privilege,
FreeRADIUS configuration or deployment changed. `s1_s2_probe.php` untouched.
F1–F13 unamended.

Decides Decision 3 from docs/65 §14, on the architecture settled in docs/66.
Decisions 2, 4, 5 and 6 remain open and are listed in §10. Site binding is
deliberately **not** touched here (§10.1).

---

## 1. The decision

> **P2, in the form docs/66's publisher makes possible.**
>
> The portal submits the voucher code to the control plane and receives, on
> success, a **generated AAA username and password** — neither derived from the
> voucher code nor from anything identifying the customer. The portal then
> submits those credentials to the router. **The voucher code never leaves the
> control-plane boundary.**

P1 (portal returns only `ok`, guest posts the *code* to the router) and P3
(backend completes the login) are rejected in §8.

---

## 2. The constraint that forces most of this

A MikroTik HotSpot guest gets online one way: something POSTs a username and
password to the **router's** login endpoint, the router sends a RADIUS
Access-Request, and FreeRADIUS answers from `radcheck`. **The router is the
authenticator.** The control plane is not, and cannot be.

So there are two destinations, not one:

```
  guest's browser --> control plane   : the voucher code, redemption
  guest's browser --> the router      : username + password, authentication
```

Everything below follows from that split. In particular, *something* must carry
credentials to the router, and the only candidates are the voucher code itself
(P1) or credentials the portal was given (P2).

Before docs/66 there was no publisher, so nothing could generate credentials and
P1 was effectively forced — which is why docs/33 §416–417 shows
`username = password = the code`. The publisher removes that constraint.

---

## 3. The ten items, decided

### 3.1 Does the portal receive the generated AAA username? — **Yes**

It must, to complete the login (§2). Two requirements on it:

* **opaque** — random, from a CSPRNG, not derived from the voucher code, the
  customer, `radius_ref`, the site, or the batch;
* **unique** across the estate, since `radcheck.username` is the lookup key and
  §12.3 established nothing enforces uniqueness there.

This retires the disclosure that made P2 uncomfortable in docs/65 §6: the
username no longer carries `radius_ref`, so a guest learns no stable customer
label. Two guests at two venues cannot tell whether the venues share an owner.

### 3.2 Does it ever receive the generated AAA password? — **Yes**

Same reason: without it the browser cannot complete the login, and the
alternatives are P1 (the code becomes the password) or P3 (rejected, §8).

**The password must differ from the username.** docs/33's example has them
identical; if that were kept, `radpostauth.username` would hold the password
too, and §3.10's benefit would be half undone.

The guest is not learning a secret they did not already effectively hold: they
hold the voucher, the voucher is the instrument, and the credentials are that
voucher's access expressed in the only form the router accepts. What changes is
that the instrument and the credential are now **different values**.

### 3.3 Does the portal authenticate, or only submit redemption? — **Neither alone**

The portal performs **two distinct submissions**, and it authenticates nothing:

1. **redemption** to the control plane — which validates, activates and
   publishes (docs/66 §2.4);
2. **credential delivery** to the router — which authenticates.

The portal has no authority of its own. It drives the router's authenticator;
it does not replace it. **The portal's responsibility ends when the credentials
reach the router.** The authentication outcome is the router's to display, and
the control plane never sees it.

### 3.4 The activation ticket

| Property | Decision |
|---|---|
| Form | opaque random token from a CSPRNG, ≥128 bits, carrying **no** encoded content — not a signed blob containing a voucher id |
| Storage | **hashed** in the control plane, never at rest in the clear — the discipline already applied to auth tokens and to not storing presented codes |
| Binds to | one publication row; and the NAS the redemption came from |
| Lifetime | short — it only has to outlive the publication deadline (docs/66 §2.7). Minutes, not hours |
| Authority | exactly one thing: **read the status of this one activation.** It is not a session token and authorises nothing else |
| Issued | once, in the response to the redemption that created it |

### 3.5 How the portal learns activation succeeded

Two paths, composed:

* **within the bounded wait** (docs/66 §2.4) the redemption response itself
  carries `ready`, the credentials and the expiry;
* **otherwise** it carries `activating` and the ticket, and the portal polls the
  ticket endpoint until `ready` or `failed`, or the ticket expires.

Poll interval and the bounded wait are operational numbers and belong to
decision 5.

### 3.6 During `activating`

```
HTTP 202
{ "state": "activating", "ticket": "<opaque>" }
```

No credentials, no expiry — **there is no expiry yet**: docs/66 §2.8 has the
publisher set `expires_at` on success, precisely so a slow publication does not
consume the guest's time.

The portal shows an honest working state. It does **not** show an error, does
**not** ask the guest to retype the code, and does **not** POST anything to the
router yet.

### 3.7 During `activation_failed`

```
HTTP 200
{ "state": "failed", "reference": "<short quotable id>" }
```

The guest has legitimately spent a valid voucher and has no connectivity. Two
things follow:

* telling them `invalid` would be a **lie**, and would leave reception unable to
  help;
* telling them *why* would leak infrastructure.

So: a short reference id they can quote at the desk, and nothing else. The
reference identifies the publication row for staff; it is not the ticket and
carries no voucher or customer identifier.

**This is the one place the uniform-failure rule of docs/65 §8 is relaxed, and
it is safe.** The oracle risk lives on the *code-keyed* endpoint: an attacker
who supplies codes must not learn which exist. This answer is on the
*ticket-keyed* endpoint, and a ticket is unguessable and single-purpose, so
`failed` discloses nothing about any code. The two endpoints have different
disclosure rules because they take different inputs.

### 3.8 Duplicate submissions

| Case | Behaviour |
|---|---|
| Same code, same NAS, voucher is `activating` | returns `activating`. **Not** treated as "already used" |
| Same code, same NAS, voucher is `active` and not expired | **returns the credentials again** — see below |
| Same code, different NAS | governed by site binding, decision 2. Unchanged here (§10.1) |
| Same code, voucher `revoked`, `expired`, or unknown | `invalid`, uniformly |
| Double-click / retried HTTP request | covered by the first two rows: redemption is idempotent on `(code, NAS)` by voucher state, so a repeat returns the same outcome rather than consuming anything twice |

**On re-returning credentials.** A guest who closes the tab before delivering
the credentials would otherwise hold a spent voucher and no access. Allowing
re-retrieval while the voucher is `active` and unexpired matches what a voucher
*is*: a bearer instrument, where holding the code is holding the access. That is
already the security model; re-retrieval does not widen it.

Two bounds keep it honest: it is rate-limited per code (decision 5), and
concurrency is the **router's** to enforce, not the portal's — simultaneous-use
checking is wired, `sql` is invoked in the `session` section at
`sites-enabled/default:805` (docs/65 §12.4).

It also does not create a new oracle. A first redemption of a valid code already
answers differently from an invalid one; that is unavoidable in any redemption
endpoint. docs/65 §8's rule is narrower and is preserved: **unknown, spent,
revoked and wrong-site are indistinguishable from each other.**

### 3.9 What is deliberately withheld

| Withheld | Why |
|---|---|
| `customer_id` | settled in docs/64; never to an unauthenticated caller |
| `voucher_id`, `batch_id`, `site_id` | internal identifiers; the guest needs none |
| `radius_ref` or any stable customer label | §3.1 — the generated username carries none |
| plan name, price, currency | commercial data the guest has no need for |
| the *reason* a code failed | unknown, spent, revoked and wrong-site all return `invalid` |
| any database, publisher or FreeRADIUS error text | infrastructure disclosure |
| whether a NAS is known | an unknown NAS returns `invalid` like everything else |
| anything encoded in the ticket | §3.4 — opaque, not a container |

The guest receives, on success, exactly: `state`, the two credentials, and
`expires_at`.

### 3.10 How `radpostauth` is handled

**The voucher code never reaches the router**, so it is never a `User-Password`,
so it never reaches `radpostauth`. The code's only destination is the control
plane.

Stated honestly, because this is a reduction and not an elimination:

* `radpostauth` **still stores a secret verbatim** — docs/65 §12.4 measured that
  and nothing here changes the AAA configuration;
* what it stores changes from **the commercial instrument** to **a per-voucher
  generated password** that expires with the voucher and buys nothing else;
* **mistyped codes never reach it at all.** A wrong code is refused by the
  control plane and the portal contacts no router. The docs/65 §8 hazard — *a
  guest who mistypes one character writes someone else's live code into a
  readable table* — **cannot occur**, because the code was never on that path;
* the reject path still logs, but what it logs is a failed generated password,
  not a voucher code.

`radpostauth` remains an independent AAA concern (docs/66 §7): its retention and
cleanup are not governed by anything decided here, and the surviving Phase 0 row
is still not to be read or exposed.

---

## 4. The response contract

Two endpoints, both unauthenticated, joining the three that already exist.

```
POST /portal/redeem          { "code": "...", "nas": "..." }
GET  /portal/activation/{ticket}
```

### 4.1 Every outcome, with its status code

| Outcome | HTTP | Body |
|---|---|---|
| published within the wait | `200` | `{"state":"ready","username":"…","password":"…","expires_at":"…"}` |
| accepted, publication pending | `202` | `{"state":"activating","ticket":"…"}` |
| code unknown / spent / revoked / expired / wrong site / unknown NAS | `200` | `{"state":"invalid"}` |
| rate-limited | `200` | `{"state":"invalid"}` — identical, deliberately |
| ticket poll, still pending | `200` | `{"state":"activating"}` |
| ticket poll, done | `200` | `{"state":"ready","username":"…","password":"…","expires_at":"…"}` |
| ticket poll, publication failed | `200` | `{"state":"failed","reference":"…"}` |
| ticket unknown or expired | `200` | `{"state":"unknown"}` |
| genuine server fault | `500` | `{"error":"internal"}` |

### 4.2 The rules that table encodes

**`activation_failed` is never a `500`.** It is a known state with a defined
response, and collapsing it into a generic error would destroy exactly the
distinction docs/66 §4 created the state for. `500` means the server broke, and
nothing else.

**Business outcomes do not use status codes to signal.** No `404` for an unknown
code, no `403` for a wrong site, no `409` for a spent one. A status-code
difference is an oracle just as a message difference is, and the whole point of
the uniform `invalid` is that nothing distinguishes the failures.

**`202` is honest rather than leaky.** It appears only after a code has already
been accepted, so an attacker who sees it has already succeeded — it tells them
nothing they did not just learn.

**The two endpoints have different disclosure rules on purpose**, per §3.7: the
code-keyed one is uniform because its input is guessable; the ticket-keyed one
may be specific because its input is not.

---

## 5. Where the voucher code goes, and where it does not

```
  code --> portal page --> control plane --> validated, activated
                                |
                                +-- audit: prefix + hash only, never the code
                                +-- publication: generated username/password
                                                      |
  browser <-- username + password <---------------- returned
      |
      +-- POST --> router --> RADIUS --> radcheck        (generated values)
                                     --> radpostauth     (generated password)

  the code reaches: the guest, the portal page, the control plane.
  the code never reaches: the router, FreeRADIUS, radcheck, radacct, radpostauth.
```

---

## 6. What this decision requires of the publisher

Recorded because it constrains docs/66's implementation and was not specified
there:

1. the publisher **mints** the username and the password; neither is derived
   from the voucher code, the customer, or each other;
2. the username is unique across the estate — `radcheck` will not enforce it
   (docs/65 §12.3);
3. both are returned to the control plane on successful publication and held
   only as long as the voucher is usable;
4. `mt_hotspot_users.radius_username` becomes the *generated* username, so
   `radiusUsername()`'s current `radius_ref + '-' + code` derivation is retired;
5. accounting ingestion still resolves the tenant through
   `mt_hotspot_users.radius_username`, which continues to work unchanged —
   the value changes, the lookup does not.

Point 4 is a consequence worth noticing: the generated username breaks the
accidental coupling by which the AAA identity disclosed both the customer and
the code.

---

## 7. Dependencies this creates

* **The walled garden must permit exactly one control-plane origin**, since the
  redemption endpoint is reached before the guest has internet. That is router
  provisioning — step 8 of docs/32 §A8's push order — and it is a dependency of
  the portal, not of this contract.
* **The portal must remain fully self-contained** (docs/45 §6.1): no CDN, no web
  fonts, no analytics. It is served before connectivity exists.
* **Two new unauthenticated routes**, joining `/api/v1/auth/request-code`,
  `/api/v1/auth/verify` and `/internal/radius/accounting`.

---

## 8. Rejected, with the concrete reason

| Rejected | Reason |
|---|---|
| **P1** — return only `ok`; the guest posts the **code** to the router | Makes the voucher code the RADIUS password, so every attempt — **including mistyped ones** — writes a live commercial instrument verbatim into `radpostauth` (docs/65 §12.4). This is the docs/65 §8 hazard, one layer down, where the control plane's decision cannot reach it |
| **P3** — the control plane completes the login against the router | Conflicts with **F2**: the intent queue is the only management path to a router, and F3 reinforces it. Not selected, so **the conflict needs no resolution** — docs/65 §6.1 stands as the record of why |
| Username derived from the customer (`radius_ref`) | Hands an unauthenticated guest a stable customer label. The publisher can mint an opaque one, so there is no reason to |
| Username equal to password (docs/33's example) | `radpostauth.username` would then hold the password too |
| Voucher code as username, generated password | The code would still reach `radcheck.username` and `radpostauth.username` |
| `404` / `403` / `409` for business outcomes | A status-code difference is an oracle exactly as a message difference is |
| `500` for `activation_failed` | Destroys the distinction docs/66 §4 created the state for, and tells a guest with a spent voucher nothing they can act on |
| Ticket as a signed blob containing the voucher id | An opaque random token discloses nothing even if it leaks; a container discloses its contents |
| Refusing re-retrieval after a lost page | Leaves a guest holding a spent voucher and no access, for a risk the bearer model already accepts |
| Status polling keyed by the voucher code | Makes the code-keyed endpoint answer specific questions, which is what §3.7's split avoids |

---

## 9. Amendments required

**None.** F1–F13 unamended, F11 included. docs/45 §2.1 unaffected — docs/66
already resolved that by moving the code to the freeze.

The **P3 / F2 conflict is closed by not selecting P3.** It remains recorded in
docs/65 §6.1 as the reason, and would have to be reopened and amended under
docs/53 §5 if P3 were ever revisited.

---

## 10. Remaining open decisions

| # | Decision | Note |
|---|---|---|
| **2** | Site binding A / B / C | §10.1 |
| **4** | Front-desk activation | unchanged; a business question |
| **5** | Rate-limit numbers | now also fixes: the bounded wait, the publication deadline, the poll interval, the ticket lifetime, and the per-code re-retrieval limit of §3.8 |
| **6** | Retention | the control plane's attempt store, and `radpostauth` separately |

Decisions **1, 3 and 7 are closed.**

### 10.1 Why site binding is untouched here

Site binding decides *where* a voucher is valid. The portal contract decides
*what is exchanged* once validity is established. They meet at one point only —
§3.8's "same code, different NAS" row — and that row defers to decision 2
without constraining it: all three binding options refuse the cross-tenant case,
and the within-estate case is exactly what decision 2 is for.

---

## 11. Not built

Nothing here exists in code. `tests/run.sh`: all suites passed, 718 assertions,
unchanged. No code, schema, privilege, FreeRADIUS configuration or deployment
was touched in this session.
