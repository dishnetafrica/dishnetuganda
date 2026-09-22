# 61 — The Four-Actor Context Model: Design and Prototype

Disposable prototype only. **No production schema, migration or application code was changed.**
The experiment lives in a throwaway database (`dnb_actors`) with `pc_*` tables and `proto_*` roles,
torn down afterwards; scripts are in `tools/audit/actors*.{sql,sh}`.

Nothing here is approved for implementation.

---

## 1. The final actor/context model

The rule that shapes everything below: **there is no universal customer context.** Each actor has
its own authority, its own credential, its own resolver, and its own RLS policy. They can all end
up reading customer-scoped rows; *the reason they are permitted to* is different in each case.

| Actor | Authority is | Credential | Resolver | Policy on the tenant table |
|---|---|---|---|---|
| customer (`dnb_app`) | a live customer session | session key | `pc_customer_ctx()` | `TO dnb_app` |
| admin (`dnb_admin`) | a minted, scoped capability | capability secret | `pc_admin_scope()` | `TO dnb_admin` |
| worker (`dnb_worker`) | the intent it actually claimed | lease secret | `pc_worker_scope()` | `TO dnb_worker` |
| RADIUS (`dnb_radius`) | a hotspot identity | none | none — no context at all | **no grants on the table** |

### The design improvement this produces

Per-role policies mean **no resolver needs a role branch**. The previous prototype (docs/60) put
`IF current_user <> 'proto_app'` inside the resolver so the worker and admin could keep the
identifier path — and that was the bug that silently disabled the whole protection, because inside
a SECURITY DEFINER function `current_user` is the owner. In this model the question never arises:
PostgreSQL decides which policy applies from the connected role, before any function runs.

The hazard is not mitigated. It is designed out.

## 2. Customer context — **PROTOTYPED**

`verified customer credential → session key in a GUC → resolver → customer → RLS`

A customer cannot become another customer by supplying a customer UUID, a session-row id, an
arbitrary GUC, a token id, or a function argument — every one tested in §9. Establishing Q's
context requires a value hashing to one of Q's live sessions, which `dnb_app` cannot read
(no grant on the session table at all) and cannot compute (it is Q's bearer token under a pepper
that is not in the database).

## 3. Admin context — **PROTOTYPED**

This is the part that had to be designed rather than reused. An admin holds no customer
credential and must never be able to obtain one.

```
admin session ──► pc_admin_open(admin_token, target_customer, operation)
                       │  verifies a live admin session
                       │  verifies an explicit permission for (admin, operation, customer)
                       ▼
                  a single-purpose capability secret, minted SERVER-SIDE
                       │  only its hash is stored, with an expiry
                       ▼
                  app.admin_cap ──► pc_admin_scope() ──► that ONE customer
```

Properties, all measured:

- **alice** (permitted on any customer) opens a capability for P and reads P; opens one for Q and
  reads Q. **P's capability does not also show Q** — the capability names one customer.
- **bob** (permitted on P only) is refused for Q and issued for P. Authority is per (admin,
  operation, customer), not per role.
- An admin presenting a **raw customer UUID** sees nothing.
- An admin presenting a **customer session key** sees nothing — that credential belongs to a
  policy that does not apply to this role.
- An admin with **no capability** sees nothing.
- `dnb_admin` has no INSERT on the capability table, so an attacker holding SQL as that role
  cannot mint one; it must go through the function, which checks a live admin session.

This is the distinction the brief asked for: an admin crossing a customer boundary presents a
**capability naming the customer, the operation and the admin who was authorised**, which is a
different kind of object from a customer credential and is recorded for audit. A customer
attempting to impersonate another customer has nothing of that shape to present.

## 4. Worker context — **PROTOTYPED**

`worker identity → claimed intent → customer derived from the queued object`

The worker never supplies a customer. It calls `pc_intent_claim()`, which returns a lease secret
bound to the intent it actually claimed; `pc_worker_scope()` resolves that lease to the intent's
customer. Measured: claiming intent 1 shows exactly that customer, claiming intent 2 shows the
other, a raw customer UUID as authority shows nothing, a customer session key shows nothing, an
admin capability shows nothing, and with no lease it sees nothing.

## 5. RADIUS context — **PROTOTYPED**

`trusted ingestion identity → hotspot username → customer`

`proto_radius` holds EXECUTE on one function and **no table privileges whatsoever**. It ingests
for P and for Q, attributed by username; an unknown username creates nothing; it cannot read the
tenant table, cannot read the usage rows it just wrote, cannot mint a capability and cannot claim
an intent. `dnb_app` cannot invoke the ingestion primitive at all.

## 6. Recursion solution — **PROTOTYPED**

The customer resolver reads the session table, whose own tenant policy would call the resolver.
Measured in docs/60: that errors.

The fix, measured here: the tables a resolver reads carry a policy `TO <definer role> USING (true)`
— **a constant, calling no resolver** — while their tenant policies name the other roles
explicitly. Verified: customer RLS works, the resolver resolves, the cycle does not occur, and the
exemption cannot be exploited because `dnb_app` has **no grant at all** on the session table
(`permission denied for table pc_sessions`). The exemption is a policy, not a privilege; without
the underlying grant it gives nothing.

## 7. Revocation — **PROTOTYPED**

| Step | Result |
|---|---|
| valid credential | reads own data |
| session revoked, same credential | **nothing**, immediately |
| context established *before* revocation, within the same transaction | **nothing** — the resolver re-evaluates |
| session expired | nothing |

Revocation is not merely fast; it takes effect *inside* an open transaction, because the resolver
is evaluated per statement rather than latched at context-open.

## 8. Connection pooling — **PROTOTYPED**

| Test | Result |
|---|---|
| `SET LOCAL` surviving `COMMIT` | cleared |
| after a failed transaction and rollback | cleared; 0 rows visible |
| an **admin capability** leaked onto a customer connection | nothing — the admin policy is `TO dnb_admin` |
| a **customer key** leaked onto an admin connection | nothing — the customer policy is `TO dnb_app` |

The last two are the strongest pooling property in this model: **a credential of the wrong class is
inert on the wrong connection**, because the policy that would consume it does not apply to that
role. Cross-actor context bleed is not just prevented, it is unrepresentable.

## 9. Complete attack matrix

`✗` = attack produced nothing. `✓` = legitimate operation worked.

| | Customer | Admin | Worker | RADIUS |
|---|---|---|---|---|
| Read own / authorised customer | ✓ | ✓ (capability) | ✓ (claimed intent) | ✓ (ingest only) |
| Read another customer | ✗ | ✓ **only where authorised**; bob refused for Q | ✗ except the claimed intent's | ✗ no table access |
| Set arbitrary customer GUC | ✗ | ✗ | ✗ | ✗ |
| Become another customer | ✗ | n/a — never becomes a customer | ✗ | ✗ |
| Manufacture admin context | ✗ (no EXECUTE, no INSERT) | — | ✗ | ✗ |
| Manufacture worker context | ✗ | ✗ | — | ✗ |
| Manufacture RADIUS context | ✗ | ✗ | ✗ | — |
| Present another actor's credential | ✗ | ✗ | ✗ | ✗ |
| Read the credential store | ✗ permission denied | ✗ | ✗ | ✗ |
| Write a row labelled another customer | ✗ RLS `WITH CHECK` | ✗ | ✗ | ✗ |
| Context survives the request boundary | ✗ | ✗ | ✗ | ✗ |

Every row was verified by **data visibility or side effect**, not by a function's return value.

### Three defects the prototype found in itself

1. **`CREATE FUNCTION` grants EXECUTE to PUBLIC — again.** Granting the claim function to the
   worker did not take it from anyone else: the customer, the admin and the RADIUS roles could all
   call `pc_intent_claim` and `pc_admin_open`. **This is the third occurrence in this project**
   (after S2 and migration 016). Any production migration must end with the revoke sweep; nothing
   about a `GRANT ... TO <one role>` implies exclusivity.
2. **`INSERT ... RETURNING` needs SELECT on the returned column.** The owner role had INSERT only,
   so RADIUS ingestion failed *inside its own function*. Same lesson as `mt_customer_create`.
3. **Dropping a PRIMARY KEY does not drop the NOT NULL it implied**, so a wildcard permission row
   (`customer_id IS NULL` = "any customer") was rejected — and because the seed was one multi-row
   `INSERT`, it took the valid row down with it, leaving *both* admins with no permissions. The
   symptom was "admin authorisation works", which looked like the design working.

## 10. Performance — and a correction to docs/60

docs/60 reported "2 resolver calls per statement, independent of row count". **That was true of
that prototype and is not true in general.** Measured here on the same 5001-row table:

| Configuration | Resolver calls | 200 statements |
|---|---|---|
| **No index on the tenant column** | **5004 — one per row** | 6.39s |
| **Index on the tenant column** | **2 — per statement** | **0.188s** |

A 34× difference. The plan explains it: with an index the predicate becomes an `Index Only Scan`
key and the STABLE expression is evaluated once as a run-time constant; on a sequential scan it is
a filter evaluated per row. docs/60's measurement was correct only because that prototype happened
to have the index.

So the per-statement cost is **not a property of the design** — it is a property of the plan, and
therefore a hard requirement on the schema.

**Audited against production:** of the 19 customer-scoped tables, three have no leading index on
the tenant column — `mt_auth_codes`, `mt_auth_sessions`, `mt_hotspot_users`. Of those, only
`mt_hotspot_users` carries a tenant policy that a resolver would evaluate (the other two are read
by the definer under a constant-true policy or are exempt), so the practical gap is one table —
but it is one table nobody would have noticed.

Language choice is a smaller, separate effect: a `LANGUAGE sql` resolver is **inlined**, which
makes it invisible to `pg_stat_user_functions` (it reports 0 calls, which is not the same as "not
called"). Timings with an index were comparable — 0.205s inlined, 0.188s plpgsql.

## 11. Recommended production architecture

Recommended, **not implemented**:

1. **Four policies per customer-scoped table**, one per actor role, each calling its own resolver.
   No role branches inside any resolver.
2. **Customer**: `app.session_key` → `mt_current_customer()` resolving live sessions
   (candidate B from docs/60).
3. **Admin**: `mt_admin_open(admin_token, customer, operation)` minting a short-lived,
   single-customer capability against an explicit `(admin, operation, customer)` permission;
   `app.admin_cap` → `mt_admin_scope()`. Admin authority becomes auditable by construction.
4. **Worker**: `mt_intent_claim()` returns a lease; `app.intent_lease` → `mt_worker_scope()`. The
   worker never supplies a customer id.
5. **RADIUS**: unchanged — EXECUTE on one function, no table privileges.
6. **Recursion break**: definer-role policies that are constant `true`, plus no underlying grant
   for application roles on the credential tables.
7. **Every customer-scoped table must have a leading index on its tenant column**, and that must
   be asserted by the same catalogue-driven guard that checks RLS (F4). Add `mt_hotspot_users`.
8. **Resolvers in plpgsql**, so they remain visible in function statistics.
9. Every migration touching functions ends with the PUBLIC revoke sweep.

## 12. Unresolved risks

1. **Admin-on-behalf-of in the HTTP layer is still undesigned.** The capability model answers the
   *database* question. Which admin actions may open a capability, how the Admin API authenticates
   an admin, and how operations are named, are not settled — and that is the Admin API's core
   design, not a detail.
2. **Capability lifetime is a guess.** Two minutes was chosen for the prototype. The right value
   depends on how the Admin API batches work, which does not exist.
3. **Single-use vs reusable capabilities was not decided.** The prototype mints reusable-until-expiry
   capabilities; `consumed_at` exists but is never set.
4. **Load is still untested.** 0.188s for 200 trivial statements on a local socket is not a
   production measurement, and the index finding shows how sharply plan shape dominates.
5. **The GUC is still forgeable; only its *contents* are now secrets.** Nothing in PostgreSQL
   makes `app.*` unsettable — proven in docs/60. This model raises the cost of forgery from
   "know a uuid" to "hold a live credential"; it does not eliminate the mechanism.
6. **F5, F6 and F8 remain open and untouched.** F8 in particular is unaffected by any of this: it
   is a grant problem, not a context problem, and the per-actor model would let it be fixed by
   moving telemetry writes off the customer policy — but that has not been designed.

## Classification

| Item | Status |
|---|---|
| Customer context | **PROTOTYPED** |
| Admin capability context | **PROTOTYPED** |
| Worker lease context | **PROTOTYPED** |
| RADIUS ingestion context | **PROTOTYPED** |
| Recursion break | **PROTOTYPED** |
| Revocation, including mid-transaction | **PROTOTYPED** |
| Cross-actor credential inertness | **PROTOTYPED** |
| Per-statement cost **requires an index** | **PROVEN** (measured both ways) |
| docs/60's unqualified per-statement claim | **REJECTED** — corrected above |
| Production index gap on `mt_hotspot_users` | **PROVEN** |
| Admin-on-behalf-of HTTP design | **UNPROVEN** — not designed |
| Capability lifetime and single-use policy | **UNPROVEN** |
| Any production implementation | **UNPROVEN** — nothing built |
