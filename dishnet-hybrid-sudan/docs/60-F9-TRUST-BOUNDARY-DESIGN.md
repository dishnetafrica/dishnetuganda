# 60 — F9: Where the Trust Boundary Belongs

Design and disposable prototypes. **No production schema, migration or application code was
changed.** The prototype lives in a throwaway database (`dnb_proto`) with `pc_*` tables and
`proto_*` roles, so nothing here can be mistaken for the real thing. Scripts are in
`tools/audit/proto*.{sql,sh}`.

No architecture is recommended for implementation until this evidence is reviewed.

---

## 1. The current F9 trust model

```
bearer token ──HMAC(pepper)──► token_hash ──mt_auth_resolve_token──► (principal, customer)
                                                                          │
                                                       Kernel ────────────┘
                                                          │
                                        SET LOCAL app.customer_id = <uuid>
                                                          │
                                   RLS: customer_id = mt_current_customer()
```

Strong at both ends, unverifiable in the middle. `app.customer_id` is a custom GUC holding an
**identifier**. PostgreSQL records no owner, no signature and no provenance for it, and
`mt_current_customer()` returns what it is told. The statement the whole model rests on is:

> PHP promises the GUC is truthful.

## 2. Threat model

An attacker has **arbitrary SQL execution as `dnb_app`** — the shape any future injection in the
Admin API would take. They may read anything that role can read, call anything it can call, and
set any `app.*` GUC.

**Requirement:** customer isolation must hold unless the attacker possesses material specific to
customer Q that they cannot obtain from that position.

The corollary that decides everything below: *any mechanism `dnb_app` can invoke, the attacker can
also invoke.* So the only question worth asking about a candidate is **what input does it require,
and can the attacker get it?**

## 3–4. Candidate architectures and their security properties

### A — GUC holds the identifier (today) · **REJECTED**

| | |
|---|---|
| What PostgreSQL verifies | nothing — it is a string the session set |
| Material required to become Q | **Q's uuid** |
| Can the attacker forge it | **yes, trivially** |
| Verdict | this is the finding |

### B — GUC holds a session SECRET; RLS resolves it · **PROTOTYPED**

The GUC carries the caller's session key (the token hash the app already computes) instead of a
customer id. `mt_current_customer()` becomes SECURITY DEFINER and resolves it against live,
unrevoked, unexpired sessions.

| | |
|---|---|
| What PostgreSQL verifies | that the presented key matches a live session row |
| Material required to become Q | a value hashing to one of **Q's live sessions** |
| Available to the attacker? | **no** — `dnb_app` cannot read Q's session row (RLS), and cannot compute the hash without Q's bearer token *and* the pepper |
| Can RLS consume it | yes, unchanged policy shape |
| Secret in the database | **none added** — the DB still stores only hashes |
| Revocation | **immediate** — measured |
| Cost | **2 resolver calls per statement, independent of row count** — measured on a 5001-row scan |

### C — Role per customer, `SET ROLE` · **REJECTED**

Prototyped and broken in three lines. A pooled request role must be able to switch into any
customer role to serve them, and once it can, so can the attacker:

```
as P: P-PRIVATE
attacker switches to Q: Q-PRIVATE
and can switch again mid-session: Q-PRIVATE
```

The only variant that resists it — one connection per customer, authenticated as that customer —
destroys pooling and requires storing a credential per customer.

### D — A definer function sets a GUC the caller cannot overwrite · **REJECTED**

This is the cheap, obvious design: resolve once per request, write a protected GUC, let RLS read
it. It depends on custom GUCs being restrictable. **They are not.** Measured:

```
REVOKE SET ON PARAMETER "app.customer_id" FROM PUBLIC;   -- reports: REVOKE
-- then, as dnb_app:
SET app.customer_id = 'anything-i-like';                 -- reports: SET
SELECT current_setting('app.customer_id');               -- anything-i-like
```

The `REVOKE` is accepted and does nothing, because `app.*` placeholders have no privilege model.
A definer function could still set the GUC; the attacker would simply overwrite it afterwards.

*This is the third time in this project that a privilege statement has been accepted while
changing nothing* — after the `dnb_app` revoke that `PUBLIC` defeated, and the
`ALTER DEFAULT PRIVILEGES` that stored no row.

### E — Asymmetric signed context token · **REJECTED (unavailable)**

The DB would hold only a public key and verify a short-lived assertion signed by the application.
It satisfies every security property, and **pgcrypto cannot do it**: the available primitives are
`hmac`, `digest`, `gen_random_bytes`, `crypt` and `pgp_pub_decrypt`. There is no signature
verification. `pgp_pub_decrypt` is the wrong direction — public-key *encryption* means anyone
holding the public key can produce a valid ciphertext, so it authenticates nothing. Would require
an extension this deployment does not have.

### F — GUC holds identifier + HMAC, key in a definer-only table · **PROTOTYPED**

| | |
|---|---|
| What PostgreSQL verifies | that the identifier carries a valid MAC under a key `dnb_app` cannot read |
| Material required to become Q | **the context key** |
| Available to the attacker? | no — the key table is readable only by the definer role |
| Secret in the database | **yes, one added** — see §9 |
| Revocation | **none** — a captured MAC is valid forever unless expiry and a nonce are designed in, which the prototype does not have |
| Cost | one key fetch + one HMAC per statement |

## 5–6. Prototype results: the attack matrix

Identical attacks, as `proto_app`, against each candidate. `nothing` = the attack returned no rows.

| Attack | A (today) | B (session secret) | F (HMAC) |
|---|---|---|---|
| P establishes its own context, reads own | P-PRIVATE | P-PRIVATE | P-PRIVATE |
| **attacker supplies only Q's UUID** | **Q-PRIVATE** | nothing | nothing |
| **attacker sets every `app.*` GUC to Q** | **Q-PRIVATE** | nothing | nothing |
| attacker forges a MAC | nothing | nothing | nothing |
| attacker calls the resolver directly | **returns Q** | NULL | NULL |
| attacker writes a row labelled Q | denied (RLS) | denied (RLS) | denied (RLS) |
| fresh connection, no context | nothing | nothing | nothing |
| after `RESET` (released connection) | n/a | nothing | nothing |
| with a junk key | n/a | nothing | nothing |
| P holding **Q's actual credential** | Q-PRIVATE | Q-PRIVATE | nothing |
| worker across customers | works | works | works |
| admin across customers | works | works | works |

The row that matters is the second: **supplying Q's identifier is enough today and is enough under
no other candidate.**

The penultimate row is not a defect in B — holding Q's session token *is* being Q, and an attacker
who has stolen it has already compromised Q by any route. F resists it only because its credential
is a system key rather than a user credential, which is the same property that makes F
unrevocable.

### A bug in my own prototype, worth more than the prototype

The first run of B and F reported *no protection at all*. The branch that keeps the worker and
admin on the identifier path was written `IF current_user <> 'proto_app'` — and **inside a
SECURITY DEFINER function `current_user` is the function's owner, not the caller**. The test was
therefore always true, every caller took the non-app path, and the protection was a no-op that
looked exactly like working code. `session_user` is the correct test.

Had this shipped, the migration would have applied cleanly, the suite would have passed, and F9
would have been "fixed" while remaining fully open. It is the same failure mode as S2, the
`ALTER DEFAULT PRIVILEGES` no-op, and the eight-of-nineteen guard.

## 7. Worker / admin / RADIUS compatibility

Both B and F keep a **role-aware branch**: `dnb_app` must present a credential; the other actors
keep the identifier path they use today.

| Actor | Under B / F | Why this is not a weakening |
|---|---|---|
| `dnb_app` | must present a session credential | the point |
| `dnb_worker` | identifier path, unchanged | it legitimately spans customers, and `dnb_app` cannot become it — separate login, no membership, proven in `test_definer_roles.php` |
| `dnb_admin` | identifier path, unchanged | same |
| `dnb_radius` | unaffected — sets no context | it holds EXECUTE on one function and no table privileges |
| owner | unaffected — reads no tenant data | |

Verified in the prototype: worker and admin still read across customers under both candidates.
**Neither candidate gives `dnb_app` any additional privilege.**

## 8. Connection pooling implications

- The credential travels per transaction as `SET LOCAL` / `set_config(..., true)`, exactly as the
  identifier does now. Measured: cleared by `COMMIT`, cleared by a failed transaction, and a
  released connection with `RESET` sees nothing.
- **Staleness is no longer a cross-customer risk under B.** A leftover identifier could name
  another customer; a leftover *session key* still belongs to the customer it was issued to, and
  a revoked or expired one resolves to NULL and fails closed.
- Cost is per statement, not per row: `pg_stat_user_functions` recorded **2 calls for a 5001-row
  scan** and **6 across a 3-statement transaction**. Wall time over 200 statements: 0.145s (A) vs
  0.149s (B) — about 3%.

## 9. Secret-management implications

| | B | F |
|---|---|---|
| New secret stored in the database | **none** | a 32-byte context key |
| What a database dump yields | session **hashes** — which are the lookup key, so a dump does permit impersonation until sessions expire | the context key, which permits **impersonating any customer indefinitely** until rotated |
| Rotation | inherent — sessions expire and are revocable | must be designed; every app instance must rotate in step |
| The auth pepper | **unchanged and still absent from the database** | unchanged |

Neither candidate puts `DNB_TOKEN_PEPPER` into SQL. That property is preserved.

**One new exposure under B**, stated plainly: the session key would travel in a `set_config` call.
With `log_statement = all` it would land in the log. The mitigation is to pass it as a **bound
parameter** rather than interpolating it — parameters are logged only when
`log_min_duration_statement` is combined with `log_parameter_max_length >= 0`, which is off by
default. This must be an explicit deployment requirement, not an assumption.

## 10. Recommended architecture — **B**, not implemented

B is recommended, on four grounds:

1. **It adds no secret to the database.** F's key is a single value whose compromise is permanent
   and silent; B's credential is per-session, expiring and revocable.
2. **Revocation is immediate**, measured. F has none without further design.
3. **Cost is per statement and small** — 3% over 200 statements, and independent of row count.
4. **Staleness stops being a cross-customer failure mode**, because the leftover value names the
   customer it was always issued to.

F remains a legitimate fallback if a per-statement lookup proves unacceptable under real load, and
it is the better answer for any actor that has no session at all.

The trust boundary moves from

> PHP promises the GUC is truthful

to

> establishing customer Q's context requires a live credential of Q's, which a request-role session
> cannot read, compute, or guess.

**It does not become "the database knows who is calling."** It becomes "the database requires
something the caller can only have if it is genuinely acting for that customer." That is the
honest description, and it is a materially different and better place for the boundary — not a
perfect one.

## 11. What implementing B would require

**Migration** (one new migration; nothing existing is dropped):

1. `mt_current_customer()` → SECURITY DEFINER, owned by `dnb_def_auth` (which already holds
   `SELECT` on `mt_auth_sessions`), with a **`session_user`** branch — not `current_user`.
2. Resolve `app.session_key` against `mt_auth_sessions` where `revoked_at IS NULL` and
   `expires_at > now()`.
3. **Break the RLS recursion.** `mt_auth_sessions` has an all-roles policy calling
   `mt_current_customer()`, which would now read `mt_auth_sessions`. Measured: this **errors**.
   The fix, also measured, is to exempt the definer role — scope the tenant policy to
   `dnb_app, dnb_worker, dnb_admin` and give the definer role its own `USING (true)` SELECT policy.
4. An index on `mt_auth_sessions (token_hash)` — it is already the primary key.

**Application:**

5. `TenantContext` gains a session-credential entry point for the request path; `run($customerId)`
   stays for worker and admin.
6. `Kernel` passes the token hash it already computes, instead of `$who['customer_id']`.
7. `Authenticator::resolve()` returns the hash alongside the pair, or the Kernel recomputes it.
8. The credential is passed as a **bound parameter**.

**Tests:** the whole attack matrix of §5 as a suite; the existing A1–A5, F1, F2 and F4 probes must
remain byte-identical and still pass; and a negative test that deliberately writes the
`current_user` branch and proves the suite goes red — because that bug is invisible otherwise.

**Unchanged:** the four planes, F1–F13, C20, the customer API contract, every existing RLS policy
shape, and all worker/admin/RADIUS behaviour.

## 12. Unresolved questions

1. **Sessions are not the only context.** The Customer PWA has one, but a future Admin API acting
   *on behalf of* a customer would have no session for that customer. Which branch it takes is
   undecided and materially affects the Admin API's design — this is the question I would resolve
   before writing a line of it.
2. **Load is untested.** 3% on 200 trivial statements against 5001 rows on a local socket is not a
   production measurement.
3. **`log_statement = all` becomes a credential leak.** Needs to be a deployment gate, alongside
   the role-password gate.
4. **F's expiry and nonce were not designed.** If F is chosen, it is not yet a complete design.
5. **Whether B closes F9 or only narrows it.** It does not make the GUC unforgeable — nothing can.
   It makes forging require a secret. Whether that is *sufficient* is the decision being asked for,
   and it is a judgement, not a measurement.
6. **F5, F6 and F8 remain open and untouched**, as instructed. F8 in particular — telemetry
   writable by the customer it describes — is unaffected by any of this, because it is a *grant*
   problem, not a context problem.

## Classification

| Candidate | Status |
|---|---|
| A — identifier in a GUC | **REJECTED** — proven vulnerable, `f116234` |
| B — session secret resolved by RLS | **PROTOTYPED** — resists every attack in §5; recommended |
| C — role per customer | **REJECTED** — prototyped and broken under pooling |
| D — protected GUC set by a definer | **REJECTED** — custom GUCs cannot be restricted; measured |
| E — asymmetric signed context | **REJECTED** — no signature verification in pgcrypto |
| F — identifier + HMAC | **PROTOTYPED** — resists every attack; unrevocable as prototyped |
| Production implementation of any of them | **UNPROVEN** — nothing has been built |
