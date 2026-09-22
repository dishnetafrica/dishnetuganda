# 93 — v0.2: HotSpot, Vouchers, Sessions, and two approved projections

**Status:** BUILT, development/test only. Production untouched.
**Suite: 1,488 assertions green** (1,446 before). **No gate moved.**

Migration **021** adds the two projections approved against `docs/92` §6.2, and
nothing else. F6-B unauthorized, C-b undeployed, no Admin write route bound,
`DenyAllIdentity` still the production Admin identity.

---

## 1. Migration 021 — exactly two projections

The read boundary D-1/D-2 fixed at eleven becomes **thirteen**.

### `mt_admin_services()`

| Field | |
|---|---|
| exposes | `id`, `customer_id`, `kind`, `status`, `started_at`, `ended_at` |
| withholds | everything else on `mt_services` — there is nothing else |

`status` is what the control plane **recorded**. The migration, the serializer
and the test all say so in three different places, because the tempting misread
is exactly one step away: a service marked `active` does **not** mean a HotSpot
server is running. A test asserts `SignalReport` still reports HotSpot liveness
as `UNMEASURED` whatever `mt_admin_services()` returns, so the two cannot drift
into agreement by accident.

Deriving HotSpot liveness from the router's lifecycle state was **rejected** in
`docs/92` §3.1 and remains rejected.

### `mt_admin_voucher(uuid)`

| Field | |
|---|---|
| exposes | `id`, `customer_id`, `batch_id`, `plan_id`, `site_id`, `state`, `price_minor`, `currency`, `duration_s`, `created_at`, `activated_at`, `expires_at`, `revoked_at`, `sold_at` |
| withholds | **`code`**, and every AAA credential, RADIUS secret and raw redemption field |

**The column list is deliberately identical to `mt_admin_vouchers()`.** A detail
view that returned more than the list would be a way to reach a withheld field
by asking for one row at a time. A test compares the two key sets and requires
them equal.

### Discipline kept from 019

Owned by `dnb_def_admin`; `SECURITY DEFINER` with a pinned `search_path`;
`REVOKE ALL … FROM PUBLIC` then one `GRANT EXECUTE` to `dnb_adminapi`, executed
under `SET LOCAL ROLE` — because after `ALTER FUNCTION … OWNER` the migration
owner holds no grant option and PostgreSQL answers a non-owner `GRANT` with a
**warning, not an error**. Migration 019 shipped exactly that inert grant on its
first run.

The migration then **proves its own effect**: `dnb_adminapi` holds EXECUTE,
`dnb_app` does not, `dnb_adminapi` still holds no table privilege anywhere, and
neither new projection returns a column named `code`.

---

## 2. HotSpot

One card per HotSpot service, carrying the customer, the recorded service state,
its plans with prices in UGX, and voucher counts by state. RADIUS reads *not
measured*; active users read *not attributable per router*.

### 2.1 The voucher lifecycle, as the domain actually supports it

`src/Vouchers/LifecycleReport.php` declares it **server-side**, for the same
reason `SignalReport` does: a screen that drew the full commercial lifecycle
would imply the product does things it cannot.

```
Available now        unused     VoucherService::issueBatch
                     revoked    VoucherService::revoke, bound to the customer API

Not reachable        activating not in the mt_vouchers CHECK constraint (F-7, unauthorized)
                     active     redemption has no entry point; the AAA publisher
                                has never been built (Decisions 1, 3, 7)
                     expired    nothing anywhere writes it — intents have an
                                expiry sweep, vouchers have none
```

The screen leads with **"Activation lifecycle unavailable — the redemption entry
point and AAA publisher are not implemented."**

**No `active` or `expired` voucher was manufactured to fill the screen.** The
simulated estate still shows 17 vouchers, all `unused`, because that is the only
state the domain can currently reach.

---

## 3. Vouchers and Sessions

**Voucher list → detail.** Rows are clickable; detail shows reference, state,
customer, site, plan, price, duration and the lifecycle timestamps — and says in
plain words why the code is absent: *a code is a bearer credential, so it stays
out of every path but redemption.*

**Sessions** gains site and plan, **derived through the voucher** — `mt_sessions`
carries no `site_id`, and the join is done client-side from two lists Admin
already reads. It needs no new projection. The header states plainly that router
attribution is unavailable and that the accounting source is RADIUS.

**No username.** `mt_admin_sessions()` returns none, deliberately:
`radius_username` is an AAA identity and stays out of Admin.

---

## 4. The operator / engineer split

Requested, and the reason is right: a NOC operator should not have to understand
*why* a signal is unavailable in order to use the screen.

| Screen | Shows |
|---|---|
| **Router Detail** | the signal and a short verdict — `WireGuard tunnel — NOT MEASURED` — and one line: *why a signal is unavailable, and what it would take, is in Diagnostics* |
| **Diagnostics** | the full evidence: source, current limitation, and what would be required, plus the voucher lifecycle |

Both render from the **same server inventory**, so the two can never disagree.

### 4.1 `WAN interface` → `WAN interface assignment — RECORDED`

The old headline was misleading and the review was right to catch it. What is
measured is the interface **a person recorded at staging**, not the WAN. The
server now supplies a `verdict` word for that entry, so the panel cannot choose
it, and the reason text names it *staging metadata* explicitly.

---

## 5. Verification

**1,488 assertions green.** New coverage:

- both projections: EXECUTE to `dnb_adminapi`, refused to `dnb_app`, `SECURITY
  DEFINER`, pinned `search_path`;
- the column contract compared **programmatically** against `AdminProjection`,
  in both directions, now for thirteen projections;
- `mt_admin_services()` returns more than one customer's service — estate scope
  proven, not assumed — and carries no liveness field;
- **HotSpot stays `UNMEASURED` in `SignalReport`** whatever services reports;
- voucher detail returns **exactly** the list's keys, withholds `code`,
  `radius_username`, `secret` and `password`, and answers an unknown or
  non-uuid id with nothing rather than an error;
- the voucher fixture is **issued through the real service** inside the test,
  because a detail assertion against an empty estate would pass by proving
  nothing.

### 5.1 The claim guard was wrong, and is now stronger

The guard forbidding the panel from asserting router state matched this line:

> *"Whether a HotSpot server is **running** on a router is **not** observed…"*

which is the screen saying precisely the right thing. A denial is not a claim.
Two changes rather than a weakening:

1. the guard judges the **whole line**, so the negator that follows the verb is
   seen — a non-greedy match stopped before it;
2. it additionally requires that a signal's verdict word is rendered **from the
   server** (`${esc(verdictOf(x))}`) and that no hardcoded `>Connected`,
   `>Healthy`, `>Running` or `>Up<` appears anywhere.

The second half is the stronger test: it catches a fabricated verdict even when
the prose around it is innocent.

This is the sixth time a guard in this project has caught its own author. The
first five were comments; this one was rendered copy, so stripping comments
would not have helped.

---

## 6. What remains unavailable

Unchanged and still blocked: `activating`, the voucher expiry writer, real
redemption, the AAA publisher, voucher code exposure, D-4 uplink telemetry,
per-router session attribution, and every real RouterOS action. The production
census is still required, Q2 is still open, and F6-B is still not authorized.

`[Generate vouchers]` renders inert: the route exists and answers **501**.
