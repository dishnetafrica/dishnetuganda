# 62 — F9 Final Design Review

Design review and disposable measurement. **No production schema, migration or application code
was changed.** Experiments ran in throwaway databases (`dnb_cap`, `dnb_plan`) — the second built
from the real migrations so the query plans are the real ones — all torn down afterwards.

This document is meant to be reviewable and then implemented once, rather than iterated on in the
production schema.

---

## 1. Final actor model

| Actor | Authentication | Authority | DB role | Context | Cross-customer |
|---|---|---|---|---|---|
| Customer | OTP → bearer token, HMAC'd with an app-side pepper | a live customer session | `dnb_app` | session key → resolver | **no** |
| Admin | admin bearer token (same scheme, separate session table) | a minted capability naming (admin, operation, customer) | `dnb_admin` | capability secret → resolver | **yes, one customer per capability** |
| Worker | process credential at connect | the intent it claimed | `dnb_worker` | lease secret → resolver | **yes, one intent's customer at a time** |
| RADIUS | shared secret at the HTTP edge + role credential | a hotspot username | `dnb_radius` | **none** | **yes, but write-only through one function** |

Per actor:

| | Customer | Admin | Worker | RADIUS |
|---|---|---|---|---|
| Credential exists as | session token | capability secret | lease secret | none |
| Stored as | SHA/HMAC hash | hash + expiry | hash + expiry | — |
| Minted by | `mt_auth_create_session` | `mt_admin_open` | `mt_intent_claim` | — |
| Verified by | `mt_current_customer` | `mt_admin_scope` | `mt_worker_scope` | `mt_session_account` |
| Revocable | **yes, immediately** | yes, by expiry or by clearing the row | yes, lease expiry | n/a |
| Lifetime | session TTL | **short — see §4** | lease TTL (existing: 5 min) | n/a |
| Replay | bounded by session validity | **see §4** | bounded by lease | n/a |
| Connection lifetime | per transaction (`SET LOCAL`) | per transaction | per transaction | none |
| Transaction lifetime | re-resolved **every statement** | every statement | every statement | none |
| Audit record | session row | **capability row: admin, customer, operation, issued_at** | intent row: `claimed_by` | session row |
| Failure behaviour | resolver returns NULL → **0 rows**, fail-closed | NULL → 0 rows | NULL → 0 rows | function returns NULL |

## 2. Admin-on-behalf-of flow

```
1. admin presents a bearer token                         (HTTP)
2. app hashes it and resolves a live admin session       mt_admin_resolve_token
3. app names the target customer and the operation       (HTTP request)
4. AUTHORISATION IS CHECKED IN THE DATABASE              mt_admin_open verifies
     - a live, unrevoked admin session, AND
     - an explicit (admin, operation, customer) permission
5. the database mints a capability and returns it ONCE   only its hash is stored
6. app sets app.admin_cap as a BOUND PARAMETER           SET LOCAL, per transaction
7. RLS consumes it via the dnb_admin policy              mt_admin_scope()
8. the capability row IS the audit record                admin, customer, operation, issued_at
```

**A raw customer UUID is never authority.** It is an *argument* to step 4, which refuses unless a
permission row exists. Measured in docs/61 and re-confirmed here:

| Test | Result |
|---|---|
| alice (permitted on any customer) → P | capability issued, reads P |
| alice → Q | capability issued, reads Q |
| P's capability also showing Q | **nothing** |
| bob (permitted on P only) → Q | **refused at mint time** |
| bob → P | issued |
| customer manufacturing a capability | **no EXECUTE on the mint function, no INSERT on the table** |
| worker manufacturing one | same |
| expired capability | **nothing** |
| revoked admin session | **refused at mint time** |
| capability presented on a customer connection | **inert** — the policy is `TO dnb_admin` |

The last row is the property worth naming: authority is scoped by **role and credential class
together**, so a leaked admin capability is useless on a customer connection and vice versa.

## 3. Capability design

```
mt_admin_caps (cap_hash PK, admin_id, customer_id, operation, expires_at, consumed_at, issued_at)
```

The secret is returned once by the minting function; only its hash is stored, so a database dump
does not yield usable capabilities. One row per authorisation event — the table is the audit log.

## 4. Capability lifetime and replay — **measured**

| Property | A: reusable until expiry | B: single-use |
|---|---|---|
| First use | works | **refused until explicitly consumed** |
| Replay (2nd, 10th use) | **works** — a bearer credential for its lifetime | consumption refused: `NULL — already consumed` |
| Expired | nothing | nothing |
| Scope | one customer | one customer |
| **Two concurrent claimants** | both succeed | **exactly one winner, measured** — atomic `UPDATE … WHERE consumed_at IS NULL RETURNING` |
| Rollback of the operation | n/a | **consumption rolls back too** — the capability becomes consumable again |
| Operational complexity | low | higher: consumption is a separate step the app must perform once |
| Leakage risk | a captured capability is usable for its whole TTL | a captured capability is usable only if the attacker consumes it *first* |
| Auditability | issued_at only | issued_at **and** consumed_at |

**Recommendation: B, single-use, with a short TTL (60 s) and a 30 s post-consumption operation
window.** Rationale: it bounds a captured capability to a race rather than a window, it records
when authority was actually exercised, and the concurrency property is proven rather than assumed.

Two consequences to accept explicitly, both measured:

- **Single-use means single *successful* use.** Consumption participates in the caller's
  transaction, so a rolled-back operation releases the capability — correct for retries, and it
  means a crash between consume and commit leaves it live until TTL.
- **Consumption cannot live inside the resolver**, because a `STABLE` function may not write. It
  is a separate explicit call, which is why A is operationally simpler and B is stronger.

A remains acceptable **only** with a TTL measured in seconds. It must never become a long-lived
bearer credential for a customer.

## 5–6. Tenant-index requirement and query-plan evidence

Measured on a database built from the **real migrations, with the real policies**, with the
resolver swapped to the F9 shape, 50 000 rows per table:

| Table | Index on tenant column | Plan | Time | Buffers |
|---|---|---|---|---|
| `mt_sessions` | yes | Index Only Scan | 4.20 ms | 832 |
| `mt_vouchers` | yes | Index Only Scan | 4.04 ms | 792 |
| `mt_hotspot_users` | **no** | **Seq Scan** | **155.72 ms** | **50 516** |
| `mt_hotspot_users` | after adding one | Index Only Scan | **1.45 ms** | 25 |

**37× slower and 60× more buffers** from one missing index — and the buffer count tracking the row
count is the resolver being evaluated per row. Adding the index made it **107× faster**.

This settles the correction from docs/61: per-statement resolver cost is **a property of the plan,
not of the design**, so it becomes a schema requirement.

`table → predicate → query pattern → required index → measured plan`

| Table | Predicate | Pattern | Required index | Measured |
|---|---|---|---|---|
| `mt_hotspot_users` | `customer_id = mt_current_customer()` | RADIUS username lookup + customer listing | `(customer_id)` | **Index Only Scan, 1.45 ms** |
| `mt_sessions`, `mt_vouchers`, `mt_intents`, `mt_uplink_samples`, `mt_audit_log`, and 11 others | same | listing, filtering | already present | Index Only Scan |
| `mt_auth_sessions` | read by the **definer** under a constant-`true` policy | point lookup on `token_hash` (**primary key**) | none needed on `customer_id` | PK lookup |
| `mt_auth_codes` | **exempt** from tenant policy (pre-authentication) | point lookup on `phone` (indexed) | none needed | — |

**So of the three tables flagged in docs/61, exactly one needs an index: `mt_hotspot_users`.** The
other two were false positives of a coverage query, not of a plan. This is the difference the brief
asked for between "add indexes everywhere" and knowing which are required.

No composite index is justified by anything currently in the codebase; that judgement should be
revisited when the Admin API's query patterns exist.

## 7. Final attack matrix — attacker holds arbitrary SQL as `dnb_app`

| Attack | Result | Evidence |
|---|---|---|
| become Q | **✗** | docs/61 §9 — supplying Q's uuid, Q's session-row id, or setting every `app.*` GUC all return nothing |
| read Q | ✗ | same |
| write Q | ✗ | RLS `WITH CHECK` |
| manufacture admin authority | ✗ | no EXECUTE on the mint function; no INSERT on the capability table |
| manufacture worker authority | ✗ | no EXECUTE on the claim function |
| manufacture RADIUS authority | ✗ | no EXECUTE on the ingestion function |
| reuse a previous customer's context | ✗ | `SET LOCAL` cleared by commit and by failed transactions |
| reuse a previous admin capability | ✗ under B (single-use); bounded by TTL under A | §4 |
| bypass RLS via a SECURITY DEFINER function | **partially — this is F6** | `mt_voucher_redeem` remains callable and ignores tenant context |
| read the credential store | ✗ | no grant on the session/capability tables |

The one honest gap is the last-but-one row, and it is a **pre-existing open finding (F6)**, not
something this design introduces. The F9 design does not close it and must not be said to.

## 8. Regression results

| Probe | Result |
|---|---|
| S1/S2 A1–A5, **byte-identical** to `tools/audit/s1_s2_probe.php` | A1 no rows · A2 null · A3 `42501` · A4 `42501` then null · A5 works |
| F1 accounting boundary | request role denied; worker claims across customers |
| F2 non-superuser owner | `dnb: super=false bypassrls=false` |
| F4 RLS guard | 19/19 in scope; no table with RLS off, FORCE off, missing or one-sided policy |
| Full suite | **718 assertions, green** |

## 9. F5 / F6 / F8 — still open, not closed by this design

- **F5** — new tables arrive granted to all roles with RLS off; new functions PUBLIC-executable.
  The four-actor model makes this *worse to get wrong*, because a new table with no per-actor
  policy is readable by every role rather than by one. Unchanged and open.
- **F6** — `mt_voucher_redeem` ignores tenant context and returns a customer id. **The F9 design
  does not address it.** It is the one remaining SECURITY DEFINER path a request-role caller can
  use to touch another customer's row.
- **F8** — telemetry writable by the customer it describes. A *grant* problem, not a context
  problem. The per-actor model would make the fix natural — move telemetry writes off the customer
  policy onto the worker's — but that has not been designed or prototyped.

## 10. Production migration plan

One migration, in this order:

1. `CREATE INDEX mt_hotspot_users_customer_ix ON mt_hotspot_users (customer_id);`
2. `mt_admins`, `mt_admin_sessions`, `mt_admin_perms`, `mt_admin_caps`, with RLS enabled, forced,
   and a definer-role policy only.
3. `mt_current_customer()` → plpgsql, SECURITY DEFINER owned by `dnb_def_auth`, resolving
   `app.session_key` against live sessions. **No role branch.**
4. `mt_admin_scope()`, `mt_worker_scope()` — same shape, owned by their definer roles.
5. `mt_admin_open(admin_token, customer, operation)`, `mt_admin_consume(cap)`,
   `mt_intent_claim` extended to return a lease secret.
6. Replace the single all-roles isolation policy on each of the 18 tenant tables with **four
   per-role policies**. `mt_customers` keeps its `id =` variant.
7. Recursion break: constant-`true` definer policies on `mt_auth_sessions` and the admin tables;
   no application-role grants on them.
8. `SELECT mt_revoke_public_execute();` last.

## 11. Application changes

- `TenantContext`: `runAsSession($sessionKey, $fn)` for the request path;
  `runAsCapability($cap, $fn)` for admin; `runAsLease($lease, $fn)` for the worker.
  `run($customerId, $fn)` is **removed** — nothing should be able to assert a customer id.
- `Kernel` passes the token hash it already computes.
- `IntentWorker` passes the lease returned by `claim()`.
- All three pass the credential as a **bound parameter**, never interpolated.
- Tests: the whole docs/61 matrix as a suite; the four regression probes unchanged; and a negative
  test that reintroduces a role branch in a resolver and proves the suite goes red.

## 12. Deployment and secret requirements

1. **Role passwords** — still development literals for all five roles. Pre-existing gate.
2. **`DNB_TOKEN_PEPPER`** must be set, secret, and not the development default. F7's downgrade
   depends on it, and nothing tests it today.
3. **`log_statement = all` becomes a credential leak** — session keys and capabilities would be
   written to the log. Bound parameters mitigate it; the setting must be a deployment check.
4. No new database-visible secret is introduced. The pepper stays out of SQL.

## 13. Unresolved questions

1. **The Admin API's own authentication is still undesigned.** This document specifies what the
   *database* requires. How an admin authenticates at the HTTP edge, how operations are named, and
   how the UI selects a target customer are the Admin API's design.
2. **Capability TTL of 60 s is a judgement, not a measurement.** It cannot be measured until admin
   workflows exist.
3. **Load is untested.** 50 000 rows on a local socket is not production.
4. **The GUC remains forgeable** — only its contents are now secrets. Unchanged from docs/60.
5. **F6 is the one unaddressed path** by which a request role reaches another customer's row.
6. **Worker lease semantics under crash** were not prototyped here; the existing lease/reclaim
   behaviour is tested but not against the new context mechanism.

## Classification

| Item | Status |
|---|---|
| Four-actor model, per-role policies | **PROTOTYPED** (docs/61) |
| Admin-on-behalf-of flow, DB half | **PROTOTYPED** |
| Capability replay, expiry, scope | **PROVEN** — measured both variants |
| Single-use concurrency: exactly one winner | **PROVEN** — measured |
| Single-use consumption rolls back with the transaction | **PROVEN** — measured |
| Tenant-index requirement | **PROVEN** — real schema, real policies, 37× / 107× |
| `mt_hotspot_users` is the only missing index that matters | **PROVEN** |
| docs/61's "three missing indexes" as a requirement | **REJECTED** — two were coverage false positives |
| S1/S2, F1, F2, F4 regressions | **PROVEN** — re-run |
| F5, F6, F8 | **open, unchanged** |
| Admin API authentication | **UNPROVEN** — not designed |
| Capability TTL value | **UNPROVEN** |
| Any production implementation | **UNPROVEN** — nothing built |
