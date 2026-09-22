# 56 — Implementation Status: Steps 1–8 Complete, Sequence Stopped

**Status:** FINAL STATUS BEFORE AUDIT — implementation sequence halted as instructed
**Scope:** `dishnet-mikrotik-control-plane/` (Domain B). No plugin change, no Domain A contact, nothing deployed.

> **RouterOS compatibility is NOT proven.** The fake-RouterOS tests prove our HTTP client
> and our delivery logic. Physical RouterOS validation is a separate gate and remains
> entirely open. §5 and §6 state exactly what that means.

---

## 1. Steps 1–8

| # | Step | Exit condition | Met |
|---|---|---|---|
| 1 | Schema, RLS, tenancy, audit, idempotency | Two customers provably cannot see each other | ✅ |
| 2 | Auth, `/me` reads, projection | PWA renders from real data; no field leaks | ✅ |
| 3 | Intent queue, state machine, worker | An intent survives a crash mid-delivery | ✅ |
| 4 | Policy: plans and derived profiles | A customer creates a plan; validity ≠ ceiling | ✅ |
| 5 | Vouchers, batches, AAA projection | Concurrent redemption fails for the second | ✅ |
| 6 | Sessions, accounting ingestion | Start/Interim/Stop reach `mt_sessions` | ✅ |
| 7 | Devices, lifecycle, delivery | Provisions against **CHR**; unproven on metal | **❌ CHR half not reached** |
| 8 | Telemetry | Measured, displayed, no enforcement path | ✅ |

**Seven of eight exit conditions met. Step 7's was not**, and §5 says why.

---

## 2. Tests

| | |
|---|---|
| Assertions | **539** |
| Suites | **14** |
| Runs | Green **twice consecutively**, from a freshly created database, via `./tests/run.sh` |
| Real-HTTP suites | 3 — the API (`php -S`), the fake router, the redemption race |
| True-concurrency test | 12 parallel processes racing one voucher code |

**Code:** 34 PHP files / 2,401 lines · 14 migrations / 1,212 lines · 21 tables · 16
`SECURITY DEFINER` functions. Tests are 2,760 lines — **more test than implementation.**

Every guard in `test_frozen_guards.php` has been **negative-tested**: a violation planted,
the suite confirmed to fail, the plant removed.

---

## 3. Defects found and fixed

### 3a. Product defects — each would have reached production

| # | Defect | Consequence if shipped |
|---|---|---|
| **P1** | **Catch-and-retry around a unique violation, inside a transaction** — 3 call sites | In PostgreSQL a failed statement aborts the whole transaction (25P02). The retry could never run and neither could anything after it. A voucher batch would abort entirely on the first code collision. Fixed with savepoints; guarded |
| **P2** | `radius_ref` added NOT NULL **with no default** | **Every future customer insert would fail.** Found because the seeder creates customers |
| **P3** | Rate limit raised `too_many_connections` | A throttled client got an opaque **500**, and the log read *"Too many connections"* — sending an operator hunting a connection-pool fault that did not exist. Now a dedicated SQLSTATE → 429 |
| **P4** | Divergence compared **equality**, not subset | RouterOS returns rows carrying attributes we never set, so **every device would read as diverged forever** |
| **P5** | RLS made unassigned stock **uninsertable** | Device registration impossible. Relaxing the policy would have let every customer read all stock. Fixed with narrow admin functions |
| **P6** | Sampler saw no devices under RLS | **Telemetry would silently collect nothing.** Same class as P5 |

**P5 and P6 are the same structural fact**, and it recurred a third time at the intent
claim: a worker that legitimately spans customers needs a narrow, auditable
`SECURITY DEFINER` function — never a relaxed policy.

### 3b. Test and guard defects — the suite lying rather than the code

| # | Defect |
|---|---|
| **G1** | A gigawords guard that **could not fail**: it checked the source for `4294967296`, which survives in the constant even when the multiplication is deleted. It passed while the bug was present. Now behavioural. *Negative-testing is what caught it* |
| **G2** | **Five text-scanning guards matched prose, not code** — the comment forbidding a thing tripping the guard hunting it. Fixed by stripping comments, by exempting lines that cite the rule, and once by rewrapping the comment rather than loosening the guard |
| **G3** | The **Domain A guard listed the bare word "Starlink"** — correct until docs/50 established the *customer's uplink* may be Starlink. It now names artefacts (`hotspot_paid_access`, `dr_wifi_`, `StarlinkSessionStore`…), re-verified to still catch each |
| **G4** | The seeder's table list went stale **three times**. Now guarded: a test asserts it covers every table with a customer foreign key |
| **G5** | An audit assertion counted the whole table and measured the test's own history — because `mt_audit_log` is append-only *by design* |

**The recurring lesson**, stated because it cost five incidents: a text-scanning guard must
distinguish **assertion from discussion**, and a guard nobody has watched fail is a guard
nobody knows works.

---

## 4. Deferred and open decisions

**None of these was answered by implementation.** Each has a clean extension point.

| | Question | Where it is held open |
|---|---|---|
| **C20** | Hardware model: A / B / C / C′ | `staged_at`/`staged_by` already model *who* staged a device; A, C and C′ need no schema change between them. **No BYO code exists** |
| **C11** | Pricing unit, then price | `mt_entitlements` is key/value; the unit becomes keys |
| **C17** | Support demarcation and portal wording | Copy, not code |
| **C18** | Display only, or display + alert | Sampling built; thresholds are a decision |
| **C19** | Trial shape | An entitlement key once C11 names the unit |
| **C6 / C16** | Owner vs staff roles; where staff records live | `mt_principals.kind` is `owner\|operator`; one `can()` to change |
| **C9 / C13** | Portal as shop or login; customisation bounds | **Nothing built.** RouterOS serves the portal |
| **C14** | Merchant of record, if C9=B | **No payment code.** Needs legal advice, not a product decision |
| **C8′** | Tunnel-down behaviour | Session reaping built; whether concurrency is offered as a customer tool waits on Phase 0 data |
| **C12** | Suspension, and guests holding paid vouchers | Not modelled |
| **C15** | Oversell attribution | Measurement exists; policy does not |

F1–F13 (docs/53) are **unchanged and unreopened**.

---

## 5. Hardware-dependent claims that remain UNPROVEN

**This is the most important section of this document.**

### 5.1 What was not run, and why

CHR could not be run here: **no `/dev/kvm`, no `vmx`/`svm`, no qemu, no Docker daemon, no
route to fetch an image.** CHR is a full RouterOS VM.

`./tools/chr_harness.sh preflight` reports this.

### 5.2 What the fake proves, and what it does not

| Proven by `tests/fake_routeros.php` | NOT proven by it |
|---|---|
| Our HTTP client: basic auth, JSON, methods, status codes, timeouts | That RouterOS accepts **any** of these paths |
| Our delivery logic: deliver → mark sent → read back → confirm | That these **payload shapes** are what RouterOS expects |
| Retry, permanent-vs-retryable, unknown-kind handling | That a 4xx from a real device means what we assume |
| Divergence computed from a read-back | That the read-back format matches what we parse |

docs/30 Artifact 13: *"A fake MikroTik would pass while the real one rejects the command."*
**That sentence describes the current state of this repository exactly.**

### 5.3 Specific unverified assumptions now embedded in code

| Assumption | Where | Risk if wrong |
|---|---|---|
| RouterOS serves REST on a **self-signed** certificate without further configuration | `RestClient` class comment; docs/30 §3b flags it | Per-device PKI becomes a fleet cost |
| `Mikrotik-Rate-Limit` accepts up to 2³²−1 bps | `PlanValidator::MAX_RATE_BPS` | A customer's plan is silently unenforceable, or rejected |
| Hotspot `shared-users` accepts up to 65535 | `PlanValidator::MAX_DEVICES` | Same |
| `ether1` is the WAN interface | `UplinkSampler::wan()` | Telemetry measures the wrong interface — **plausible-looking wrong numbers** |
| `ip/hotspot/profile` carries `use-radius` | `RouterOsDelivery` | Confirmation never succeeds, or always does |
| `ip/hotspot/active/remove` takes `.id` | `RouterOsDelivery::disconnect` | Disconnect silently does nothing |
| `interface` returns `rx-bits-per-second` | `UplinkSampler` | Telemetry records zeros |

**Every row is a guess until a device answers.** The fourth is the one to fear: it fails by
producing numbers that look right.

---

## 6. Physical MikroTik tests required

CHR narrows this; **it does not close it.** docs/31 §1.1: CHR has no radio, no RouterBOARD
serial and no factory-reset behaviour, so the bootstrap flow can only be proven on metal.

### 6a. On CHR (replaces the fake)

1. `/interface/wireguard/print` does not error
2. `/ip/service/print` shows `www-ssl`; enable it with a certificate
3. `GET /rest/system/resource` returns JSON
4. **REST over a self-signed certificate** — docs/30 §3b, unverified
5. `PATCH ip/hotspot/profile use-radius=yes` is accepted **and reads back applied**
6. The **exact maximum** `Mikrotik-Rate-Limit` the unit accepts
7. `ip/hotspot/active/remove` with `.id` actually removes
8. `interface` field names for throughput, and **which interface is WAN**
9. `/export` completeness after each step

### 6b. On metal only (the Phase 0 gate — docs/31/32)

| | |
|---|---|
| **A1–A14** | docs/32's full acceptance run: staging → ship → tunnel through **real CGNAT** → push over the tunnel → phone joins → voucher → `Access-Accept` → `radacct` Start/Interim/Stop → expiry with no intervention |
| **Test B** | CGNAT on the connection customers actually have |
| **Test C** | Power cut at each A8 step — recoverable? |
| **Test D** | **The watchdog, deliberately triggered.** An untested watchdog is a scheduled outage waiting for its condition |
| **Test E** | The customer presses reset |
| **docs/31 §7** | The compatibility matrix, filled by hand, per model × RouterOS version |
| **Timing** | Factory-reset unit to managed, behind real CGNAT, **under five minutes** — docs/30: if this fails, the product described does not exist |

---

## 7. The deployment decision still required

**docs/30's question 5, unanswered since that document was written:**

> *"Where would this run? The plugin lives in the uCRM container. The control plane,
> PostgreSQL, Redis, FreeRADIUS and the concentrator do not fit there, and a WireGuard
> concentrator needs a stable public endpoint."*

The service is **deployment-agnostic** — configuration is environment variables — so this
blocks deployment, not development. What must be decided:

| | |
|---|---|
| Host for the control plane + its PostgreSQL | Not the uCRM container |
| Where the **WireGuard concentrator** lives, and its stable public endpoint | Sized by **device count**, not traffic; horizontally scalable from day one |
| Whether Phase 0's `dn-phase0-*` containers on `209.97.137.203` become production, or are replaced | User instruction stands: **no EasyPanel service, no Docker/Swarm change** |
| Secret management for `DNB_SECRET_KEY` and `DNB_INTERNAL_TOKEN` | The key must **not** live beside the database it protects |
| Backup and restore for a database holding revenue records | |

---

## 8. Repository and commit state

**Repository:** `dishnetafrica/dishnetuganda` · **Branch:** `claude/study-this-jhe2eg` ·
**Head:** `1fd38e9`, pushed.

| Commit | Step |
|---|---|
| `41d0423` | docs/55 plan |
| `3053c29` | 1 — schema, RLS, audit, idempotency |
| `23347d4` | 2 — auth, `/me`, projection |
| `9a6b5ef` | 3 — intent queue |
| `1f226a0` | 4 — policy plane |
| `3a71efa` | 5 — vouchers |
| `561cd93` | 6 — sessions |
| `544f875` | 7 — devices (CHR half not met) |
| `1fd38e9` | 8 — telemetry |

**The control plane is a separate service living in this repository for review.** It has no
path dependency on the plugin. Extraction to its own repository, which docs/30 and your C20
answer both call for, is:

```bash
git subtree split --prefix=dishnet-mikrotik-control-plane -b control-plane
```

**Not yet done** — it was kept alongside the plan that produced it.

---

## 9. Customer PWA status

| | |
|---|---|
| **Design** | Complete — `prototype/dishnet-customer-pwa-prototype.html`, 13 screens, 244 checks |
| **Backing API** | **Built**: `/me`, `/me/services`, `/me/sites`, `/me/plans` (CRUD), `/me/vouchers`, `/me/sessions`, `/me/usage`, `/me/uplink`, `/me/intents`, `/me/entitlements` |
| **Wired to the API** | **NO.** The prototype still renders from its own mock objects |
| **Not built** | Owner vs staff roles (C6), voucher delivery to a guest (C7), billing/payment, support chat |

**The remaining work is front-end integration**, not backend. Every screen has an endpoint;
none of them calls one.

---

## 10. Admin Web status

| | |
|---|---|
| **Design** | Complete — `prototype/…-v2.html`, plus docs/42's screens and role matrix |
| **Backing API** | **NOT BUILT.** `/api/v1/admin/*` has **zero routes** |
| **What exists instead** | Admin *operations* exist as `SECURITY DEFINER` functions and repository methods — device register/assign/state, profiles, credentials — reachable from code and tests, **not over HTTP** |

**Admin Web is the largest single gap.** Everything DishNet staff need to do is
implemented as a function; none of it is exposed as an endpoint or a screen. Note this also
means **no HTTP route can currently reach those admin functions** — which is safe, and
also means provisioning is not yet operable by a person.

---

## 11. Production-ready code vs validated application logic

| | |
|---|---|
| **Logic validated, and the validation is meaningful** | Tenancy and isolation (PostgreSQL RLS, 66 assertions incl. enumerate/infer/modify); authentication and enumeration resistance; the intent state machine and crash recovery; plan validity; voucher uniqueness and single redemption **under real concurrency**; accounting ingest under retransmit, reorder and 32-bit wrap; audit append-only; credential sealing |
| **Logic validated against a fake — RouterOS behaviour NOT validated** | Everything in `RouterOsDelivery`, `RestClient` and `UplinkSampler`. §5.3 lists seven embedded guesses |
| **Not production-ready regardless of tests** | No admin HTTP surface (§10) · PWA not wired (§9) · no deployment target (§7) · no backup/restore · no secret management · no rate limiting outside the auth endpoint · no observability beyond `error_log` · async bulk voucher generation above 500 not built · no Redis (docs/30 assumes it for live session state) |
| **Explicitly NOT built** | BYO/adoption (docs/53 §4) · captive portal · payments · anything in Domain A |

### The one-sentence version

> **The application logic is well validated and the hardware integration is not validated at
> all.** Everything that can be proven without a MikroTik has been proven twice from a clean
> database; everything that needs one is a guess with a test that agrees with it.

---

## Recommended next step

The architecture-to-code audit you have called for, **before** any deployment decision —
with §5.3 as its highest-value target, since those seven assumptions are where the code and
the physical world are most likely to disagree.

*No production system was contacted, modified or deployed at any point in steps 1–8.*
