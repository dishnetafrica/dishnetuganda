# 57 — Architecture-to-Code Audit

**Status:** AUDIT REPORT — findings only, nothing fixed
**Subject:** `dishnet-mikrotik-control-plane/` at `1fd38e9` · 539 assertions, 14 suites
**Method:** catalogue queries, source tracing, and **empirical probes** — not a reading of the tests

> **Two HIGH security findings were proven empirically, not inferred.** §4.1 and §4.2.
> Neither is reachable over HTTP today. Both are real. Nothing has been changed.

**Evidence grades used throughout:**
**PROVEN** · **TESTED AGAINST FAKE** · **UNPROVEN UNTIL PHYSICAL MIKROTIK** · **NOT BUILT**

---

## 0. Auditor's caveat

This audits code I wrote, so the failure mode is confirming my own assumptions. The
countermeasure used was to probe behaviour rather than re-read intentions: §4.1 and §4.2
were found by **executing an attack**, not by reviewing the design. Both contradict things
I asserted in earlier step reports, and §4.6 lists those corrections.

---

## 1. RouterOS assumptions — highest priority

None of the seven has been answered. No answer has been guessed.

| # | Assumption | Code | Test | What the test **actually** proves | Physical test | Recommendation |
|---|---|---|---|---|---|---|
| **R1** | RouterOS serves REST on a **self-signed** certificate | `RestClient` (`SSL_VERIFYPEER=false`, tunnel-only) | none | Nothing. The fake is plain HTTP — TLS is never exercised | Enable `www-ssl` with a self-signed cert; `GET /rest/system/resource` | **KEEP**, verify. If it fails, per-device PKI is a fleet cost (docs/30 §3b) |
| **R2** | `Mikrotik-Rate-Limit` accepts ≤ 2³²−1 bps | `PlanValidator::MAX_RATE_BPS` | `test_policy` rejects above it | That *our* validator rejects it. Nothing about the device | Bisect the accepted maximum on a unit | **PARAMETERISE.** A `const` states a fact we do not have; it belongs in config keyed by model × version |
| **R3** | `shared-users` accepts ≤ 65535 | `PlanValidator::MAX_DEVICES` | as R2 | as R2 | Same bisection | **PARAMETERISE**, as R2 |
| **R4** | **`ether1` is the WAN interface** | `UplinkSampler::wan()` | `test_telemetry` feeds a fake named `ether1` | That we pick the interface *we told the fake to offer*. Circular | Read `/interface` on real units; confirm which is WAN per model | **REMOVE FROM CODE.** See §1.1 — this is the dangerous one |
| **R5** | `ip/hotspot/profile` carries `use-radius` | `RouterOsDelivery::assertRadiusBacked` | fake returns `use-radius` | That we parse the shape we invented | `GET ip/hotspot/profile` on a real unit | **KEEP**, verify |
| **R6** | `ip/hotspot/active/remove` takes `.id` | `RouterOsDelivery::disconnect` | fake removes by `.id` | That the fake honours our own convention | Disconnect a real session | **KEEP**, verify. Fails **silently** if wrong |
| **R7** | `interface` returns `rx/tx-bits-per-second` | `UplinkSampler` | fake returns those keys | as R4 — circular | Read `/interface` on a real unit | **KEEP**, verify; couple with R4 |

### 1.1 R4 is the one to fix before hardware, not after

R4 and R7 are **circular tests**: the fake returns what the code expects because I wrote
both. That is true of R5 and R6 too, but they fail *loudly* — confirmation never succeeds,
or a disconnect visibly does not happen.

**R4 fails quietly and plausibly.** If `ether1` is not the WAN on a given model, the sampler
measures a LAN bridge or a tunnel and records numbers that look entirely reasonable. Those
numbers then become the evidence in the support-boundary conversation C17 exists for — and
they would be wrong in the customer's favour or DishNet's at random.

**Recommendation:** the WAN interface is not something to guess per model. It is a fact
established at staging, when a person is holding the device (docs/31 §3.1). It belongs in a
`mt_devices.wan_interface` column recorded then, with the sampler reading it and **recording
nothing when it is unset** — consistent with the existing rule that an unreachable router
records nothing rather than a zero.

*Not applied. It adds a column and a staging step, which is more than a local fix.*

---

## 2. F1–F13 compliance

| | Rule | Verdict | Evidence |
|---|---|---|---|
| **F1** | Domain A / B separation | **PASS** | Nine Domain A artefacts grepped across `src/`, `migrations/`, `bin/`, `public/` — zero hits. No SQLite driver anywhere. Own database. Guarded and negative-tested |
| **F2** | Intent queue is the only path to a router | **PASS (structural)** | Only `Jobs/` and `Delivery/` construct `RestClient`; only `IntentWorker` calls `deliver()`; `Http/` and `Api/` reference neither. Guarded |
| **F3** | Customer PWA never talks to a router | **PASS** | No customer route reaches `Delivery`. `/me/uplink` reads stored samples — asserted with the router down |
| **F4** | Authorization server-derived | **PASS at the route layer** | 21 route patterns, none contains `customer`/`tenant`; no handler reads one from a body; `Kernel` derives from the token. **But see §4.1–4.2** — the boundary *below* the route has holes |
| **F5** | Three experiences | **PASS** | Admin has no HTTP surface (§7), portal not built — both correct, neither is drift |
| **F6** | Four planes | **PASS** | §3 |
| **F7** | Customer owns the uplink | **PASS** | No capacity column; telemetry is observation only |
| **F8** | No bandwidth sold or rationed | **PASS** | `mt_entitlements.key` CHECK admits exactly five platform-scope keys. No entitlement key appears in `src/` at all |
| **F9** | Customer controls retail policy | **PASS** | Plans at 5–1000 Mbps all accepted; price 0 accepted; no commercial-refusal wording in any source file |
| **F10** | No commercial limit felt by a guest | **PASS** | Vacuously — no entitlement is enforced anywhere yet. Will need re-auditing when C11 lands |
| **F11** | A voucher is a RADIUS user, not an account | **PASS** | `mt_hotspot_users` is the projection; no code creates a customer from a redemption |
| **F12** | Phase 0 stands | **N/A** | Not touched. No WireGuard or FreeRADIUS configuration was modified |
| **F13** | Measure and inform, never gate | **PASS** | Proven behaviourally: saturated readings change nothing, and a 100 Mbps plan is created while the link reads saturated. Two structural guards |

**No frozen decision was reopened. No BYO or adoption code exists. No C20 assumption was made.**

---

## 3. Four planes

**No hidden commercial ceiling exists.** `mt_entitlements` is referenced by exactly one
source file — the read-only route. No entitlement key name appears in `src/`. The CHECK
constraint means adding a bandwidth key requires a migration that visibly edits the list.

| Plane | Where it lives | Leak into another plane |
|---|---|---|
| Commercial | `mt_services`, `mt_entitlements` | None — never read by a decision |
| Policy | `mt_plans` (customer) | Bounded only by protocol validity |
| Enforcement | `mt_profiles` (derived), RADIUS attributes | Customer never names a profile |
| Transaction | vouchers, sessions, audit | — |

---

## 4. Security

### 4.1 FINDING S1 — cross-customer router credential disclosure · **HIGH**

**Proven, not inferred.** Executed against a live database:

```
As customer P, inside P's tenant context:
  Q's device row          -> hidden          (RLS working)
  Q's mt_device_secrets   -> READABLE        (no RLS on that table)
  credentials(Q_device)   -> username=dn-mgmt  password=pw-Q
```

**A caller holding another customer's device id recovers that customer's router password in
plaintext.**

Cause, in two parts:
1. `mt_device_secrets` has **no RLS** (`relrowsecurity = false`, zero policies) and `dnb_app`
   holds SELECT/INSERT/UPDATE.
2. `DeviceRegistry::credentials()` does not check the device is visible to the caller, and
   the AEAD opens because the key is process-wide and the associated data is the device id
   **which the caller supplied**.

**Reachability today: none over HTTP.** No route takes a device id, and the admin surface
does not exist. The protection is *absence of a route*, not a boundary.

**Remediation (not applied):** add `customer_id` to `mt_device_secrets` with the standard
policy, and have `credentials()` resolve the device through `mt_devices` (RLS-protected)
before reading the secret. Both, not either.

### 4.2 FINDING S2 — cross-customer intent disclosure · **HIGH**

**Proven:**

```
P calls IntentQueue::claim() -> 1 intent returned
  kind=secret.work  customer=<Q>  payload={"confidential":"Q-payload"}
```

`mt_intent_claim` is `SECURITY DEFINER`, returns `SETOF mt_intents` — **whole rows for every
customer** — and `EXECUTE` is granted to `dnb_app`. It must be, because the worker has no
customer context. But nothing distinguishes the worker from a request handler: they are the
same database role.

**Reachability today: none over HTTP.** Again, absence of a route.

**Remediation (not applied):** give the worker its own role with `EXECUTE` on the claim and
reap functions, and revoke them from the request-path role. This is a deployment-shaped
change — two connection identities instead of one — so it belongs with §6.

### 4.3 FINDING S3 — device configuration cross-readable · **MEDIUM**

`mt_device_config` has no RLS; `dnb_app` has SELECT. Desired and actual router
configuration for **every** device is readable from any customer context. Same cause and
same remediation shape as S1.

### 4.4 FINDING S4 — profile table readable · **LOW**

`mt_profiles` has no RLS. It carries no customer column by design (profiles are shared and
deduplicated), but the row set reveals *what shapes of plan exist across the estate* — a
weak inference channel. **It also narrows a claim I made in step 4** (§4.6).

### 4.5 FINDING S5 / S6 — minor · **LOW**

- **S5:** `dnb_app` holds **DELETE on `mt_migrations`**, from the blanket
  `GRANT … ON ALL TABLES`. The application has no business deleting migration history.
- **S6:** **no rate limit on voucher redemption.** The 32¹⁰ keyspace makes brute force
  impractical, but `mt_voucher_redeem` is callable without throttling, unlike the auth
  endpoints. Worth a limit before a captive portal exists.

### 4.6 Corrections to claims I made in earlier step reports

| Claimed | Accurate |
|---|---|
| Step 7: *"an envelope lifted to another device does not open"* | True, and narrower than it sounded. AAD prevents **row swapping**. It does **not** prevent a caller who knows the device id — §4.1 |
| Step 4: two customers sharing a profile *"cannot observe that"* | True of **API responses**. Not true of the table, which has no RLS — §4.4 |
| Step 7: config and secrets *"are reached only through a device the caller could already see"* | **An assumption I asserted and did not enforce.** Nothing checks it |

### 4.7 What passed

| | Verdict |
|---|---|
| RLS on all 16 customer-scoped tables, all **FORCE**d | **PASS** |
| `dnb_app`: not superuser, `NOBYPASSRLS`, no DDL, no ownership | **PASS** |
| **All 16** `SECURITY DEFINER` functions pin `search_path` | **PASS** |
| Customer isolation at the route layer: read, enumerate, modify, infer | **PASS** — 66 assertions |
| 404 never 403; a real foreign id is indistinguishable from a nonexistent one | **PASS** |
| Enumeration resistance on `request-code` and `verify` | **PASS** |
| Projection allowlists — new columns withheld by default | **PASS**, proven by adding a column |
| Credential sealing at rest; no plaintext column; no credential logged | **PASS** (but see S1 for *access*) |
| Router destination restricted to `10.66.0.0/16` | **PASS** |
| Idempotency incl. digest mismatch and in-flight | **PASS** |
| Audit immutability incl. TRUNCATE | **PASS** |

---

## 5. Intent / delivery, and identity

**F2 is structural, not conventional.** Verified by source trace: `Http/` and `Api/`
reference neither `DeliveryPort` nor `RestClient`; `deliver()` has exactly one caller,
`IntentWorker`. An HTTP request cannot reach a router in one process.

**Identity chain intact:** `Customer → Service → Site → Router → Voucher → Device → Session`.
Every entity carries `customer_id` under RLS. `TenantContext::run()` is called in exactly
two places — the `Kernel` (from the resolved token) and `IntentWorker` (from the claimed
intent's own customer). Neither takes an id from a request.

---

## 6. Production-readiness gap

| Category | Items |
|---|---|
| **PROVEN — validated application logic** | Tenancy and isolation at the route layer · auth and enumeration resistance · intent state machine and crash recovery · plan validity ≠ ceiling · voucher uniqueness and single redemption **under real concurrency** · accounting under retransmit, reorder and 32-bit wrap · audit immutability · projection allowlists · telemetry cannot gate |
| **TESTED AGAINST FAKE — RouterOS behaviour unvalidated** | All of `RouterOsDelivery`, `RestClient`, `UplinkSampler`. Seven assumptions, two of them circular (§1.1) |
| **DEFECTS FOUND, NOT FIXED** | S1, S2 (HIGH) · S3 (MEDIUM) · S4, S5, S6 (LOW) |
| **Missing Admin API** | `/api/v1/admin/*` has **zero routes**. Every admin operation exists as a function reachable from code and tests, not over HTTP. Provisioning is not operable by a person |
| **Missing PWA integration** | Every screen has an endpoint; none calls one |
| **Deployment** | Host, concurrency concentrator endpoint, secret storage, backup/restore — all undecided (docs/30 Q5) |
| **Infrastructure / security operations** | No Redis (docs/30 assumes it for live session state) · no observability beyond `error_log` · no rate limiting outside auth · no key rotation procedure · single database role for worker and request path (S2) |
| **Remaining product decisions** | C6, C9, C11, C12, C13, C14, C15, C16, C17, C18, C19, C20, C8′ |
| **NOT BUILT, deliberately** | BYO/adoption · captive portal · payments · async bulk vouchers >500 · anything in Domain A |

**This is not a production-ready platform.**

---

## 7. Prioritised remediation

| # | Finding | Priority | Shape of fix | Blocks |
|---|---|---|---|---|
| 1 | **S1** credential disclosure | **P0** | `customer_id` + RLS on `mt_device_secrets`; resolve via `mt_devices` first | Any admin API |
| 2 | **S2** intent disclosure | **P0** | Separate worker role; revoke claim/reap from the request role | Deployment design |
| 3 | **S3** config cross-readable | **P1** | As S1, on `mt_device_config` | — |
| 4 | **R4** `ether1` guess | **P1** | `mt_devices.wan_interface`, recorded at staging; record nothing when unset | Trustworthy telemetry |
| 5 | **S6** redemption rate limit | **P2** | Throttle per code prefix and per NAS | Captive portal |
| 6 | **S5** `DELETE` on migrations | **P2** | Narrow the grant | — |
| 7 | **S4** profile readability | **P3** | Document as shared, or add a scoped view | — |
| 8 | **R2/R3** parameterise limits | **P3** | Config keyed by model × version, after measurement | Needs hardware |

**P0 and P1 should land before any admin HTTP surface**, because that surface is precisely
what would turn "unreachable over HTTP" into "reachable".

---

## 8. Physical Phase 0 tests to close the hardware gate

### 8a. CHR — replaces the fake, closes R1–R7

1. `/interface/wireguard/print` does not error
2. `/ip/service/print` shows `www-ssl`; enable with a certificate
3. `GET /rest/system/resource` returns JSON
4. **REST over a self-signed certificate** → **R1**
5. `PATCH ip/hotspot/profile use-radius=yes` accepted **and reads back applied** → **R5**
6. Bisect the maximum accepted `Mikrotik-Rate-Limit` → **R2**
7. Bisect the maximum accepted `shared-users` → **R3**
8. `ip/hotspot/active/remove` with `.id` actually removes → **R6**
9. `GET /interface`: exact field names, and **which interface is WAN per model** → **R4, R7**
10. `/export` completeness after each step

### 8b. Metal only — the Phase 0 gate (docs/31, docs/32)

| | |
|---|---|
| **A1–A14** | Staging → ship → tunnel through **real CGNAT** → push over the tunnel → phone joins → voucher → `Access-Accept` → `radacct` Start/Interim/Stop → **expiry with no intervention** |
| **Test B** | CGNAT on the connection customers actually have |
| **Test C** | Power cut at each A8 step — recoverable? |
| **Test D** | **The watchdog, deliberately triggered.** An untested watchdog is a scheduled outage waiting for its condition |
| **Test E** | The customer presses reset |
| **docs/31 §7** | Compatibility matrix filled by hand, per model × RouterOS version |
| **Timing** | Factory-reset unit to managed, behind real CGNAT, **under five minutes** |

CHR narrows the unknown. **It does not close it:** docs/31 §1.1 — no radio, no RouterBOARD
serial, no factory-reset behaviour.

---

## 9. Audit verdict

| | |
|---|---|
| Frozen architecture | **Intact.** F1–F13 hold; nothing reopened; no BYO, no Domain A, no C20 assumption |
| Application logic | **Well validated**, with two HIGH isolation defects the tests did not look for |
| RouterOS integration | **Unvalidated.** Two of seven assumptions are circular tests |
| Production readiness | **No** |

The tests were validating the right architecture. They were not looking below the route
layer for isolation, which is where both HIGH findings live — reachable from a database
role rather than from a URL.

*No production system was contacted. Nothing was fixed. The probe database was temporary.*

---

# 10. Remediation design for S1 and S2

**Written before any code changed.** Added to the audit rather than to a new document so
the finding and its answer stay together; the audit's own findings above are unaltered.

## 10.1 One attack the audit under-stated

Re-running the probe cleanly surfaced a fourth path the audit did not list:

```
A4  mt_device_assign(Q's device -> P)  as the request role   ASSIGNED
    then credentials(Q_device) as P                          DISCLOSED password=pw-Q
```

**A customer can assign another customer's device to itself, then read its credential
entirely legitimately.** `mt_device_assign` is `SECURITY DEFINER` and executable by
`dnb_app`.

This means **S1 cannot be fixed by securing `mt_device_secrets` alone.** Putting RLS on the
secrets table while leaving device *assignment* open converts a direct read into a two-step
read. The device admin functions are therefore in scope — not as an unrelated finding, but
because S1 is not secure without them.

## 10.2 Current privilege path

```
HTTP request ─┐
worker        ├─ ALL run as  dnb_app  ─── EXECUTE on every SECURITY DEFINER function
admin/staging ┘                        └── SELECT on mt_device_secrets (no RLS)
```

One database identity for three execution contexts. Every privilege any of them needs, all
of them have. The only thing separating a customer request from a worker is **which PHP
function the process happens to call** — and that is not an authorization boundary.

## 10.3 Intended privilege path

Three roles, because three execution contexts genuinely exist:

```
HTTP request   →  dnb_app     table DML under RLS · auth functions only
background job →  dnb_worker  + claim, expire, reap, prune, samplable, uplink_record
provisioning   →  dnb_admin   + device register, assign, set_state, set_secret
```

All three: `NOSUPERUSER`, `NOBYPASSRLS`, no DDL, own nothing. None is a member of another,
so none can `SET ROLE` into another.

## 10.4 Exact changes

| Object | Change |
|---|---|
| **`mt_device_secrets`** | `+ customer_id` (FK, nullable for unassigned stock), backfilled; `ENABLE`/`FORCE ROW LEVEL SECURITY`; standard isolation policy |
| `mt_device_assign` | Also moves the secret's `customer_id`, so the two cannot drift |
| **`mt_device_set_secret`** | **New** `SECURITY DEFINER` — writes a secret for a device that may still be unassigned, which RLS would otherwise forbid. `dnb_admin` only |
| `mt_devices_samplable` | Now returns `customer_id`, so the sampler can enter a tenant context instead of reading across customers |
| `mt_intent_claim`, `mt_intent_expire_overdue`, `mt_sessions_reap`, `mt_uplink_prune`, `mt_uplink_record`, `mt_devices_samplable` | `REVOKE` from `dnb_app`; `GRANT` to `dnb_worker` |
| `mt_device_register`, `mt_device_assign`, `mt_device_set_state`, `mt_device_set_secret` | `REVOKE` from `dnb_app`; `GRANT` to `dnb_admin` |
| `mt_migrations` | `REVOKE DELETE` from all three (finding S5, one line, taken while the grants are being rewritten) |
| `Database` | `+ worker()`, `+ admin()` |
| `DeviceRegistry::credentials()` | Resolves the device through `mt_devices` **first**; refuses if invisible |
| `UplinkSampler` | Enters each device's tenant context before reading its credential |

**Unchanged:** `mt_intent_claim`'s body, the intent state machine, lease and retry
semantics, the AEAD scheme, and every `mt_auth_*` function (login is a request-path
operation and those functions return ids only).

## 10.5 Why this stops direct SQL abuse, not merely HTTP abuse

The audit's own objection to "there is no HTTP route" applies to any fix that relies on
application structure. This one does not:

- **S1:** with `customer_id` + RLS, a `SELECT` on `mt_device_secrets` from `dnb_app`
  returns another customer's row **never** — not "only if the code forgets a check". The
  device resolution in `credentials()` is a second layer, not the layer.
- **S2:** `dnb_app` holds no `EXECUTE` on the claim primitive. Arbitrary SQL as `dnb_app`
  cannot call it, cannot `SET ROLE` to a role that can (no membership), and cannot grant
  itself (no `CREATEROLE`, not superuser).
- **A4:** `dnb_app` holds no `EXECUTE` on `mt_device_assign`, so the two-step theft has no
  first step.

In each case the boundary is a privilege the request path does not hold, which holds
whether the caller arrived through a route, through a SQL injection, or through a PHP
shell.

## 10.6 What this does not fix

Deliberately out of scope, and still true afterwards:

- **R4** (`ether1`) — sequenced after this gate.
- `mt_session_account` and `mt_voucher_redeem` remain `dnb_app`-executable **by design**:
  both are network-side entry points that resolve identity from what is presented. Neither
  returns another customer's data, but `mt_session_account` could be used to inject
  accounting for a username an attacker already knows. Noted as residual; the
  namespaced usernames are not exposed by any projection.
- Nothing here is proven against hardware. The physical Phase 0 gate (§8) is untouched.

---

# 11. Remediation outcome (S1, S2)

Recorded after implementation. The findings in §4 and the design in §10 are left exactly as
written so the pre-remediation state stays a fixed reference point.

## 11.1 A remediation that silently did nothing

The first implementation of migration 015 revoked the privileged functions like this:

```sql
REVOKE EXECUTE ON FUNCTION mt_intent_claim(text,interval,integer) FROM dnb_app;
```

That statement succeeds, reports no error, and **changes nothing that matters**. PostgreSQL
grants `EXECUTE` on a newly created function to `PUBLIC`, so the privilege never depended on
a grant to `dnb_app` in the first place. Revoking the named grant leaves the `PUBLIC` grant
standing, and `dnb_app` keeps the privilege through it.

Every attack in §4 still succeeded against that schema. The catalogue showed it plainly once
looked at — `proacl` read `=X/dnb,...`, where the empty grantee before `=` *is* `PUBLIC` —
but nothing in the migration's output said so, and a reader checking that the revoke was
present would have found it present.

What caught it was writing the denial assertions as tests and watching them fail. This is the
same lesson as G1–G5 in the implementation log, now in its most expensive form: **a security
control nobody has watched fail is a control nobody knows works.** A revoke is not a denial
until something has been refused.

The corrected sweep takes `EXECUTE` away from `PUBLIC` *and* from all three application roles
across the schema, then grants it back by name, so the grants in migration 015 are the entire
privilege surface rather than a delta against migrations written earlier. An
`ALTER DEFAULT PRIVILEGES` keeps future functions on the same footing, and a test asserts that
no `mt_` function grants `EXECUTE` to `PUBLIC`, so an ordinary `CREATE FUNCTION` in a later
migration cannot quietly reopen S2.

## 11.2 Attack results, before and after

Same probe, same attacker identity (`dnb_app` inside customer P's tenant context), against a
freshly migrated database in both cases.

| # | Attack | Before | After | Refused by |
|---|--------|--------|-------|-----------|
| A1 | `SELECT … FROM mt_device_secrets WHERE device_id = <Q's>` | **DISCLOSED** | no rows | RLS (`FORCE`) |
| A2 | `DeviceRegistry::credentials(<Q's device>)` | **DISCLOSED** `password=pw-Q` | `null` | RLS + device resolved first |
| A3 | `IntentQueue::claim()` as the request role | **DISCLOSED** 1 intent, payload included | `SQLSTATE 42501` | no `EXECUTE` |
| A4 | `mt_device_assign(<Q's device> → P)`, then read | **ASSIGNED**, then **DISCLOSED** `pw-Q` | `SQLSTATE 42501`, then `null` | no `EXECUTE` |
| A5 | P reads its **own** credential (must keep working) | ok | ok | — |

The two refusal mechanisms differ on purpose. Reads fail **closed and quiet** — RLS returns no
rows, and a missing row is indistinguishable from a foreign one, so the boundary leaks nothing
about what exists. Cross-customer *primitives* fail **loud** — `42501` — because a request-path
process attempting them is not a user error, it is either a bug or an intrusion, and it should
be visible in the log as such.

A4 is why the device admin functions were in scope. Securing `mt_device_secrets` alone would
have converted a one-step read into a two-step one.

## 11.3 What this changes about the audit's conclusions

S1 and S2 move from OPEN to CLOSED, and S3 and S5 close with them because the same sweep
covers them. **No other finding is affected**, and in particular:

- The system is still **not proven against hardware**. F1–F13 passing, and now these denials
  passing, are statements about the database and the code — not about a MikroTik.
- R4 (`ether1`) is untouched and still sequenced after this gate.
- `mt_session_account` and `mt_voucher_redeem` remain `dnb_app`-executable by design (§10.6),
  and that residual is unchanged.

Nothing here makes the system deployable. It makes one class of cross-customer compromise
unreachable from the request role, which was a precondition for deployment, not a substitute
for the remaining gates.

## 11.4 Residual privilege paths, and what still gates them

Four things remain true after this remediation. None is a defect introduced by it; all four
are reasons the system is still not deployable.

**1. Role passwords are development literals (deployment gate).** Migration 015 creates
`dnb_worker` and `dnb_admin` with literal passwords, following the convention migration 001
set for `dnb_app`. They are in the repository. On a database reachable beyond a local socket
they are equivalent to no password, and three roles with published credentials are one role.
The separation proven in §11.2 holds only once each role has a real password
(`ALTER ROLE … PASSWORD`, supplied via `DNB_APP_PASS` / `DNB_WORKER_PASS` / `DNB_ADMIN_PASS`).
Stated in the migration itself so it cannot be deployed unread.

**2. The `dnb` owner still bypasses everything.** Table owners are not subject to RLS unless
`FORCE` is set — it is set on the tenant tables — but the owner can drop `FORCE`, drop a
policy, or `SET ROLE` to any application role. Migrations must run as the owner, so this
cannot be closed, only contained: the owner is not an application identity, no long-lived
process connects as it, and `Database::owner()` appears in migrations and tests only.

**3. `mt_session_account` and `mt_voucher_redeem` remain request-path executable by design.**
Unchanged from §10.6. Both are network-side entry points that resolve identity from what is
presented; neither returns another customer's data. `mt_session_account` could still be used
to inject accounting for a username an attacker already knows, and the namespaced usernames
are not exposed by any projection. Residual, accepted, unchanged by this work.

**4. None of this is proven against hardware (physical Phase 0 gate).** Everything in §11.2
was demonstrated against PostgreSQL and a fake RouterOS. The admin functions are now the only
way to register, assign or credential a device, which means Phase 0 provisioning must run as
`dnb_admin` — a path no physical device has yet exercised. Whether a real MikroTik provisions
through it is untested, and the CHR harness cannot answer it in this environment (no KVM, no
qemu, no route to the vendor). This stays exactly where the audit put it: unproven until a
physical device runs the provisioning path end to end.
