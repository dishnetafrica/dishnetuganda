# 66 — Decision record: Decision 1 and Decision 7

**Status: DECISIONS MADE. Nothing implemented.** No code, schema, privilege,
FreeRADIUS configuration or deployment changed. `s1_s2_probe.php` untouched.
F1–F13 unamended. This record decides; docs/65 holds the evidence it decides on.

Decisions 2, 3, 4, 5 and 6 remain open and are listed in §9.

---

## 1. Decision 1 — **Model B**

> **The AAA credential is published at redemption/activation, not at voucher
> issue.**

### 1.1 The measured lifecycle, both models, all three layers

From docs/65 §3 and §12.3–§12.4. "What FreeRADIUS answers" is against the
documented lookup; "what the guest experiences" follows from it.

| # | Event | | **Model A — published at issue** | **Model B — published at redemption** |
|---|---|---|---|---|
| 1 | **printed, unsold** | `mt_vouchers` | `unused` | `unused` |
| | | registry | present | absent |
| | | `radcheck` | **`Cleartext-Password`** | no rows |
| | | FreeRADIUS | **Access-Accept** | Access-Reject (no such user) |
| | | guest | **a code nobody bought works** | nothing works |
| 2 | **unused voucher** | | as above — there is no state between printing and use | as above |
| 3 | **unused, revoked** | `mt_vouchers` | `revoked` | `revoked` |
| | | `radcheck` | rows must be removed, or they remain | nothing was ever written |
| | | FreeRADIUS | **Access-Accept until unpublished** (measured) | Access-Reject |
| | | guest | revocation is not real until the AAA store is reached | revocation is complete on the control plane alone |
| 4 | **unused, expired** | `mt_vouchers` | no shelf-life concept exists — `expires_at` is only set at redemption | same gap, but harmless: nothing is published |
| | | FreeRADIUS | **Access-Accept indefinitely** | Access-Reject |
| 5 | **redeemed / activated** | `mt_vouchers` | `active` | `activating` → `active` (§4) |
| | | registry | already present | written here |
| | | `radcheck` | **amended** to add `Expiration` — a *second* publication | written once, complete |
| | | FreeRADIUS | Access-Accept | Access-Accept once published |
| 6 | **active, revoked** | `radcheck` | must be removed | must be removed — identical |
| | | FreeRADIUS | **Access-Accept until removed** (measured) | identical |
| 7 | **active, expired** | `radcheck` | `Expiration` rejects — if it was published | identical |
| 8 | **established session after revoke/expiry** | | removal stops the next authentication, not a running session — identical under both (§4.4) | identical |
| 9 | **retry / duplicate publication** | | **two publication events per voucher**, one of them an amend — and the amend is exactly where duplication was measured: 4 check items, still Access-Accept, silently | **one publication event per voucher** |
| 10 | **publication failure** | | invisible: the credential already existed, so nothing about the guest's experience depends on it | **the exposed case** — redemption can succeed while publication fails, and the guest has consumed a valid voucher with no connectivity |

### 1.2 Why B, stated as consequences rather than labels

**1. Model A makes a printed voucher a live bearer credential before it is
sold.** Measured: row 1, `Access-Accept` for a code nobody bought. A batch of
500 printed codes is 500 working internet credentials in a drawer, and anyone
who photographs the print run has all of them. That is a commercial lifecycle
property, not an implementation detail: DishNet's customers would be printing
live instruments. Rows 2 and 4 make it worse — there is no shelf-life concept,
so an unsold code stays accepted indefinitely.

**2. The two failure modes are not comparable in kind.**

* Model A's failure — an unsold or revoked code that still authenticates — is
  **silent, systemic and unbounded in time**. Nothing in the control plane
  records it: accounting resolves through the registry and never reads
  `mt_vouchers.state`, so a session on an unsold voucher looks like any other.
* Model B's failure — publication fails after a valid redemption — is **loud,
  individual and recoverable**. One guest, one known NAS, one known moment, with
  a row that records it. §4 gives it an explicit state and a recovery path.

A failure you can see and fix is not the same size of problem as one you
cannot see at all.

**3. Model A doubles the publication events and adds the one that breaks.**
A must publish at issue and then *amend* at redemption to attach `Expiration`.
§12.3 established there is no unique constraint on `(username, attribute)` in
either table, and docs/65 §3.2 measured what the amend does when written
naively: duplicate `Cleartext-Password` **and** `Expiration` rows, with
authentication still succeeding, so nothing surfaces the fault. B publishes once,
complete, with `Expiration` included.

**4. Model A's stated advantage dissolves under §2.4.** The code's comment gives
the reason for publishing at issue: *"a code works the moment it is handed over
rather than when a queue gets to it."* That is only an advantage if publication
is asynchronous and slow. §2.4 makes publication synchronous from the guest's
point of view, so under B a code works the moment it is redeemed — which is the
moment it needs to.

**5. B matches the freeze.** docs/45 §2.1 states the HotSpot user is created by
redeeming a voucher. B makes the code match the frozen document; A would require
amending it. This is a tiebreaker, not the reason — reasons 1–3 stand on
measurement alone.

### 1.3 What B costs, stated plainly

Redemption becomes a provisioning step on a guest's critical path. §4 is the
design for that, and it is the price of B. It is accepted because the cost is
visible and bounded, where A's is neither.

---

## 2. Decision 7 — the publication architecture

### 2.1 Authoritative lifecycle store

**`mt_vouchers` is authoritative for the commercial lifecycle.
`radcheck`/`radreply` is a projection of it, never a source.**

The AAA store answers one question — may this identity pass traffic — and it
answers it from rows the control plane put there. Nothing reads AAA state back
into a commercial decision. The existing accounting ingestion is unaffected: it
already flows RADIUS → control plane over HTTP and is a separate actor.

### 2.2 Publication authority

**A dedicated AAA Publisher: a control-plane worker acting on claimed
publication rows.** Not the request path, not the portal, not FreeRADIUS, not an
admin screen.

The reason is the credential. The publisher is the only thing that holds an
identity in the `radius` database, and the portal request role must never hold
one. Making the publisher a separate process is what keeps that true.

### 2.3 Least-privilege credentials — two identities, neither reused

| Where | Role | Holds |
|---|---|---|
| control plane | `dnb_publisher` (new, LOGIN) | EXECUTE on the claim / complete / fail functions. **No table privileges.** Modelled on `dnb_worker` |
| `radius` database | `dnb_pub` (new, LOGIN) | EXECUTE on **two SECURITY DEFINER functions**, `publish(...)` and `unpublish(...)`, owned by the `radius` role. **No table privileges at all** |

`dnb_pub` therefore cannot read or write `nas` (which holds `nas.secret`),
`radacct`, `radpostauth`, `radgroupcheck`, `radgroupreply`, `radusergroup` or
`nasreload` — not by policy but because it has no privilege on any table. It
cannot `SELECT` the credentials it writes.

**`dnb_radius` is not reused**, per docs/65 §12.0: its trust direction is
RADIUS → control plane, publication is control plane → RADIUS, and the two are
different grants.

**On the stock schema.** The two functions and the role are *additive* to the
`radius` database. No FreeRADIUS table is altered, no column added, no
constraint created — docs/65 §12.0's prohibition holds. Adding functions and a
role is nonetheless a change to that database, to be executed as its own
reviewed step, not folded into a control-plane migration.

### 2.4 Synchronous or asynchronous

**Synchronous from the guest's point of view; asynchronous in mechanism.**

```
  portal request  --> mt_portal_redeem(code, nas)      [control plane, one txn]
                        validates, sets 'activating',
                        writes the registry row,
                        queues a publication,
                        returns an opaque TICKET
                  --> the endpoint then waits, bounded, polling the
                      publication row server-side
  publisher       --> claims the publication, calls publish() in the
                      radius database, marks it done
  portal returns  --> ok + expiry, or 'activating' + the ticket
```

This is what lets the RADIUS credential stay out of the request role while the
guest still gets one answer to one request. The wait is short and bounded; the
exact value is operational and belongs with decision 5's numbers.

**The existing intent queue's timings are explicitly not inherited.**
`max_attempts DEFAULT 5` and `deadline_at DEFAULT now() + interval '7 days'`
were designed for router provisioning. A publication deadline is minutes, not
days, because under B the voucher's validity window is tied to publication
(§2.8) and a guest is waiting.

**Status is polled by ticket, never by code.** An opaque ticket returned to that
one browser. Re-presenting a *code* to ask "is it ready yet" would make the
endpoint an oracle that distinguishes a real code from an unknown one, which is
exactly what docs/65 §8 forbids.

### 2.5 Idempotency mechanism

**The idempotency key is the voucher id.** One voucher yields at most one AAA
credential, identified by one `radius_username`. The publication row carries the
voucher id under a unique constraint, so a retry claims the same row rather than
creating a second.

### 2.6 Duplicate prevention

**Convergence by construction, inside the `radius`-side definer function:**

```
publish(username, password, expiration, reply_items):
    DELETE FROM radcheck WHERE username = $1;
    DELETE FROM radreply WHERE username = $1;
    INSERT INTO radcheck ...;   -- Cleartext-Password, Expiration
    INSERT INTO radreply ...;   -- reply items
  -- one transaction
```

Delete-then-insert converges; a second call produces the same two rows, not
four. Because `dnb_pub` holds no table privileges, **no caller can bypass this**
and insert directly. That is the whole reason the boundary is a function rather
than a grant: §12.3 established the schema will not prevent duplication, so the
prevention has to live somewhere that cannot be gone around.

### 2.7 Failure and retry semantics

Three outcomes, distinguished because they need different handling:

| Outcome | Examples | Handling |
|---|---|---|
| **transient** | connection refused, timeout, deadlock, `radius` database unavailable | retry with backoff until the publication deadline |
| **permanent** | a constraint or privilege error, an unknown failure | no retry; the publication is marked failed |
| **guest-wait timeout** | publication still pending when the bounded wait expires | **not a failure.** The redemption stands, the publication keeps retrying, the voucher stays `activating`, and the guest holds a ticket |

**If the `radius` database is unavailable**, publication is transient by
definition and retries. What must not happen is the redemption silently
succeeding into `active`: §4's `activating` state exists so that "redeemed but
not yet usable" is a state the system can name.

### 2.8 Expiry propagation

`expires_at` is set **by the publisher on successful publication**, not by the
redemption, and the `Expiration` check item is written in the same call from the
same value. Two consequences, both deliberate:

* the guest's clock starts when their access starts, not when their request
  arrived — a publication that takes thirty seconds does not cost thirty seconds
  of a one-hour voucher;
* there is no second amend, so Model A's measured duplication hazard has no
  analogue here.

A sweep transitions `active` → `expired` in the control plane for reporting and
unpublishes the credential. FreeRADIUS already refuses on `Expiration`, so the
unpublish is hygiene and reconciliation, not enforcement.

### 2.9 Revoke propagation

Revocation enqueues an unpublish and moves the voucher `active` → `revoking` →
`revoked` on confirmation. Revoking an `unused` voucher completes immediately
with nothing to unpublish — a real simplification B buys, measured as row 3.

**Revocation is not complete when `mt_vouchers.state` changes.** docs/65 §4.2
measured a revoked voucher still being served. The state flip is the request;
the unpublish is the act.

### 2.10 Active-session handling

Removing a credential stops the **next** authentication. It does not tear down a
session already established — derived from how RADIUS works, not measured here.

So revoking or expiring an **active** voucher is two actions, ordered:

1. **unpublish**, so a reconnect cannot succeed;
2. **`session.disconnect`** for any open session on that voucher — an intent
   kind that already exists in `RouterOsDelivery` and is today connected to
   nothing.

Ordered that way because the reverse leaves a window in which a disconnected
guest simply reconnects.

### 2.11 Drift detection

A reconciler compares what the control plane says should exist against what the
AAA store holds, and classifies:

| Class | Meaning | Severity |
|---|---|---|
| `active` voucher, **no** credential | the guest cannot connect | support |
| non-`active` voucher, credential **present** | access that should not exist | **security and revenue** |
| credential with **no voucher at all** | orphan; access with no commercial basis | **security and revenue** |
| **duplicate** check items for one username | undefined authentication behaviour | correctness |

The fourth class exists because §12.3 established nothing prevents it.

### 2.12 Reconciliation — asymmetric, and deliberately so

**The reconciler may remove access automatically. It may never grant access.**

* *Removing* a credential no live voucher justifies can only ever deny access
  that should not exist. Automated — with a per-run cap and an alert above a
  threshold, because a bug here denies paying guests.
* *Creating* a credential for a voucher that claims to be active is an
  authorization decision, and a bug would grant access. **Never automated.** It
  is reported, and the fix goes back through the publication state machine,
  which has the audit trail and the failure handling.

One direction can only subtract, the other can only add. They do not deserve the
same trust.

### 2.13 Source of truth on disagreement

**The control plane wins** — but convergence is asymmetric per §2.12. The AAA
store is converged *downward* automatically and *upward* only through
publication. "Control plane wins" is a statement about authority, not a licence
for a sweep to mint credentials.

### 2.14 Cross-database trust boundary

* **One direction, write-only, through two functions.** Control plane → RADIUS.
* **No FDW, no `dblink`, no two-phase commit.** Prohibited: a foreign-data
  wrapper would make the control-plane database itself a path into the RADIUS
  database, and neither RLS nor the definer boundaries would cover it.
* **Therefore publication is not atomic with redemption.** Two databases, two
  connections, no shared transaction. §4's `activating` state is the direct
  consequence, and is why it is a state rather than a workaround.
* **Nothing in the RADIUS database can reach the control plane.** Accounting
  ingestion remains HTTP, initiated from the RADIUS side, landing on
  `dnb_radius` — a different actor from the publisher, unchanged by this
  decision.
* The publisher process holds both credentials and is the only thing that does.
  It runs where control-plane workers run, not on the RADIUS host.

### 2.15 Auditability

Every publication attempt records: voucher, actor, outcome, error class,
timestamps for queued / claimed / completed. **Never the code and never the
password** — docs/65 §8's rule extends to the publication trail unchanged.

Operator surfaces are the four drift classes of §2.11 and the count of failed
publications. `error_log()` is not a channel (docs/65 §12.2 Q15); whatever
carries these is a monitoring decision, but the signals are defined here.

---

## 3. Rejected alternatives, with the concrete reason

| Rejected | Reason |
|---|---|
| **Model A** | An unsold printed voucher authenticates — measured. Silent, systemic, unbounded; plus two publication events where the amend silently duplicates |
| **Reusing `dnb_radius` as the publisher** | Its trust direction is RADIUS → control plane. Publication is the reverse. That the role exists is not a reason (docs/65 §12.0) |
| **Publishing from the portal request path** | Would put a `radius` database credential in the unauthenticated request role — the exact opposite of docs/65 §10 |
| **Granting the publisher table privileges in `radius`** | `nas.secret` is one `SELECT` away. With definer functions the publisher cannot read any table, including the credentials it writes |
| **A unique constraint on `radcheck (username, attribute)`** | Closed in docs/65 §12.0; also a divergence from stock FreeRADIUS, which expects multiple check items per user |
| **FDW / `dblink` / 2PC between the databases** | Makes the control-plane database a lateral path into the RADIUS database, outside every boundary this project has built |
| **Fully asynchronous publication with no bounded wait** | A guest at a reception desk is not a provisioning queue. The intent queue's 7-day deadline is the shape of the mistake |
| **Rolling the redemption back when publication fails** | Races the retry: publication could succeed after the rollback, leaving a live credential for an `unused` voucher — Model A's failure reintroduced |
| **Status polling by voucher code** | Turns the endpoint into an oracle distinguishing real codes from unknown ones, against docs/65 §8 |

---

## 4. The resulting lifecycle state machine

```
                      issue
                        |
                        v
                    [ unused ] ------- revoke -------> [ revoked ]
                        |                                  ^
                 redeem (portal)                           |
                        |                          unpublish confirmed
                        v                                  |
                  [ activating ] --- revoke ---> [ revoking ]
                    |        |                             ^
     publication ok |        | publication failed          |
                    |        |   (permanent, or past       |
                    v        v    the deadline)            |
                [ active ] [ activation_failed ]           |
                    |   \          |                       |
           Expiration|    \        | admin release         |
             reached |     `--- revoke ------------------->'
                     v            |
                [ expired ]       v
                              [ unused ]   (explicit, audited, a new
                                            code may be issued instead)
```

Three states are new: **`activating`**, **`activation_failed`**, **`revoking`**.

* `activating` — redeemed, registry row written, credential not yet live. The
  honest name for the window §2.14 makes unavoidable. `expires_at` is **not**
  set yet.
* `activation_failed` — publication permanently failed or passed its deadline.
  Operator-visible. Resolvable by retry, by releasing the code back to `unused`,
  or by revoking it and issuing another. Never resolved silently.
* `revoking` — revocation requested, unpublish not yet confirmed. Prevents
  `revoked` from meaning "still authenticating", which docs/65 §4.2 measured.

`expired` is reached by sweep for reporting; FreeRADIUS has already refused on
`Expiration`.

---

## 5. Data ownership

| Data | Owner | Written by | Read by | Notes |
|---|---|---|---|---|
| `mt_vouchers` | control plane | redemption, revoke, sweeps, publisher (on confirm) | control plane | authoritative for the commercial lifecycle |
| `mt_hotspot_users` | control plane | **redemption** (moved from issue — §8) | accounting ingestion, publisher | the registry: voucher ↔ `radius_username` ↔ customer. Not a credential |
| publication rows | control plane | redemption (queue), publisher (claim/complete/fail) | publisher, reconciler, operators | carries the idempotency key and the audit trail |
| `radcheck` / `radreply` | **RADIUS database** | the publisher, only through `publish()` / `unpublish()` | FreeRADIUS | a projection. Never a source of commercial truth |
| `radacct` | RADIUS database | FreeRADIUS | ingestion, over HTTP | unchanged by this decision |
| `radpostauth` | **RADIUS database, independently** | FreeRADIUS, by its own configuration | — | §7. The control plane has no reach over it |
| `nas`, `nas.secret` | RADIUS database | out of scope | FreeRADIUS (`read_clients = yes`) | the publisher has no privilege on it |

---

## 6. Redemption and publication remain separate events

Unchanged from docs/65 §2.1 and restated because the architecture now assigns an
owner to each arrow:

```
  Guest presents voucher
        |
        v
  Validate / redeem            control plane, one transaction
        |
        v
  Authorize activation         control plane: may THIS actor start THIS voucher
        |
        v
  Publish AAA credential       the Publisher, across the boundary, retryable
        |
        v
  Guest authenticates          FreeRADIUS, from radcheck
        |
        v
  RADIUS session               radacct, ingested back over HTTP
```

They are not collapsed into one portal operation. `activating` exists precisely
because arrows three and four cannot share a transaction.

---

## 7. Post-authentication logging — an independent AAA concern

Recorded, not acted on, and deliberately outside this architecture:

* **Control-plane retention does not govern `radpostauth`.** FreeRADIUS writes
  it from its own configuration; nothing decided here reaches it.
* **The Phase 0 cleanup checklist missed it** — docs/65 §12.4. `radcheck`,
  `radreply`, `radacct` and `nas` were recorded at 0 rows; `radpostauth` was not
  in the list and still holds one row.
* **Future operational cleanup and retention must address it separately**, as
  its own checklist item for this server.
* **The surviving Phase 0 row is not to be read or exposed**, and nothing in
  this decision depends on changing or deleting it.

**One consequence worth stating, because the architecture creates it.** The
publisher writes both the username and the password. It is therefore no longer
necessary for the voucher code to *be* the RADIUS password — the publisher can
mint a password unrelated to the code. Before there was a publisher, nothing
could generate one, so docs/33's `password = username = code` was the only
option. That materially changes **decision 3** (the portal contract), which
remains open: it is no longer only a disclosure trade-off but a way to keep the
voucher code out of `radpostauth` entirely. Recorded as an input to decision 3,
not decided here.

---

## 8. Amendments required

**None to docs/53. F1–F13 are unamended, F11 included.**

**docs/45 §2.1 requires no amendment either** — Model B makes the code match the
frozen document rather than the reverse. Concretely, the change is to the
implementation:

* `VoucherService::issue()` **stops** writing `mt_hotspot_users`;
* the redemption transaction writes it instead;
* the `mt_hotspot_users` table comment — *"Redeeming a voucher creates one of
  these and nothing else (docs/45 §2.1)"* — **becomes true**, and the
  contradiction recorded in docs/65 §0 is resolved by the code moving, not by a
  document being rewritten.

**The P3 / F2 conflict is unresolved and stays documented.** docs/65 §6.1
stands: P3 has the control plane contact a router synchronously outside the
intent queue, F2 says the queue is the only management path. Nothing here
resolves it; decision 3 must, and if it selects P3 it needs an F2 amendment
under docs/53 §5 first.

**New architecture, not an amendment:** AAA publication, `Expiration`, idempotent
republication, and revocation-plus-disconnect are new and belong in their own
architecture document, per docs/65 §11.

---

## 9. Remaining open decisions

| # | Decision | Note |
|---|---|---|
| **2** | Site binding A / B / C | unchanged; the cross-tenant floor holds under all three |
| **3** | Portal contract P1 / P2 / P3 | **changed by §7**: the publisher can mint a password unrelated to the code, so this now also decides whether the code reaches `radpostauth`. P3 still needs the F2 question settled first |
| **4** | Front-desk activation | unchanged; a business question |
| **5** | Rate-limit numbers | now also fixes the **bounded-wait duration** of §2.4 and the **publication deadline** of §2.7 |
| **6** | Retention | now explicitly two questions: the control plane's attempt store, and `radpostauth` separately (§7) |

**Decisions 1 and 7 are closed.**

---

## 9a. An implementation dependency recorded after this decision closed

**This decision is unchanged.** docs/68 §2.4d records a dependency of
*implementing* it, found while verifying Decision 2a: the customer → NAS-set
restriction that protects the AAA plane is carried by a **huntgroups file**,
which is server-side configuration rather than RADIUS database state, and a
change to it takes effect only on a full FreeRADIUS restart.

That gives the publisher's world a second kind of state beyond `radcheck` /
`radreply`. It does not alter §2.3's privilege model — it extends it: the
publisher must not receive arbitrary configuration or filesystem access, and
whatever maintains the mapping must be narrow, validated, audited, and aware
that its change is a service event. A single-NAS customer needs none of it.

---

## 10. What was decided without being built

Nothing in this record exists in code. The next step after it is an
implementation gate, and the order that gate should follow — migrations, roles,
the publisher, the state machine, the reconciler, tests — is not part of this
decision and is deliberately left to it.

`tests/run.sh`: all suites passed, 718 assertions, unchanged. No code, schema,
privilege, FreeRADIUS configuration or deployment was touched in this session.
