# 111 — The uCRM bridge architecture

**Design only. No code, no migration, no schema change, no production access, no
fixtures.** Nothing here is authorized to build.

`docs/110` froze the standalone boundary: Domain B operates without uCRM, and
uCRM is not in the real-time path. This designs the **optional** bridge that
carries the commercial relationship, without contaminating Domain B.

---

## 1. Who owns what

| **uCRM owns** | **Domain B owns** |
|---|---|
| the CRM customer | the **network/operator identity** — the RLS boundary |
| contacts | principals (Domain-B logins) |
| commercial services | sites |
| billing · invoices · payments · dunning | routers, provisioning, HotSpot |
| support / tickets | plans, vouchers, AAA, sessions |
| **uCRM staff identity** | network accounting, **network audit** |

**Neither is a projection of the other.** `mt_customers` is not a cache of a
uCRM client (`docs/101`), and a uCRM client is not a view of an operator.

---

## 2. The API surface the bridge would use — measured

From `plugin/plugin.json`, whose declared surface is **asserted equal to the
served surface** by test (`docs/90`):

```
"api": { "surface": "read-only" }

15 GET routes:
  /health  /network-signals  /customers  /customers/{id}  /services  /sites
  /routers  /routers/{id}  /plans  /vouchers  /vouchers/{id}  /voucher-batches
  /sessions  /intents  /audit

7 declared_unbound POST routes — every one answers 501:
  /routers  /routers/{id}/assign  /routers/{id}/actions
  /sites  /plans  /voucher-batches  /sessions/{id}/disconnect
```

### N-1 — the bridge's core operation has no endpoint at all

> **There is no route to create a Domain-B operator, and no route to link one to
> a uCRM client — not even among the seven that answer 501.**

The unbound writes are routers, sites, plans, voucher batches and session
disconnect. **Customer creation, principal creation, service creation and the
commercial link are absent from the surface entirely.** So the bridge cannot be
built against today's API even if everything else were solved: **at least two
endpoints must be designed and added**, and they are the two this document is
about.

This is consistent with `docs/103` — those writers have no production path — and
it means **the bridge depends on the onboarding spine**, not the reverse.

### N-2 — the bridge has no way to authenticate

`DenyAllIdentity` answers **401 on every route**; only
`DN_DEV_STAFF_IDENTITY=yes-development-only` makes the panel render, and it
**throws** rather than degrading when real bindings are allowed. **W-4 is open**,
so today a bridge could not read a single byte through the API.

---

## 3. Rule: APIs only, never the database

> **The bridge must never connect to Domain-B PostgreSQL, hold a Domain-B role
> credential, write a Domain-B table, or call a provisioning function.**
> (`docs/98` R-1, restated as binding.)

Measured reasons this is not merely tidiness:

- **Every Domain-B control lives in PostgreSQL features a uCRM plugin cannot
  have** — RLS, `FORCE ROW LEVEL SECURITY`, `SECURITY DEFINER`, definer-owned
  roles. A plugin has *"SQLite and JSON — there is no PostgreSQL"* (`docs/98`).
- **The 12 roles are cluster-wide.** A plugin sharing the uCRM cluster would put
  them there, and uninstall would drop them cluster-wide (`docs/96`).
- **A credential in the plugin is a credential in the uCRM data directory.** B-1
  closed the equivalent defect by removing credentials from migrations; putting
  one in a plugin re-opens it in a worse place.

---

## 4. The bridge is never a dependency of guest redemption

```
guest code → redemption → AAA publication → RADIUS auth → session → accounting
                  └────── the bridge appears NOWHERE ──────┘
```

Unchanged from `docs/110` §9, and enforced by privilege rather than convention:
**`dnb_portal` and `dnb_radius` hold no table privileges and one EXECUTE each.**
Neither could reach a bridge adapter if one existed. **A uCRM call on this line
is a regression, not a feature.**

---

## 5. The optional relationships — cardinality is OPEN

### 5.1 `Domain-B operator ↔ uCRM customer`

**The current schema already enforces 1:1**: `mt_customers.ucrm_client_id` is
`integer UNIQUE`, nullable. So *"do not assume 1:1"* cuts both ways — **1:1 is
what exists**, and anything else is a **schema change**.

| Evidence for 1:1 | Evidence against assuming it |
|---|---|
| the column is `UNIQUE` today | the working precedent is a **link table** — `lte_service_links`, `UNIQUE` on the **pair**, so many-to-many capable, and its own comment says that was chosen over a column deliberately (`docs/100`) |
| `docs/101` determined 1:1 *for approval* | **C10** is open: whether a reseller holding several venues is one person or several customers |
| cheap to relax, expensive to tighten | a many-to-many shape has consequences for RLS and `/me` that **nothing has examined** |

**Not decided.** Recorded as U-1's companion.

### 5.2 `Domain-B service ↔ uCRM service`

**No column exists.** `mt_services` has no uCRM reference of any kind, so there is
nothing to be 1:1 *with*. And `docs/109` found **no uCRM HotSpot service plan
evidenced anywhere** — so the premise may be empty.

> **U-5 stays deferred.** Designing a cardinality for a relationship whose far
> side is not known to exist would be inventing both ends.

---

## 6. When uCRM is unavailable

| | |
|---|---|
| **Continues** | every Domain-B operation in `docs/110` §4 — operator, site, router, plan, voucher, redemption, AAA, RADIUS, session, accounting; the Admin **read** boundary |
| **Stops** | billing, invoicing, dunning, support; creating or reading links |
| **Stops — by design choice** | **staff authentication into the Admin panel**, under either S-1 or S-2 (§7) |

**Required behaviour of the bridge itself:**

- **Fail closed on identity, fail soft on data.** If `/current-user` cannot be
  reached, the bridge must **refuse to assert a staff identity** — never fall
  back to a cached or assumed one. But a commercial *read* that fails should
  render as **"not available"**, never as a zero or a blank that reads like a
  fact (the `SignalReport` discipline from `docs/90`).
- **No queue of pending commercial writes that later auto-apply.** A link is an
  audited act with an actor; replaying it later with a stale actor would
  misattribute it.
- **No degradation of Domain-B authorization.** A uCRM outage must not widen
  anything.

---

## 7. Staff authentication

```
browser ──cookie──▶ uCRM plugin (server side)
                          │  forwards the session cookie to /current-user
                          ▼
                    uCRM answers: who this staff member is, or 403
                          │
                          │  ← the trust boundary ends HERE
                          ▼
                    bridge asserts identity to Domain B
                          │
                          ▼
                    Domain B maps it to a Domain-B capability set
                          │
                          ▼
                    Domain-B authorization decides
```

**Binding rules:**

1. **The uCRM session cookie is trustworthy only on the server side of the
   plugin**, where `/current-user` answered it. It works **only same-origin**
   (`docs/98`).
2. **Domain B must never accept a staff identity from a browser** (U-7).
3. Two arrangements, **neither chosen**: **S-1** the plugin proxies every call;
   **S-2** the plugin mints a short-lived signed assertion. *(If S-2: the signing
   key is a secret with all of B-1's lessons attached — not in source control,
   not in a migration, provisioned at install, rotatable.)*
4. **The audit actor is `staff`** — `actor_kind` already permits it. **No new
   actor kind.** The actor arrives as a **parameter from the identity boundary**,
   exactly as W-1 requires — never a session GUC, never a request field.
5. **Domain-B authorization remains authoritative.** Being logged into uCRM
   establishes *who*, never *what they may do in Domain B*. **uCRM's permission
   model is not Domain B's**, and must not be read as a grant.

> This closes B-2 **only inside uCRM**. It does not authorize binding any Admin
> write route: **W-4, W-5 and W-6 stay open.**

---

## 8. What the bridge may read and write

### 8.1 Read — through the 15 GET routes, and nothing else

Subject to capability, and only what the projection returns. **Read access
should be narrower than the panel's**, not equal to it: the bridge needs the
commercial view, not the engineering one.

| Needs | Does not need |
|---|---|
| `/customers`, `/customers/{id}` — to show and resolve a link | `/network-signals`, `/intents` — engineering telemetry |
| `/services`, `/sites` — commercial shape | `/routers/{id}` detail — staging fields, `tunnel_ip` |
| `/plans`, `/voucher-batches` — what the operator sells | `/audit` — Domain-B network audit is not CRM data |
| `/vouchers` counts, `/sessions` volume — for a commercial summary | per-voucher detail |

### 8.2 Write — nothing today, and only two things ever

**Today: nothing.** All seven POSTs answer 501, and the two the bridge needs
do not exist (N-1).

When designed, the bridge's write surface is **exactly two operations**:

```
POST  /customers/{id}/ucrm-link      link · relink · unlink
POST  /customers                     create a Domain-B operator   ← only if the
                                      bridge is to onboard from uCRM at all
```

Both are `dnb_adminwrite`, definer functions, W-1 audit in the same transaction,
actor a parameter. **The bridge must not be given the other five POSTs** — router
registration, assignment, actions, sites, plans, voucher batches and session
disconnect are **network operations**, not commercial ones. A CRM plugin has no
business rebooting a router.

> **The second endpoint is itself a decision, not a given.** If operators are
> always created in the Admin panel, the bridge needs only the link.

---

## 9. What the bridge can never access — measured

| Must be unreachable | Where it actually lives | Why the bridge cannot reach it |
|---|---|---|
| **RADIUS credentials** | a **separate PostgreSQL instance** (`radius`) | the Admin API has no connection to it at all |
| **Router secrets** | `mt_device_secrets` — `device_id, username, secret_sealed, rotated_at` | **`dnb_def_admin` holds NO policy on this table** — measured; and `mt_admin_router()` returns no secret column |
| **WireGuard keys** | `mt_devices.wg_pubkey` | **not in `mt_admin_router()`** — even the *public* key is withheld |
| **Voucher codes** | `mt_vouchers.code` | **`code` is absent from `mt_admin_vouchers()` and `mt_admin_voucher()`**, which carry *identical* column lists so a detail view cannot leak one row at a time (`docs/93`) |
| **Guest credentials / sessions** | `mt_auth_sessions.token_hash`, `mt_auth_codes.code_hash` | **no `dnb_def_admin` policy on either**; and both store **hashes, not secrets** |
| **Domain-B PostgreSQL** | — | §3 |

> **Measured, not asserted:** a query for `dnb_def_admin` `USING(true)` policies
> across `mt_device_secrets`, `mt_hotspot_users`, `mt_auth_sessions` and
> `mt_auth_codes` returns **NONE**. The Admin read boundary **structurally cannot
> reach a secret-bearing table** — the projection omitting a column is the second
> layer, not the first.

**Also never:** no uCRM client resolved from a phone number at request time
(`LeadMatcher` *"can identify the WRONG customer and disclose their balance"*);
no guest data of any kind crossing to uCRM (§12).

---

## 10. The commercial-link lifecycle

```
Domain-B operator exists  ──────────────────────────▶ operates regardless
         │
         │ staff decides to represent this operator commercially
         ▼
      LINK   ── audited: actor (staff), ucrm_client_id, linked_at, reason
         │
         ├── RELINK  ── audited: previous AND new relationship + reason
         │              (a customer can be re-papered onto a new uCRM client)
         │
         └── UNLINK  ── audited: previous relationship + reason
                        the Domain-B operator and all its history SURVIVE
```

**Binding properties:**

- **Not a prerequisite for anything in `docs/110` §4.** An unlinked operator is a
  fully functioning operator.
- **Written once, with provenance** — never inferred per request, never from a
  phone number.
- **`dnb_adminwrite` only** — never `dnb_app`, never `dnb_portal`.
- **Coherence, where a service link exists:** the uCRM service's `clientId` must
  equal the client linked to that service's customer. **No FK can express it**;
  the linking function must check it against uCRM. *"The most dangerous operation
  in the bridge"* (`docs/101`) — and note it is the **one** place a Domain-B
  write legitimately depends on a live uCRM read. It is an **administrative**
  operation, not an operating-path one.
- **Nothing is deleted because a uCRM relationship ended** (`docs/101`).

---

## 11. Open decisions — status after this document

| | Status | Why not closed here |
|---|---|---|
| **U-1** — when/under what circumstances an operator is linked | **OPEN** | a commercial decision; Q7's lookup still outstanding (`docs/109` §5) |
| **U-2** — `ucrm_client_id NOT NULL` | **OPEN** | **E-2**; and §5.1 shows it would also decide cardinality by accident |
| **U-5** — the service link | **OPEN, deferred** | the far side is not known to exist (`docs/109` §8.1) |
| **U-7** — S-1 proxy vs S-2 signed assertion | **OPEN** | §7 states the rules both must satisfy; the choice needs the installed uCRM version and whether a client-zone page is supported — both UNVERIFIED |
| **E-2** — the production census | **NOT RUN** | operator; `docs/79` |
| **C10** — is the reseller the PWA user? | **OPEN** | §5.1 shows it is entangled with link cardinality — **decide C10 before cardinality**, not after |

**Newly surfaced, not previously listed:**

| | |
|---|---|
| **N-1** | the bridge's two required endpoints **do not exist**, not even as 501s. **The bridge depends on the onboarding spine.** |
| **N-2** | there is **no way for a bridge to authenticate** to Domain B today (W-4) |
| **N-3** | if **S-2** is chosen, its signing key is a **new secret** and inherits every B-1 lesson |

---

## 12. Preserved invariants

1. **Domain B can operate without uCRM.** Measured, not designed (`docs/110` §2).
2. **Guests never become uCRM customers merely because they used a voucher.**
   Not a client, not a lead, not a contact. A guest has no `mt_customers` row, no
   principal, and **no `guest` actor kind** — and the attempt store gives
   attribution without inventing one (`docs/89`).
3. **uCRM integration is a commercial/staff integration layer, not the HotSpot
   control path.**
4. **No placeholder anything** — no uCRM client to satisfy a Domain-B field, no
   Domain-B customer to satisfy a uCRM one.
5. **The bridge reads Domain B through its API and writes at most two
   operations**, neither of which is a network operation.

---

**Stopping here. Suite unchanged: 1,599 assertions, 27 suites. No code, no
migration, no schema change, no production access, no fixtures. Nothing closed
that the evidence does not close; three new blockers recorded.**
