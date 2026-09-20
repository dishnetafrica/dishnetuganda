# 58 — Hostile Re-Audit of the Authorization and Database Enforcement Model

Scope: the effective PostgreSQL behaviour of the control plane at `01acb1a`. Nothing was fixed.
No application code, migration, or test was changed. Every claim below was produced by execution
or catalogue inspection against a database built from the migrations, not by reading migration
text or test names.

**Working assumption, per the brief: there is another silent enforcement failure until disproven.**
There were three.

## 0. Method and its limits

A fresh database was migrated (001–016), two customers P and Q were given complete object graphs
as the owner, and every boundary was then attacked as `dnb_app`, `dnb_worker` and `dnb_admin`
over real connections. Probes are in `tools/audit/` and are read-only with respect to the
application.

Two things this audit cannot establish:

- **Privilege vs. visibility.** `has_table_privilege` answers what was granted; it says nothing
  about RLS, which is a row filter rather than a privilege. Both are reported, separately.
- **Anything about hardware.** Unchanged from docs/57 §12.4–12.5.

One environmental caveat, stated because it affects a finding: the cluster used here was rebuilt
mid-session and its owner role `dnb` was created as SUPERUSER. Rather than assume that matched
production, it was tested both ways — see F2, which is a finding precisely because the answer
differs.

---

## 1. Effective privilege matrix

Owner of every object is `dnb`. No table grants anything to `PUBLIC`.

| Table | RLS | FORCE | Policies | dnb_app | dnb_worker | dnb_admin |
|---|---|---|---|---|---|---|
| mt_audit_log | Y | Y | 1 | SIUD | SIUD | SIUD |
| **mt_auth_codes** | **Y** | **Y** | **0** | **none** | SIUD | SIUD |
| mt_auth_sessions | Y | Y | 1 | SIUD | SIUD | SIUD |
| mt_customers | Y | Y | 1 | SIUD | SIUD | SIUD |
| mt_device_config | Y | Y | 1 | SIU | SIUD | SIUD |
| mt_device_secrets | Y | Y | 1 | SIU | SIUD | SIUD |
| mt_devices | Y | Y | 1 | SIUD | SIUD | SIUD |
| mt_entitlements | Y | Y | 1 | SIUD | SIUD | SIUD |
| mt_hotspot_users | Y | Y | 1 | SIUD | SIUD | SIUD |
| mt_idempotency | Y | Y | 1 | SIUD | SIUD | SIUD |
| mt_intents | Y | Y | 1 | SIUD | SIUD | SIUD |
| **mt_migrations** | **n** | **n** | 0 | SIU | SIU | SIU |
| mt_plans | Y | Y | 1 | SIUD | SIUD | SIUD |
| mt_principals | Y | Y | 1 | SIUD | SIUD | SIUD |
| **mt_profiles** | **n** | **n** | 0 | SI | SIUD | SIUD |
| mt_services | Y | Y | 1 | SIUD | SIUD | SIUD |
| mt_sessions | Y | Y | 1 | SIUD | SIUD | SIUD |
| mt_sites | Y | Y | 1 | SIUD | SIUD | SIUD |
| mt_uplink_samples | Y | Y | 1 | SIUD | SIUD | SIUD |
| mt_voucher_batches | Y | Y | 1 | SIUD | SIUD | SIUD |
| mt_vouchers | Y | Y | 1 | SIUD | SIUD | SIUD |

`TRUNCATE`: owner only. `DELETE` on `mt_migrations`: nobody (finding S5, holding).

**Role attributes, verified:** `dnb_app`, `dnb_worker`, `dnb_admin` are all
`NOSUPERUSER NOBYPASSRLS NOCREATEDB NOCREATEROLE`, none is a member of another, and none holds
`CREATE` on any schema — `CREATE TABLE` and `CREATE FUNCTION` are denied to all three by
execution. That closes `search_path` shadowing, which matters because two invoker functions do
not pin it (§2).

`dnb` is the owner and, in any deployment that works today, a superuser (F2).

---

## 2. SECURITY DEFINER inventory

**Twenty** SECURITY DEFINER functions, all owned by `dnb`, **all with `search_path` pinned to
`public, pg_temp`**. No unpinned DEFINER function exists.

| Function | EXECUTE | Derives customer from | Caller-controlled id? |
|---|---|---|---|
| mt_auth_issue_code | dnb_app | phone lookup | phone |
| mt_auth_verify_code | dnb_app | phone lookup | phone |
| mt_auth_create_session | dnb_app | **caller argument** | principal + customer |
| mt_auth_resolve_token | dnb_app | **session row** | token hash |
| mt_auth_revoke_token | dnb_app | token lookup | token hash |
| mt_voucher_redeem | dnb_app | **nothing — ignores context** | code |
| mt_session_account | dnb_app | `mt_hotspot_users` lookup | username |
| mt_intent_claim / _expire_overdue | dnb_worker | n/a (cross-customer by design) | — |
| mt_sessions_reap / mt_uplink_prune | dnb_worker | n/a | — |
| mt_uplink_record | dnb_worker | device row | device id |
| mt_devices_samplable | dnb_worker | n/a | — |
| mt_device_register / _assign / _set_state / _set_secret / _set_desired / _set_wan | dnb_admin | caller argument | device id |
| mt_revoke_public_execute | owner only | n/a | — |

Two SECURITY **INVOKER** functions do not pin `search_path`: `mt_audit_append_only()` (an
unconditional `RAISE`, resolving no names) and `mt_current_customer()` (`current_setting` and
`NULLIF`, both `pg_catalog`, plus a `::uuid` cast). Neither is exploitable **because no app role
can create objects**. Both are `PROVEN SECURE` conditional on that fact, which is itself a
privilege that a future migration could grant away without any test noticing.

---

## 3. Customer-isolation attack matrix

As `dnb_app`, in P's context, against every customer-scoped table. `rows=0` means RLS returned
nothing; `DENIED` means the statement was refused outright.

| Table | P reads Q | P updates Q | P deletes Q | P inserts as Q | P on its own |
|---|---|---|---|---|---|
| all 19 customer-scoped tables | rows=0 | rows=0 | rows=0 / DENIED | **DENIED 42501** | works |
| mt_auth_codes | DENIED (no grant) | DENIED | DENIED | DENIED | DENIED |
| mt_profiles | **rows=2 (both customers')** | — | — | — | unscoped by design |
| mt_migrations | rows=16 | — | — | — | unscoped by design |

Every INSERT denial was confirmed to be the RLS `WITH CHECK` (SQLSTATE 42501, *"new row violates
row-level security policy"*) using fully valid rows — not a `NOT NULL` violation masking an
absent check. Handing one's own row to Q via `UPDATE ... SET customer_id = Q` is refused the same
way.

**All 18 policies are identical in shape**: `FOR ALL`, to all roles, with both `USING` and
`WITH CHECK` on `customer_id = mt_current_customer()` (`mt_customers` uses `id`). No policy is
`USING`-only, so no table permits a write it would not permit a read of.

**Direct cross-customer read, write and delete are PROVEN SECURE.** What follows is what that
statement does not cover.

---

## 4. Findings

### F1 — Cross-customer accounting injection · PROVEN VULNERABLE · HIGH

`mt_session_account` resolves the owning customer from `mt_hotspot_users.radius_username`, which
is the correct design — the caller does not supply the tenant. The consequence is that a caller
who knows **another customer's** RADIUS username writes into **that customer's** rows:

```
  injected session id returned to P            YES
  Q's session rows before / after              1 / 2
  the new row is owned by                      Q   <-- cross-customer WRITE
  fabricated byte counters                     in=9999999 out=8888888
  P can read back the row it planted           no (write-only blind injection)
```

P cannot read Q's sessions, so this is a blind write, which makes it *harder to notice*, not less
serious. Byte counters use `GREATEST`, deliberately, so that a reordered RADIUS retransmit cannot
shrink them — which means **an inflated counter can never be corrected downward by the ingest
path**. Sessions are what a customer is shown as "who used my hotspot and how much", and byte
totals are evidence in the support-boundary conversation C17 exists for.

docs/57 §10.6 predicted this in one sentence and accepted it. This audit's contribution is the
demonstration that the write lands in the victim's rows and is irreversible.

Root cause is not the function. It is that **the role serving customer HTTP requests also holds
the RADIUS ingestion privilege.** They are different trust contexts sharing one identity — the
same shape as S2, one layer up.

### F2 — The system requires a SUPERUSER database owner · PROVEN VULNERABLE · HIGH

Tested both ways, on freshly migrated databases:

| Operation | Owner is SUPERUSER | Owner is not |
|---|---|---|
| Migrations 001–016 | apply | **apply** |
| `mt_auth_issue_code(...)` (login, step 1) | returns an id | **ERROR: new row violates RLS policy for `mt_auth_codes`** |
| `INSERT INTO mt_customers` (onboarding) | succeeds | **ERROR: new row violates RLS policy for `mt_customers`** |

Two independent causes, both structural:

- `mt_auth_codes` has RLS enabled **and forced** with **zero policies**. Forced RLS applies to the
  owner, so with no policy the table is readable and writable by nobody — except a role that
  bypasses RLS entirely. The DEFINER functions that use it run as `dnb`, so they work only while
  `dnb` is superuser.
- `mt_customers`' policy is `id = mt_current_customer()`. A customer that does not exist yet
  cannot be the current customer, so **there is no context in which a new customer can be
  inserted**, and no `mt_customer_create()` admin function exists (§7).

Consequences, in order of importance:

1. **Every one of the 20 SECURITY DEFINER functions executes with superuser rights.** A logic
   flaw or injection inside any of them is not contained by the role model. The login path in
   particular runs as superuser on every request.
2. docs/57 §11.4 says the owner "is not an application identity". That is **false as measured**:
   owner privilege is on the runtime path for login.
3. A deployment hardened in the ordinary way — non-superuser owner — **breaks login**, and breaks
   it at first use rather than at deploy time.

### F3 — RLS validates the row's own `customer_id`, never its parents · PROVEN VULNERABLE · MEDIUM

`WITH CHECK (customer_id = mt_current_customer())` constrains one column. It does not constrain
the rows a new row *points at*. As P, with ids known (the stated threat model):

| Write | Result |
|---|---|
| secret row on **Q's device**, labelled P | **ALLOWED** |
| config row on **Q's device**, labelled P | **ALLOWED** |
| uplink sample on **Q's device**, labelled P | **ALLOWED** |
| voucher against **Q's plan**, in **Q's batch**, labelled P | **ALLOWED** |
| site under **Q's service**, labelled P | **ALLOWED** |
| auth session for **Q's principal**, labelled P | **ALLOWED** |
| intent naming **Q's device** in its payload, labelled P | **ALLOWED** |
| hotspot user on Q's voucher / entitlement on Q's service | denied — **by a unique constraint, not by any rule** |

The last row is the important one: two of these were stopped by an accident of schema, not by a
security decision. The rule everywhere else is simply absent.

Most consequences are containable because the reading side is still tenant-scoped. One is not:
`mt_device_secrets` is `UNIQUE (device_id)`, so **P can occupy the secret slot of a device
belonging to Q**, after which `mt_device_set_secret` for the rightful owner fails on the unique
constraint — a cross-customer denial of provisioning, from the request role, with no read access
required.

### F4 — The RLS coverage guard checks 8 of 19 tables · TESTED BUT INCOMPLETE · HIGH (process)

`tests/test_rls_isolation.php` has a test titled *"every customer-scoped table has RLS enabled AND
forced"*. It iterates a hand-written list of four tables plus four more named inline — **eight**.
Nineteen tables carry `customer_id`. The eleven not checked include `mt_sessions`, `mt_vouchers`,
`mt_devices`, `mt_device_secrets`, `mt_intents` and `mt_uplink_samples`.

All nineteen are correct today, verified from the catalogue, so this is not presently a hole. It
is a guard that does not guard what its name claims, in a codebase where that exact defect has now
occurred four times (G4 seeder list, the ALTER DEFAULT PRIVILEGES claim, the `proacl IS NULL`
blind spot, and this).

### F5 — A new table arrives fully granted and unprotected · PROVEN VULNERABLE (latent) · MEDIUM

Created as the owner, then tested by execution:

```
  new table relacl = {dnb=arwdDxt/dnb, dnb_app=arwd/dnb, dnb_worker=arwd/dnb, dnb_admin=arwd/dnb}
  dnb_app SELECT on the new table: 1 row     (RLS: not enabled)
```

`ALTER DEFAULT PRIVILEGES ... GRANT ... ON TABLES` in migration 015 means every future table is
immediately readable and writable by all three application roles, while RLS defaults to off. A
future migration that adds a customer-scoped table and forgets `ENABLE ROW LEVEL SECURITY` ships a
fleet-wide readable table, and — per F4 — no test fails.

The mirror-image function case (`proacl IS NULL` = PUBLIC EXECUTE) was re-confirmed the same way,
and **is** caught: the guard added in `016` flagged the probe function. Functions are guarded;
tables are not.

### F6 — `mt_voucher_redeem` ignores tenant context and returns a tenant id · PROVEN VULNERABLE · MEDIUM

The function matches on `code` alone and returns `customer_id`. Any `dnb_app` caller can redeem
any code in the fleet and learn which customer owns it. Bearer semantics are intended — the code
*is* the credential at a captive portal — but two properties are not obviously intended: it is
callable from a *customer-authenticated* path where burning another customer's voucher is pure
vandalism, and it discloses a tenant identifier to whoever redeems. The keyspace (~1.1e15) makes
blind enumeration impractical, which is the only thing limiting it.

### F7 — `mt_auth_resolve_token` does not check principal against customer · PROVEN VULNERABLE · MEDIUM

The resolver joins `mt_principals` only to test `status = 'active'`; it never asserts
`p.customer_id = s.customer_id`. Combined with F3, P can mint a session row carrying **Q's
principal id** under P's own customer id, and it resolves:

```
  resolve_token(forged) returns rows            1
    -> principal_id is Q's principal            YES  <-- mismatch accepted
    -> customer_id returned                     P (tenant stays P)
```

No data crosses, because the tenant comes from the session row's `customer_id` and the `WITH
CHECK` pins that to P. The damage is attribution: actions and audit entries can be made to name a
principal belonging to another customer. It is also one schema change away from being worse.

### F8 — Telemetry is writable by the customer it describes · PROVEN VULNERABLE · MEDIUM

`dnb_app` holds `INSERT` on `mt_uplink_samples`, and the `WITH CHECK` passes for any row labelled
with the caller's own customer id (device id unconstrained, per F3). The entire point of R4 was
that uplink samples are *DishNet's evidence* in a support dispute. A customer that can author its
own evidence defeats that purpose, and no amount of correctness in the sampler changes it.

### F9 — Tenant context is self-asserted · PROVEN SECURE as built · read this before the Admin API

`dnb_app` may `SET LOCAL app.customer_id` to any value it likes, and the database will honour it:

```
  app sets context to Q by itself and reads Q   YES 1 rows
```

This is not a defect — the database cannot know which customer an HTTP request belonged to. It is
the load-bearing assumption of the whole model: **isolation holds exactly as long as the
application derives the customer id from the verified token and never from input.** One SQL
injection that reaches `SET LOCAL` yields the entire fleet, and RLS will cooperate fully.

That is the single most important sentence in this report for the work that comes next.

---

## 5. Context-switch and connection-reuse results — PROVEN SECURE

| Attack | Result |
|---|---|
| `SET LOCAL` surviving `COMMIT` | cleared |
| `SET LOCAL` surviving a **failed** transaction + `ROLLBACK` | cleared |
| No context set at all | **0 rows** — fails closed |
| Plain `SET` (no `LOCAL`) persisting on the connection | **persists** — see note |
| Worker connection carrying a tenant context | none |
| Worker unscoped read of `mt_customers` | 0 rows — fails closed |

The plain-`SET` row is not a finding against the code: `TenantContext` uses `SET LOCAL`
exclusively, and a guard test already forbids `SET` without `LOCAL`. It is recorded because it is
the exact mechanism by which a pooled connection would leak a tenant, and because a future
author's `SET` would be caught only by that one guard.

## 6. Worker boundary — PROVEN SECURE

`dnb_app ≠ dnb_worker` in effective privilege, not only in class names:

- request role calling `mt_intent_claim` → **42501**, and the same for `expire_overdue`,
  `sessions_reap`, `uplink_prune`, `devices_samplable`;
- worker claimed **2 intents across 2 customers** — the capability exists where it should;
- worker connection carries no tenant context between jobs, and an unscoped read returns nothing,
  so a job cannot inherit its predecessor's customer;
- delivery runs on the connection the context was established on (the S1 fix), verified by the
  existing suite.

## 7. Admin boundary — PROVEN SECURE, and narrower than expected

`dnb_admin` is **subject to RLS like everyone else** — unscoped reads of `mt_customers`,
`mt_device_secrets` and `mt_vouchers` all return 0 rows. Its power is exactly six SECURITY DEFINER
functions and nothing more:

`mt_device_register`, `mt_device_assign`, `mt_device_set_state`, `mt_device_set_secret`,
`mt_device_set_desired`, `mt_device_set_wan`.

It cannot call worker primitives (42501), and the request role cannot call its (42501). This is an
explicit administrative capability, not an accidental consequence of ownership — which is what the
brief asked to confirm.

**NOT IMPLEMENTED:** there is no administrative path to create a customer. `mt_customers` cannot be
inserted into under any tenant context (F2), and no `mt_customer_create()` exists. Onboarding today
is possible only as a superuser, by hand.

## 8. RLS audit

- 19 of 21 `mt_*` tables have RLS **enabled and forced**; `mt_profiles` and `mt_migrations` do not
  and are unscoped by design.
- 18 have exactly one `FOR ALL` policy with both `USING` and `WITH CHECK`. No `USING`-only policy
  exists, so no table allows a write it would not allow a read of.
- **`mt_auth_codes` has RLS forced with zero policies** — deny-all, functioning only via superuser
  bypass (F2).
- Every policy expression uses `mt_current_customer()`, which reads session state the application
  sets; see F9 for what that does and does not guarantee.
- No policy permits cross-customer access; proven by execution across all 19 tables (§3).
- The owner *does* bypass in production as configured (F2), which is the opposite of the
  containment docs/57 §11.4 claimed.
- SECURITY DEFINER functions bypass RLS **by running as the owner**, which is intended for the
  worker/admin primitives and is the mechanism behind F1 and F6 for the request-role ones.

## 9. Original S1/S2 regression — PROVEN SECURE

`/tmp/attack.php`, unchanged, on a freshly migrated database:

| | Attack | Result |
|---|---|---|
| A1 | read Q's secret row | no rows |
| A2 | decrypt Q's credential | null |
| A3 | `claim()` as request role | 42501 |
| A4 | assign Q's device to P, then read | 42501, then null |
| A5 | P reads its own | works |

## 10. RouterOS assumptions — PHYSICAL VERIFICATION REQUIRED

Unchanged by this audit; not re-examined, per the brief.

R1 self-signed TLS · R5 HotSpot `use-radius` · R6 active-session `.id` removal (**fails silently
if wrong**) · R7 rate-key names · R2/R3 hardware bisections · and the R4 staging step, which is now
a process question rather than a code one.

## 11. Prioritised findings

| # | Finding | Class | Severity |
|---|---|---|---|
| F1 | Cross-customer accounting injection via `mt_session_account` | PROVEN VULNERABLE | **HIGH** |
| F2 | System requires a superuser owner; DEFINER functions run as superuser | PROVEN VULNERABLE | **HIGH** |
| F4 | RLS coverage guard checks 8 of 19 tables | TESTED BUT INCOMPLETE | **HIGH** (process) |
| F3 | RLS never validates parent ownership; device-secret squatting | PROVEN VULNERABLE | MEDIUM |
| F5 | New tables arrive granted to all roles with RLS off, unguarded | PROVEN VULNERABLE (latent) | MEDIUM |
| F6 | `mt_voucher_redeem` ignores context, returns a tenant id | PROVEN VULNERABLE | MEDIUM |
| F7 | `mt_auth_resolve_token` accepts a foreign principal | PROVEN VULNERABLE | MEDIUM |
| F8 | Telemetry is writable by the customer it describes | PROVEN VULNERABLE | MEDIUM |
| — | `mt_profiles` readable fleet-wide (no tenant data in it) | PROVEN SECURE by design | LOW |
| F9 | Tenant context is self-asserted by the application | PROVEN SECURE as built | — |
| — | Direct cross-customer read/write/delete, all 19 tables | PROVEN SECURE | — |
| — | Context lifecycle, worker boundary, admin boundary, A1–A5 | PROVEN SECURE | — |
| — | Customer creation | NOT IMPLEMENTED | — |
| — | Admin API, PWA wiring, deployment | NOT IMPLEMENTED | — |
| — | R1, R5, R6, R7, R2/R3 | PHYSICAL VERIFICATION REQUIRED | — |

## 12. The pattern, stated plainly

Three silent enforcement failures were predicted and three were found, but not where the last two
were. S1/S2 and the `ALTER DEFAULT PRIVILEGES` failure were **SQL that did not do what it said**.
These are different: the SQL does exactly what it says, and what it says is **narrower than the
rule everyone believes is in force**.

- `WITH CHECK (customer_id = …)` enforces a column, and is read as enforcing ownership (F3).
- `mt_session_account` resolves the tenant correctly, and is read as being tenant-*scoped* (F1).
- A test named "every customer-scoped table" enforces a list, and is read as enforcing the
  catalogue (F4).
- RLS + FORCE on `mt_auth_codes` reads as maximum protection and is in fact deny-all plus a
  superuser bypass nobody wrote down (F2).

The common shape: **a control that is correct about what it checks, and silent about what it does
not.** The next audit of this system should ask of every rule not "does it work?" but "what is the
exact set it constrains, and what is adjacent to that set?"

Three of the eight findings above are reachable today only by someone who can already execute SQL
as `dnb_app`. All three become reachable from the internet the moment the Admin API exists. That
is the correct reason to have done this now.

**Recommendation: remediate F1, F2 and F4 before any HTTP surface is built.** F2 in particular
should be settled first, because its answer changes what "run as the owner" means for every other
finding in this report.

---

# 13. F2 remediation design — removing the superuser-owner dependency

Written before any code changed, per the remediation brief. §§1–12 above are left as the audit
found them.

## 13.1 The finding is worse than §4 F2 stated

F2 reported that two operations fail under a non-superuser owner. Measuring all twenty SECURITY
DEFINER functions showed that is the minority case. **Three fail loudly; the rest silently do
nothing and return a success-shaped answer.**

Verified by performing each operation and then checking the side effect as a superuser:

| Function | Reported to the caller | Actually happened |
|---|---|---|
| `mt_auth_issue_code` | **ERROR** (RLS, `mt_auth_codes`) | nothing |
| `mt_auth_create_session` | **ERROR** (RLS, `mt_auth_sessions`) | nothing |
| `mt_device_register` | **ERROR** (RLS, `mt_devices`) | nothing |
| `mt_auth_verify_code` | "no such code" | nothing — it can never see a code |
| `mt_voucher_redeem` | no rows (i.e. "already used or unknown") | voucher still `unused` |
| `mt_session_account` | `NULL` (i.e. "unknown username") | no session row written |
| `mt_intent_claim` | `0 intents` | nothing claimed — **the queue stops forever** |
| `mt_uplink_record` | `false` | no sample written |
| `mt_device_assign` | returns a row shape | device unchanged |
| `mt_device_set_secret` | `false` | no credential stored |
| `mt_device_set_wan` | returns a row shape | `wan_interface` still NULL |

Every one of those "answers" is indistinguishable, to the caller, from a legitimate negative
result. A deployment with a correctly hardened, non-superuser owner would come up, serve traffic,
accept logins that never succeed, take RADIUS packets it silently discards, and run a worker that
reports an empty queue forever. Nothing would appear in an error log.

This is the same species as the rest of this audit: **the control is correct about what it
checks, and silent about what it does not.** Here the silence is the whole failure mode.

## 13.2 Which functions require owner-level bypass, and exactly why

Every DEFINER function does something a tenant context cannot express — it runs before a tenant
exists, across tenants, or above them. That is *why* it is `SECURITY DEFINER`. The defect is not
that they bypass RLS; it is that they bypass it **by being owned by a superuser**, which is an
undeclared, unbounded, unauditable grant.

| Function | Tables and commands it needs | Why a tenant context cannot serve |
|---|---|---|
| `mt_auth_issue_code` | `mt_auth_codes` S,I · `mt_principals` S | pre-authentication: no tenant is known yet |
| `mt_auth_verify_code` | `mt_auth_codes` S,U | same |
| `mt_auth_create_session` | `mt_auth_sessions` I · `mt_principals` U | the session is what establishes the tenant |
| `mt_auth_resolve_token` | `mt_auth_sessions` S · `mt_principals` S | resolves the tenant; cannot presuppose it |
| `mt_auth_revoke_token` | `mt_auth_sessions` U | may run without a context (logout everywhere) |
| `mt_voucher_redeem` | `mt_vouchers` S,U | network-side: a guest at a portal has no tenant |
| `mt_session_account` | `mt_hotspot_users` S · `mt_sessions` S,I,U | RADIUS: the NAS has no tenant; it is derived |
| `mt_intent_claim` | `mt_intents` S,U | cross-customer by design (one worker, whole fleet) |
| `mt_intent_expire_overdue` | `mt_intents` U | same |
| `mt_sessions_reap` | `mt_sessions` U | same |
| `mt_uplink_prune` | `mt_uplink_samples` D | same |
| `mt_uplink_record` | `mt_devices` S · `mt_uplink_samples` I | same |
| `mt_devices_samplable` | `mt_devices` S | same |
| `mt_device_register` | `mt_devices` I | unassigned stock belongs to no customer |
| `mt_device_assign` | `mt_devices` U · `mt_device_secrets` U · `mt_device_config` U | moves a device *between* tenants |
| `mt_device_set_state` | `mt_devices` U | may act on unassigned stock |
| `mt_device_set_secret` | `mt_devices` S · `mt_device_secrets` I | staging precedes assignment |
| `mt_device_set_desired` | `mt_devices` S · `mt_device_config` I | same |
| `mt_device_set_wan` | `mt_devices` U | same |
| *(new)* `mt_customer_create` | `mt_customers` I | **a customer cannot be its own tenant context before it exists** |

## 13.3 The two policy causes

**`mt_auth_codes`: RLS enabled and FORCED with zero policies.** Forced RLS binds the owner too, so
with no policy the table is readable and writable by nobody at all. It works today only because a
superuser ignores RLS entirely. This is not tenant isolation — it is deny-all plus an undocumented
bypass. It also could never *be* tenant isolation: an OTP is issued before anyone is authenticated,
so there is no `mt_current_customer()` to compare against. The real requirement for this table is
**secrecy of `code_hash` from every application role**, which table grants already provide (no
application role holds any privilege on it).

**`mt_customers`: policy `id = mt_current_customer()`.** A customer that does not exist yet cannot
be the current customer, so no context satisfies the `WITH CHECK` for an INSERT. There is no
bootstrap path, and none should be created by weakening this policy — the policy is correct for
every other operation on the table.

## 13.4 The least-privilege alternative

Four **function-owner roles**, `NOLOGIN`, `NOSUPERUSER`, `NOBYPASSRLS`, member of nothing, owning
no tables — they exist only to be the `SECURITY DEFINER` identity of one trust context each:

| Role | Owns | Trust context |
|---|---|---|
| `dnb_def_auth` | the 5 `mt_auth_*` functions | pre-authentication |
| `dnb_def_net` | `mt_voucher_redeem`, `mt_session_account` | network-side entry points (portal, RADIUS) |
| `dnb_def_work` | the 6 worker primitives | cross-customer background work |
| `dnb_def_prov` | the 6 device primitives + `mt_customer_create` | provisioning and onboarding |

Each is granted table privileges **and RLS policies** for exactly the tables and commands in the
matrix of §13.2, and nothing else. Because these roles do not own the tables, ordinary RLS applies
to them, so every bypass they enjoy is an explicit row in `pg_policy` naming the role, the table
and the command. The privilege becomes **declared, bounded and auditable** instead of implicit in
a role attribute.

What this buys, concretely: `dnb_def_auth` cannot read a device secret; `dnb_def_prov` cannot read
a session; `dnb_def_net` cannot touch `mt_devices`. Under the present design all twenty functions
could do anything at all, because they all ran as a superuser.

The table **owner** (`dnb`) keeps DDL and nothing else. It remains subject to FORCE RLS on every
tenant table, so a migration script or an operator connecting as the owner still cannot read
customer data — which is what docs/57 §11.4 already claimed and could not deliver.

## 13.5 `mt_customer_create` — the trusted bootstrap path

```
mt_customer_create(p_name text, p_created_by text) RETURNS mt_customers
  SECURITY DEFINER, owner dnb_def_prov, EXECUTE granted to dnb_admin only
```

Requires a non-empty `p_created_by` for the same reason `mt_device_set_wan` does: an onboarding
with no recorded author is not an administrative act, it is an anonymous write. `mt_customers`
gains one additional policy — `FOR INSERT TO dnb_def_prov WITH CHECK (true)` — which is INSERT
only. The existing `FOR ALL` isolation policy continues to govern SELECT, UPDATE and DELETE, so
this role can create a customer and still cannot read one.

## 13.6 What this design does not do

- It does not remove `FORCE` from any table, and does not weaken any existing policy. Both would
  achieve "works without superuser" by making the owner bypass RLS implicitly — the same defect
  wearing different clothes.
- It does not give any role `BYPASSRLS`.
- It does not address F1, F3, F5–F8. F1 is the next gate and is deliberately separate.
- It changes the test harness, which currently seeds fixtures by inserting directly as the owner.
  Under a non-superuser owner those inserts silently write nothing, so fixtures must be built
  through the same trusted paths the application uses (`mt_customer_create`, then a tenant
  context). Teardown moves to `TRUNCATE`, which is not subject to RLS and is the owner's to
  perform. **This is not incidental churn: a suite that seeds by bypassing RLS cannot prove that
  the paths which do not bypass it work.**

---

# 14. Remediation outcome (F2, F1, F4)

§§1–12 are the audit as found; §13 is the design written before code changed. This section
records what was done. F3, F5, F6, F7 and F8 are untouched and remain open (§14.8).

## 14.1 F2 — before and after

| | Before (`01acb1a`) | After |
|---|---|---|
| Database owner | **superuser required** | `dnb`: `NOSUPERUSER`, `NOBYPASSRLS` |
| What a DEFINER function runs as | the owner, i.e. a superuser | one of four role-scoped identities |
| Functions able to reach any table | **all 20** | none — each is limited to its own trust context |
| Owner reading tenant data | everything | **nothing** — subject to FORCE RLS |
| Customer creation | superuser, by hand | `mt_customer_create()`, admin-only |
| Behaviour without superuser | 3 loud failures, **11 silent no-ops** | works |

Final role state, read from `pg_roles` after a clean build — every role, without exception:

```
dnb          super=false bypassrls=false      dnb_def_auth super=false bypassrls=false
dnb_app      super=false bypassrls=false      dnb_def_net  super=false bypassrls=false
dnb_worker   super=false bypassrls=false      dnb_def_work super=false bypassrls=false
dnb_admin    super=false bypassrls=false      dnb_def_prov super=false bypassrls=false
dnb_radius   super=false bypassrls=false
```

None is a member of another; `dnb_app` cannot `SET ROLE` to `dnb_worker`, `dnb_def_auth` or
`dnb_def_prov`. The four definer roles are `NOLOGIN` and hold no `CREATE` on any schema, so
none can mint itself a new entry point — asserted by execution, not by reading the grant.

## 14.2 SECURITY DEFINER privilege requirements, as implemented

Each definer role holds table privileges **and** matching RLS policies for exactly the commands
its functions issue, and `tests/test_definer_roles.php` compares the live policy set against this
table, so widening one requires editing a list on purpose:

| Role | Owns | Privileges |
|---|---|---|
| `dnb_def_auth` | 5 `mt_auth_*` | `mt_auth_codes` S,I,U · `mt_principals` S,U · `mt_auth_sessions` S,I,U |
| `dnb_def_net` | `mt_voucher_redeem`, `mt_session_account` | `mt_vouchers` S,U · `mt_hotspot_users` S · `mt_sessions` S,I,U |
| `dnb_def_work` | 6 worker primitives | `mt_intents` S,U · `mt_sessions` S,U · `mt_uplink_samples` S,I,D · `mt_devices` S |
| `dnb_def_prov` | 6 device primitives + `mt_customer_create` | `mt_devices` S,I,U · `mt_device_secrets` S,I,U · `mt_device_config` S,I,U · `mt_customers` **I only** |

Proven by execution: `dnb_def_auth` holds no `SELECT` on `mt_device_secrets` or `mt_sessions`;
`dnb_def_prov` none on `mt_sessions` or `mt_auth_codes`; `dnb_def_net` none on `mt_devices`;
`dnb_def_work` none on `mt_auth_codes` or `mt_device_secrets`. Under the previous design every
one of those questions had the same answer — yes — because all twenty functions were superuser.

`mt_customers` gained one INSERT-only policy for `dnb_def_prov`. The existing `FOR ALL` isolation
policy still governs SELECT, UPDATE and DELETE, so that role can create a customer and cannot
read one. `mt_customer_create` returns a **uuid it generates**, not the row: `INSERT … RETURNING`
would have required SELECT on `mt_customers`, i.e. the ability to read every customer in the
fleet in order to create one.

## 14.3 Authentication path proof

Every assertion is a **side effect**, because checking that a call did not raise would have passed
against the broken system:

| Step | Asserted |
|---|---|
| `mt_auth_issue_code` | returns an id **and a row exists in `mt_auth_codes`** |
| `mt_auth_verify_code` | resolves the principal **and the code is marked consumed** |
| `mt_auth_create_session` | **a session row exists** for the token hash |
| `mt_auth_resolve_token` | returns the correct customer |
| `mt_auth_revoke_token` | **`revoked_at` is genuinely set** |

The same shape covers the operations that used to no-op: `mt_device_register`, `_assign`,
`_set_secret`, `_set_wan`, `_set_state`, `mt_voucher_redeem`, `mt_session_account`,
`mt_intent_claim`, `mt_uplink_record` — each verified by reading the row back.

## 14.4 Customer-creation path proof

`mt_customer_create('Probe Customer','staff:test')` as `dnb_admin` creates the row; the request
role and the worker are refused (`42501`); an empty name or a missing author is refused; and the
new customer is immediately usable as a tenant context. The owner attempting
`INSERT INTO mt_customers` directly is refused by RLS — the bootstrap path is the only path.

## 14.5 F1 — before and after

| Role | Before | After |
|---|---|---|
| `dnb_app` (serves customer requests) | **ACCEPTED** — wrote into Q's rows | **DENIED 42501** |
| `dnb_worker` | ACCEPTED | DENIED 42501 |
| `dnb_admin` | ACCEPTED | DENIED 42501 |
| `dnb_radius` (new) | — | ACCEPTED |

`dnb_radius` holds `EXECUTE` on `mt_session_account` and `mt_current_customer` and **no table
privileges at all** — proven per table for `mt_sessions`, `mt_hotspot_users`, `mt_vouchers`,
`mt_customers`, `mt_devices` and `mt_device_secrets`, and per function for `mt_intent_claim`,
`mt_voucher_redeem`, `mt_device_set_secret` and `mt_customer_create`. A compromised RADIUS
endpoint can submit accounting for a username and do nothing else.

A dedicated identity rather than reusing `dnb_worker`: outbound delivery to a router and inbound
accounting from a NAS are different exposures, and the worker holds device credentials.

**Legitimate ingestion, all asserted:** P's and Q's accounting both accepted and attributed by
username rather than by caller; an unknown username still yields no session and no error; a
retransmitted Start is idempotent (one row, same id); a late smaller Interim cannot shrink a
counter (50000/60000 survives a 1000/2000 retransmit); Gigawords still combine
(2 × 2³² + 1); a Stop closes and a late Interim neither reopens it nor moves its numbers.

`mt_voucher_redeem` deliberately stays with `dnb_app` and remains open as F6.

## 14.6 F4 — guard coverage proof

The hand-written list is gone. Scope is derived from the catalogue: a table is customer-scoped if
it has a `customer_id` column, or if it is `mt_customers`.

**19 of 19 enumerated**, asserted by name:

```
mt_audit_log  mt_auth_codes  mt_auth_sessions  mt_customers  mt_device_config
mt_device_secrets  mt_devices  mt_entitlements  mt_hotspot_users  mt_idempotency
mt_intents  mt_plans  mt_principals  mt_services  mt_sessions  mt_sites
mt_uplink_samples  mt_voucher_batches  mt_vouchers
```

`rls_violations()` reports RLS disabled, FORCE missing, no all-roles isolation policy, a policy
not covering every command, a missing `USING`, and a missing `WITH CHECK`. One exemption is
declared with its reason in code — `mt_auth_codes`, which is pre-authentication and protected by
grants rather than by a tenant policy — and it must still have RLS forced.

## 14.7 The negative test

A guard nobody has watched fail is a guard nobody knows works, so the suite plants the mistake a
future migration would make and walks it back to correct, one step at a time:

| Planted | Guard reports |
|---|---|
| `mt_guard_probe (customer_id uuid)`, nothing else | picked up with no list edited; **RLS not enabled**; **not FORCED** |
| RLS enabled and forced, no policy | **no all-roles isolation policy** |
| `USING`-only policy | **no WITH CHECK — it would permit a write it would not permit a read of** |
| `USING` + `WITH CHECK` | nothing — satisfied |

The table is dropped and the schema re-verified clean.

## 14.8 Findings deliberately still open

F3 (RLS never validates parent ownership; device-secret squatting), F5 (new tables arrive granted
to all roles with RLS off — note F4's guard now catches the RLS half, but **not** the default
grants), F6 (`mt_voucher_redeem` ignores context and returns a tenant id), F7
(`mt_auth_resolve_token` accepts a foreign principal), F8 (telemetry writable by the customer it
describes). None was touched.

F9 is unchanged and is still the most important sentence for the Admin API: **tenant context is
self-asserted.** `dnb_app` may `SET LOCAL app.customer_id` to any value. What F2 changed is that
a compromise of that path no longer also carries superuser.

## 14.9 Residual privilege dependencies

1. **The owner is a member of the four definer roles**, because PostgreSQL requires membership to
   transfer ownership. Granted `WITH INHERIT FALSE, SET TRUE`, so the owner carries none of their
   privileges while acting as itself and must `SET ROLE` deliberately. The first draft omitted
   `INHERIT FALSE`; the owner then inherited every definer policy, and the suite caught it — a
   quieter replay of the superuser dependency this work removed.
2. **The owner can still rewrite anything**, having DDL. It cannot read tenant data, which is the
   part §11.4 claimed and could not deliver.
3. **Role passwords are still development literals**, `dnb_radius` included. Unchanged deployment
   gate from §11.4.
4. **The test harness uses a superuser `inspector()` identity** to observe and poke state no
   tenant can see. Fixtures are *created* through `mt_customer_create` and tenant contexts; only
   observation uses it, and a test asserts nothing under `src/` or `bin/` calls it.
5. **`mt_revoke_public_execute()` is now SECURITY INVOKER**, because PostgreSQL forbids `SET ROLE`
   inside a DEFINER function and the sweep must assume each function's owner to revoke. A caller
   without `SET` on the definer roles simply cannot run it.

## 14.10 Suite

**18 suites, 718 assertions, green on two consecutive clean runs, with a non-superuser owner.**
New: `test_definer_roles.php` (57), `test_accounting_boundary.php` (36); `test_rls_isolation.php`
grew from 66 to 71.

The A1–A5 probe was run **unchanged and byte-identical** to the committed copy: A1 no rows, A2
null, A3 `42501`, A4 `42501` then null, A5 works. Only its fixture connection was pointed at a
superuser, because seeding a customer by direct INSERT is exactly what F2 removed.

## 14.11 Status

Ready for a hostile re-audit of this remediation. **Not ready for deployment, and not ready for
the Admin API** — five findings remain open, the physical MikroTik gate is untouched, and the
role-password gate still stands.
