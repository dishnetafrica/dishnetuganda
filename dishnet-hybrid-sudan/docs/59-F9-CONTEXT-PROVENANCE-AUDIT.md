# 59 — F9 and the Remaining Findings: a Hostile Audit of Context Provenance

Scope: `bf5310b`. Read-only. No application code, migration or test was changed. Every claim
below was produced by execution against a database built from migrations 001–018, or by reading
the code path it describes.

The question the brief put, and the one this report answers:

> Can a request authenticated as P cause the database connection used for that request to execute
> with `app.customer_id = Q`?

**At the HTTP boundary: no, by construction and by test. At the database boundary: yes, trivially,
and the database cannot tell the difference.** Those are two different statements and the gap
between them is F9.

---

## 1. F9 — context-provenance attack

All as `dnb_app`, the role that serves customer requests.

| # | Attack | Result | Class |
|---|---|---|---|
| 1 | Establish context as P, then `set_config('app.customer_id', Q, true)` in the same transaction | **READ Q's private row** | PROVEN VULNERABLE |
| 2 | Same, using a literal `SET LOCAL app.customer_id = '<Q>'` statement | **READ Q's private row** | PROVEN VULNERABLE |
| 3 | Ask the database how the value was set | only a value; **no provenance is recorded anywhere** | PROVEN VULNERABLE |
| 4 | No context set at all | nothing visible | PROVEN SECURE |
| 5 | `'not-a-uuid'` | `ERROR: invalid input syntax for type uuid` | PROVEN SECURE |
| 6 | `"', true); DROP TABLE mt_sites; --"` | same error — it is a parameter, never concatenated | PROVEN SECURE |
| 7 | Empty string | accepted, **0 rows** — `NULLIF(...,'')::uuid` is NULL, and `= NULL` is never true | PROVEN SECURE |

Attack 1 and 2 are the finding. Nothing in PostgreSQL distinguishes a `app.customer_id` that the
Kernel derived from a verified token from one an attacker typed: it is a custom GUC, settable by
any session, with no owner, no signature and no audit. `mt_current_customer()` — which every RLS
policy calls — is `SELECT NULLIF(current_setting('app.customer_id', true), '')::uuid`, and it
returns what it is told.

`TenantContext::run()` does validate the id is a uuid, but that check lives **in PHP**. It
constrains the one code path that calls it; it constrains nothing that reaches the connection by
another route.

**So RLS here enforces an identity it cannot authenticate.** The policies are correct. What they
are correct *about* is supplied by the caller.

## 2. The chain: credential → principal → customer → DB context → RLS

| Transition | Mechanism | Trust class |
|---|---|---|
| bearer token → `token_hash` | `hash_hmac('sha256', token, DNB_TOKEN_PEPPER)` | **cryptographic**; the pepper is an application secret, never stored in the database |
| `token_hash` → (principal, customer) | `mt_auth_resolve_token`, SECURITY DEFINER, checks `revoked_at`, `expires_at`, `status='active'` | **database-derived** |
| (principal, customer) → `$who` | `Kernel::handle` | application-derived, no caller influence |
| `$who['customer_id']` → `app.customer_id` | `TenantContext::run`, `SET LOCAL` | **application-asserted — the weak link** |
| `app.customer_id` → row visibility | 18 RLS policies, `USING` + `WITH CHECK`, FORCE | **database-enforced** |

The chain is cryptographic at one end and database-enforced at the other, with an unverifiable
assertion in the middle. That middle step is F9, and it is why "the first token is verified" does
not make the chain secure.

Confirmed by reading every writer of the GUC: `TenantContext` is the only one in `src/` or `bin/`;
`runUnscoped()` has **no production callers**; and no route handler is given the `TenantContext`
object, so no handler can open a context of its own choosing — a handler that tried would also
fail on PDO's nested-transaction rule. The exposure is therefore not "a handler might pass the
wrong id"; it is **any SQL execution on the request connection**.

## 3. Connection reuse and stale context

| Test | Result | Class |
|---|---|---|
| `SET LOCAL` surviving `COMMIT` | cleared | PROVEN SECURE |
| `SET LOCAL` surviving a **failed** transaction + rollback | cleared | PROVEN SECURE |
| Three sequential HTTP requests P → Q → P on **one connection** | `SECRET-CustP`, `SECRET-CustQ`, `SECRET-CustP` | PROVEN SECURE |
| Context on the connection after those requests | cleared | PROVEN SECURE |
| A revoked token after logout | `401` — no context established | PROVEN SECURE |
| Plain `SET` (no `LOCAL`) | **persists, and Q becomes readable outside any transaction** | PROVEN VULNERABLE *as a mechanism* |

The last row is not a defect in the code — `TenantContext` uses `SET LOCAL` exclusively and a
guard test forbids `SET` without `LOCAL`. It is recorded because it is the exact mechanism by
which a pooled connection would leak a tenant, and because one guard is all that stands between
the codebase and it.

**No caller-controlled route exists through HTTP.** A `customer_id` in the body of
`POST /api/v1/me/plans` was ignored and the plan was created for **CustP**; the same value in a
query string and in a path segment produced `404`; `/api/v1/me` derives from the token alone.

## 4. F3 — object-reference attack (with known Q ids)

| Write, as P, naming Q's object | Result |
|---|---|
| config row on **Q's device**, labelled P | ALLOWED |
| uplink sample on **Q's device**, labelled P | ALLOWED |
| voucher against **Q's plan**, labelled P | ALLOWED |
| site under **Q's service**, labelled P | ALLOWED |
| auth session for **Q's principal**, labelled P | ALLOWED |
| secret row on **Q's device**, labelled P | denied here only by `UNIQUE(device_id)` — see below |
| any row labelled **Q** | DENIED `42501`, RLS `WITH CHECK` |

`WITH CHECK (customer_id = mt_current_customer())` constrains one column and says nothing about
the rows a new row points at. Every such row is labelled P and visible only to P, so this is
**pollution of the attacker's own view and referential corruption**, not cross-customer
disclosure.

**A correction to docs/58 §4 F3.** That report claimed device-secret squatting was a
cross-customer *denial of provisioning* — that P could occupy the `UNIQUE(device_id)` secret slot
of Q's uncredentialed device and prevent the admin from ever credentialing it. **That is wrong.**
Tested directly: P plants the row, and `mt_device_set_secret` then succeeds, because its upsert
sets `customer_id = EXCLUDED.customer_id` from `mt_devices` — the row is re-labelled to its true
owner. The squat is transient and self-healing, and the planted value is P's own junk, never a
credential. The finding stands only as referential corruption.

The one consequence worth keeping: a voucher of P's referencing **Q's plan** takes its enforcement
profile from Q's plan, and makes Q's plan un-retirable without dangling references.

**PROVEN VULNERABLE**, severity LOW–MEDIUM (was MEDIUM).

## 5. F5 — future objects on the production migration path

My first attempt created the probe objects as the *inspector* and they came out denied to every
role. That was an artifact: default privileges belong to the creating role, and the production
creator is the owner. Re-run **as the owner**:

```
new table relacl : {dnb=arwdDxt/dnb, dnb_app=arwd/dnb, dnb_worker=arwd/dnb, dnb_admin=arwd/dnb}
new table RLS    : false
new function     : proacl NULL = built-in default = PUBLIC EXECUTE

  as dnb_app      SELECT 1 row(s) — another customer's row / INSERT ok / EXECUTE ok
  as dnb_worker   SELECT 1 row(s) / INSERT ok / EXECUTE ok
  as dnb_admin    SELECT 1 row(s) / INSERT ok / EXECUTE ok
  as dnb_radius   denied / denied / EXECUTE ok
```

A table added by a future migration is immediately readable **and writable** by the request role,
across customers, with RLS off. `dnb_radius` is excluded only because it was created after the
`ALTER DEFAULT PRIVILEGES` and never granted.

Half of this is now guarded: F4's catalogue-driven check reports RLS-off on any new
customer-scoped table. The other halves are not — **the default table grants** (nothing asserts
they are narrowed) and **the function's PUBLIC EXECUTE** (caught only when a migration remembers
to call `mt_revoke_public_execute()`).

**PROVEN VULNERABLE (latent)**, MEDIUM. Unchanged from docs/58, now confirmed on the real path.

## 6. F6 — voucher redemption

`voucher → hotspot user → customer` was traced. `mt_voucher_redeem` matches on `code` alone,
ignores tenant context entirely, and returns `customer_id`.

```
P redeems Q's voucher code (state unused ->)   active
  and learns which customer owns it            YES — Q's uuid returned
```

A request-role caller can burn any voucher in the fleet whose code it knows, and learns the owning
tenant's uuid. The code is a bearer credential by design — a guest types it at a portal — and the
keyspace (~1.1e15) makes enumeration impractical, which is the only thing limiting it. What is not
obviously intended is that it is reachable from a *customer-authenticated* path, where burning
another customer's voucher is pure vandalism, and that it discloses a tenant identifier.

**PROVEN VULNERABLE**, MEDIUM. Unchanged.

## 7. F7 — token / principal association

The resolver never asserts `mt_principals.customer_id = mt_auth_sessions.customer_id`, and P can
insert a session row naming **Q's principal** (F3). So the association is forgeable. But the
attack does not reach a usable session:

| Step | Result |
|---|---|
| P plants a session for a token it invents, hashed without the pepper | `401` — the server hashes with `DNB_TOKEN_PEPPER`, which P does not hold |
| P plants one hashed **with** the pepper (worst case) | `404` — `/api/v1/me` looks up the principal **inside P's tenant context**, and Q's principal is invisible |
| Tenant of a forged session | **P, its own** — `customer_id` comes from the session row and `WITH CHECK` pins it to P |
| P plants a session labelled **Q** | DENIED `42501` |
| P repoints its own principal at Q | DENIED `42501` |

Two independent defences: an application-side secret that is never stored in the database, and the
tenant context making the foreign principal unreadable. `principal_id` is used only for audit and
intent attribution and for `/me`; it is never an authorization input.

**PROVEN VULNERABLE**, downgraded to **LOW** — forgeable attribution, not forgeable access.
docs/58 §4 F7 rated this MEDIUM on the strength of the mismatch alone; measuring the exploit path
does not support that.

## 8. F8 — telemetry write authority

| Identity | Direct INSERT into `mt_uplink_samples` |
|---|---|
| `dnb_app` (request role) | **ALLOWED** |
| `dnb_worker` | ALLOWED (legitimate — the sampler writes them) |
| `dnb_admin` | ALLOWED |
| `dnb_radius` | denied |

```
P writes a fabricated sample for its OWN device   ALLOWED (987654321 bps)
  visible to P as its own telemetry               1 row
P writes a sample LABELLED Q                      DENIED — RLS WITH CHECK
```

A customer can author the evidence that exists to adjudicate disputes with that customer (C17), so
R4's careful work on *not fabricating* telemetry is undermined by the write path, not by the
sampler. Cross-customer fabrication is refused.

**PROVEN VULNERABLE**, MEDIUM. Unchanged.

## 9. Actor / capability / scope matrix

Crossing customers is not itself a defect; it is a capability that must be deliberate, and held by
an identity whose exposure justifies it.

| Actor | Capability | Intended scope | Enforcement | Verdict |
|---|---|---|---|---|
| `dnb_app` — customer request | read/write own tenant | one customer per transaction | RLS + `SET LOCAL` | PROVEN SECURE for reads/writes; **context is self-asserted (F9)** |
| `dnb_app` | redeem a voucher by code | any code presented | none — bearer semantics | F6 |
| `dnb_app` | write telemetry for own tenant | own devices | RLS `WITH CHECK` only | F8 |
| `dnb_radius` — RADIUS ingestion | submit accounting for a username | whole fleet, by design | EXECUTE on one function; **no table privileges at all** | PROVEN SECURE |
| `dnb_worker` — background | claim intents, reap, prune, sample | whole fleet, by design | EXECUTE on 6 functions; per-table policies on `dnb_def_work` | PROVEN SECURE |
| `dnb_admin` — provisioning | register/assign/credential devices, create customers | whole fleet, by design | EXECUTE on 7 functions; itself subject to RLS | PROVEN SECURE |
| `dnb_def_*` — function owners | exactly their own trust context | per-table, per-command | explicit `pg_policy` rows | PROVEN SECURE |
| `dnb` — owner/maintenance | DDL | migrations | not superuser, not BYPASSRLS, reads no tenant data | PROVEN SECURE |

## 10. Regression results

| Probe | Result |
|---|---|
| S1/S2 A1–A5 (**byte-identical** to `tools/audit/s1_s2_probe.php`) | A1 no rows · A2 null · A3 `42501` · A4 `42501` then null · A5 works |
| F1 accounting injection | `dnb_app`/`dnb_worker`/`dnb_admin` denied; only `dnb_radius` accepted |
| F2 non-superuser owner | `dnb: super=false bypassrls=false`; full suite green |
| F4 RLS guard | 19/19 enumerated, negative test still proves the guard fails when it should |
| Full suite | 18 suites, **718 assertions, green** |

## 11. Findings ranked by actual exploitability

| Rank | Finding | Reachable today by | Class | Severity |
|---|---|---|---|---|
| 1 | **F9** — tenant context is self-asserted; any SQL on the request connection reaches `set_config` and the database records no provenance | anyone who can execute SQL as `dnb_app` — i.e. **any injection in any future endpoint** | PROVEN VULNERABLE | **HIGH** |
| 2 | F5 — new tables arrive granted to all roles with RLS off; new functions PUBLIC-executable | a future migration author, silently | PROVEN VULNERABLE (latent) | MEDIUM |
| 3 | F8 — a customer can fabricate its own telemetry | the request role | PROVEN VULNERABLE | MEDIUM |
| 4 | F6 — any known voucher code can be burned; redemption returns a tenant uuid | the request role, needs a code | PROVEN VULNERABLE | MEDIUM |
| 5 | F3 — cross-object references; no parent-ownership check | the request role, needs known ids | PROVEN VULNERABLE | LOW–MEDIUM (downgraded) |
| 6 | F7 — principal/customer mismatch accepted by the resolver | needs the app-side pepper **and** still 404s | PROVEN VULNERABLE | LOW (downgraded) |
| — | HTTP-layer context derivation; connection reuse; logout | — | PROVEN SECURE | — |
| — | Worker, RADIUS, admin and definer boundaries | — | PROVEN SECURE | — |
| — | Physical MikroTik: R1, R5, R6, R7, R2/R3 bisections | — | PHYSICAL VERIFICATION REQUIRED | — |
| — | Admin API, PWA wiring, deployment | — | NOT IMPLEMENTED | — |

### Two observations, not findings

- `TenantContext::runUnscoped()` is `public` and has no production callers. It is a method whose
  entire purpose is to run without tenancy; nothing prevents a future handler from reaching it.
- `Database::inspector()` is a superuser accessor that lives in `src/`. A test asserts nothing in
  `src/` or `bin/` calls it, which is the right guard, but the method is still there to be called.

## 12. What remains unproven

- **Whether F9 is closable at all in PostgreSQL.** A GUC cannot be made unforgeable. The shape that
  would move the trust boundary into the database — having RLS resolve the tenant from a *session
  token* the application sets, so that claiming another tenant requires that tenant's token rather
  than its uuid — was **not designed, prototyped or tested** in this audit. It is an assertion about
  what might work, and it is recorded as exactly that.
- **Whether the pepper survives deployment.** F7's downgrade rests on `DNB_TOKEN_PEPPER` being
  present, secret, and not equal to its development default. Nothing tests that today.
- **Whether any future endpoint introduces SQL injection.** F9's severity is entirely a function of
  this, and no endpoint beyond the current read-mostly surface exists to examine.
- **Everything physical.** Unchanged: R1, R5 (fails loudly), R6 (**fails silently if wrong**), R7,
  and the R2/R3 bisections.
- **Deployment.** Role passwords are still development literals, `dnb_radius` included.

**The Admin API remains gated.** F9 is the reason: it converts any single injection anywhere in the
new HTTP surface into a whole-fleet compromise, and the database will cooperate fully because it
cannot tell that anything is wrong.
