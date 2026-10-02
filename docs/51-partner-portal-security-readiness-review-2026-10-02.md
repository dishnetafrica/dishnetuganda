# WS-A P4 — security & readiness review (pause before P4e)

**Date:** 2026-10-02 · **Branch:** `claude/study-this-jhe2eg` · **Requested by:** operator, after the P4a–P4d report.
**Status:** review only. No code was written, nothing deployed, no portal enabled, no OTP sent, no real partner data touched.

This answers the operator's five review points, then lists the remaining blockers and a proposed P4e/P4f
sequence. It is read from the **current code at HEAD `70b2dd5`** (not from the earlier report's claims); where a
finding is carried from the `docs/47` audit rather than re-verified here, it says so.

---

## 0. What this review did and did not do

- **Did:** read the current code; ran the four targeted security suites and read the last full-suite result;
  verified the PD items against today's source; confirmed the production commit and the off-by-default gates.
- **Did NOT:** build P4e, change any file, deploy, enable the portal, connect a delivery provider, send an OTP,
  or read any real partner/customer data. All test data is synthetic (`SIM-`/temp SQLite).

---

## 1. Authentication readiness — the one-time code is not deliverable yet

**Finding — CONFIRMED (code).** The sign-in brain produces a code but nothing transmits it. `PartnerApi::handle`
calls `PartnerAuth::requestLoginCode`, which returns a `delivery` descriptor, and the entry file passes a **null**
delivery seam:

- `partner_api.php` — `$deliver = null; … PartnerApi::handle($pdo, $config, $tp, $req, $deliver);`
- `lib/PartnerApi.php` `auth.request_code` — `if ($deliver !== null && !empty($r['delivery'])) { $deliver(...); }`
  → with a null seam, nothing is called; the route still answers the uniform `{status:"sent"}`.

So **the passing auth tests prove the state machine, not that a distributor can receive a code.** `test_partner_auth`
and `test_partner_api` inject a capturing closure as the seam and read the code from it — they never send anything.

**What delivery requires (design, not built):**
1. A **delivery adapter** bound in `partner_api.php` instead of `null`. The code must go to the distributor's
   **already-verified** number — `dist_contacts` with `verified = 1`, resolved **server-side from the account**, never
   from the request. (The account's `phone` is the OTP key; `dist_contacts.verified` is the "we proved this number"
   flag.)
2. A **channel**. Options, in order of least risk: (a) the SMS path the Domain-B operator app uses is a *separate
   service*, not available here; (b) the plugin's own WhatsApp/Evolution sender (`NotificationService`) — but that is
   **Admin-approval-gated and currently bound to `NullWhatsAppChannel`** for distributors, and **Domain A's WhatsApp
   gateway may not be used without explicit authorisation**; (c) an SMS provider (e.g. the Africa's Talking pattern
   from Domain B) — a new dependency needing its own credential, sealed, provisioned at install, never in source.
   **No channel is chosen or wired, and this review does not choose one.**
3. **Failure handling** (to design): a send failure must not reveal whether the number exists (the response stays the
   uniform `{status:"sent"}`); the code row is already written, so a retry re-requests within the rate cap; a dead
   channel must fail closed (no sign-in), never fall back to exposing the code.

**Already built and verified (so delivery is the only missing piece of the mechanism):**
- **Rate limits** — per-account (`send_per_hour_user`, default 5) and per-address (`send_per_hour_ip`, default 20),
  hashed ledger, uniform throttle. `PartnerAuth::DEFAULTS`, test §F.
- **Replay** — the code is single-use: consumed only on full (code + TOTP) success; a replay of a consumed code is
  refused; a wrong code burns an attempt (`code_max_attempts`, default 5). `test_partner_auth` §D.
- **Lockout** — decaying, never permanent (`fail_threshold`→`lock_base` doubling, capped `lock_cap`). §F.
- **Second factor** — TOTP (RFC 6238, validated against the published vectors), required for every sign-in, enrolled
  on first use and confirmed before it can satisfy a login; a confirmed authenticator cannot be silently reset.
- **Account recovery — NOT designed.** If a distributor loses their authenticator there is today no recovery path
  (by design, `beginEnrol` refuses once confirmed). A **staff-side TOTP-reset** (audited, admin-only) is required
  before real use and is **a P4f/PD item**, not built.

> **Readiness verdict:** the authentication *logic* is complete and tested; **end-to-end sign-in is not possible**
> until a delivery channel is chosen, wired (replacing the null seam), and a staff-side authenticator-reset path
> exists. No real message may be sent during any of this without explicit authorisation.

---

## 2. PD-1 … PD-13 — staff-side security items (docs/47 §7.2)

Status read at HEAD `70b2dd5`. "Verified now" = I read today's code; "per audit" = carried from `docs/47`
(2026-09-30), not re-reproduced here. **Severity** is the staff-plane risk. **Blocks real partner access?** asks only
whether it must be resolved before a *real distributor* signs in to the new portal.

| PD | What | Status now | Evidence (file:line) | Sev | Blocks real partner access? |
|---|---|---|---|---|---|
| **PD-1** | Collections CSV export with no sign-in check | **FIXED (5.18.55)** — now `requireLogin()` + admin before any read | `includes/routes.php:266-274` (gate) before `:279` (load) | was High | **No — already closed in prod** |
| PD-2 | Staff JSON API permission check is module-based, ignores roles | **OPEN** (verified now) | `includes/api_handlers.php:94-98` (`$can` = `in_array($perm,$mods)`) | Med | **No** to the portal (separate dispatcher), **Yes** for staff-side distributor management |
| PD-3 | Staff login answer returns the whole account incl. `api_token` (+ reset token, uCRM app key) | **OPEN** (verified now) | `includes/api/api_public.php:13` (`'token'=>$found['api_token']`) | High | **No** to the portal (it returns no account; sign-in returns only `{ok:true}`), but a real staff-side G5 leak |
| PD-4 | Every staff page embeds the 90-day bearer token in a meta tag / inline script | OPEN (per audit) | `public.php:1683,1777` | High | **No** to the portal; staff-side |
| PD-5 | A tab with no `$_tabPerms` entry is open to any signed-in user | OPEN structural (per audit) | `public.php` tab dispatch | Med | **Partly** — the `distributors` staff tab must have an explicit perm entry (it also self-gates admin+Uganda+flag); confirm in P4f |
| **PD-6** | `customer_360` loads any uCRM client id from the request, no ownership check (the IDOR pattern) | **OPEN** (verified now) | `includes/api/api_retailer.php:613-616` (`$cid=(int)$_GET['cid']` → `clients/{$cid}`) | High | **No** to the portal — the portal's `me.link` uses composite-key scoping, the opposite pattern; it is the anti-pattern to never copy into P4e |
| PD-7 | Some stock API actions check no role; privileged stock roles include `sales` | OPEN (per audit) | `includes/api/api_stock.php:9-11,141-150` | Med | **No**; staff-side stock |
| **PD-8** | Staff JSON API sends `Access-Control-Allow-Origin: *`, no CSRF/Origin; session cookie `SameSite=None` | **OPEN** (verified now) | `includes/api_handlers.php:3`; `public.php:62` (`samesite=None`) | High | **No** to the portal — the partner API sets **no CORS header**, enforces its own custom-header + same-site-Origin rule, and uses a `SameSite=Strict` cookie (verified) |
| PD-9 | Reflected injection: `stock_tab` written into a script with only `addslashes` | **OPEN** (verified now) | `tabs/admin/stock_dashboard.php:9,868` | Med | **No**; staff-side |
| PD-10 | "Export deployed units" selects non-existent columns | OPEN, **not a risk** (broken) (per audit) | `includes/api/api_stock.php:382-395` | Info | No |
| PD-11 | Emergency-repair page falls back to a built-in key when no key file exists | OPEN, server-state-dependent (per audit; NV-13) | `public.php:236-241` | Med | No (not partner-facing); worth closing |
| PD-12 | `bc_portal` (South Sudan LTE) forwards any `table` with the server token, sweep found no sign-in check | OPEN (per audit) | `tabs/lte/bc_portal.php:38-77` | High (SS) | **No** to Uganda portal — **it is the existing partner-facing anti-pattern to not copy** |
| PD-13 | Cashbook approve/reject/void write no audit row | OPEN (per audit) | `lib/CashbookService.php:797-806,…` | Med | **No**; distribution approvals must not follow it |

**PD-1 specifically (the fix-now item):** **already remediated in production.** `includes/routes.php:266` begins the
export block and `:271-274` calls `$auth->requireLogin()` and enforces admin **before** `payment_collections.json`
is read at `:279`. The in-code comment attributes the fix to **5.18.55 / docs/48 PD-1**. Production is 5.18.65, so the
anonymous-export hole is closed in prod. (Recommended still, per the audit: a one-off look at the web access logs for
historical `col_export=csv` requests — operator action, not code.)

**The important distinction for the operator's concern:** PD-2…PD-9 are **staff-plane** gaps. The **new partner
portal is a separate surface** (its own entry `partner_api.php`, its own dispatcher `PartnerApi`, its own session
`PartnerSession`) that **structurally does not inherit them** — verified: it emits no `Access-Control-*`, never
includes `api_handlers.php`/`RetailerAuth`/`$can`, uses `SameSite=Strict`, and scopes every read from the session.
What the staff-plane items *do* affect is the **staff-side management of distributor data** (the Distributors admin
tab and `post_distributors.php`), which runs inside the staff plane and shares its posture. So: **none of PD-2…PD-13
blocks the portal's isolation**, but **PD-2, PD-5, PD-8 (staff-plane) and the delivery/recovery gaps are the real
"before a real distributor" set**, consistent with `docs/47` R-1 / Phase 0.

---

## 3. End-to-end isolation review — the partner API

**The whole partner surface is 8 declared actions** (`PartnerApi::ACTIONS`); an undeclared action is 404 (deny by
default) and never falls through to the staff API. Scope source, verified by reading `lib/PartnerApi.php` +
`lib/DistributorPortalData.php`:

| Action | Auth | Scope source | Request can name a partner/foreign id? |
|---|---|---|---|
| `auth.request_code` | public | — (operates by phone; uniform `{sent}`) | reveals nothing either way |
| `auth.sign_in` | public | — (phone+code+TOTP) | no partner data returned |
| `auth.enrol_begin` / `auth.enrol_confirm` | public, gated by a valid unconsumed code | the user the code belongs to | no |
| `me.profile` | session | `PartnerContext.partnerId()` → `myProfile()` (own row by id) | **no** — no "other partner" form exists |
| `me.customers` | session | `… WHERE partner_id = :ctx` | **no** — no partner_id parameter |
| `me.link` | session | composite `WHERE id=? AND partner_id=:ctx` | id is a **candidate**; a foreign id → 404 |
| `me.logout` | session | the caller's own token | no |

The six properties the operator named:

1. **Direct-object access / cross-partner id** — `me.link` is the only by-id read; it uses the composite predicate, so
   B's link id returns **404 for A**, indistinguishable from absent. Verified: `test_partner_api` §D and
   `test_dist_isolation` §C (with a non-vacuity control proving the row exists). **The scope comes from the session in
   every read; there is no read method that takes a partner id argument** — asserted by reflection in
   `test_dist_isolation` §A.
2. **Session revocation** — logout, disable and role/outlet change revoke on the row; `authenticate` also re-reads the
   live user every request, so a disabled account or changed role binds on the next call. Verified: `test_partner_session`
   §F/§G, `test_partner_api` §E (logged-out token → `revoked`).
3. **CSRF** — every POST needs the custom header `X-Requested-With: DishNet` **and** a same-site Origin; cookie is
   `SameSite=Strict`. Verified: `test_partner_api` §B (no header → 403; cross-site → 403).
4. **Unauthenticated access** — a session action with no token → 401; a random/foreign/staff bearer → 401 (`invalid`).
   Verified: `test_partner_api` §A/§E.
5. **Disabled-feature behaviour** — `partner_api.php` gates on Uganda (`StaffJobsGate`) + `distributors_enabled`; off
   or other tenant → **404, the surface does not exist**. Verified: `test_partner_api` §F (gate present) + the entry's
   `http_response_code(404)`.
6. **Allow-listed output** — answers project through `LINK_PUBLIC`/`PROFILE_PUBLIC`; staff identity (`assigned_by`) and
   billing linkage (`ucrm_client_id`, `tin`) are withheld; no uCRM app key is anywhere on the surface. Verified:
   `test_partner_api` §D, `test_dist_isolation` §D.

**For the *future* portal operations (P4e):** the only data path is `DistributorPortalData` through the `PartnerApi`
dispatcher. P4e must add **no new data path** — every screen reads through these same session-scoped methods. That is a
binding constraint on P4e, and the leak test's control-on-control (a weakened scope must fail the test) guards it.

**Honest limits of this evidence:** the isolation is proven at the **dispatcher + data-layer** level by automated
tests against synthetic data. It is **not** yet proven end-to-end through real HTTP (the entry file `partner_api.php`
is covered by reading, not by an HTTP test), and **no page layer exists yet**. A passing isolation unit test is not by
itself proof that every future page, export and notification-setting is isolated — that is exactly why P4e must route
only through this layer and add its own HTTP-level and per-screen tests.

---

## 4. Production & configuration boundaries

- **Production is plugin 5.18.65 at commit `ce3fa91`** (operator's deploy log, 2026-10-01 21:25 UTC, 18 checks /
  0 failed; serving `/home/unms/.../plugins/dishnet-hybrid-sudan`). The pilot flag was left **ON** by the operator;
  the deploy changed nothing about it and **nothing is sent** (bound channel is `NullWhatsAppChannel`).
- **None of the P4 partner-portal code is in production.** `ce3fa91` is an ancestor of HEAD; commits `8805505`
  (docs/50), `93da47e` (P4a), `1ac4723` (P4b), `a68dc11` (P4c), `70b2dd5` (P4d) are all **after** it and **unreleased**.
  The manifest `information.version` is **unchanged at 5.18.65** — P4 is internal foundation, not a release.
- **Off by default, in code.** The `?page=partner_api` route and `partner_api.php` both require
  `StaffJobsGate::applies` (Uganda) **and** `distributors_enabled`; South Sudan and flag-off get a plain 404. Migrations
  081/082 are additive and inert (no production reader). **No production configuration was or will be changed in this
  review.**

---

## 5. Test evidence (exact commands + results)

**Full suite** (run 4, after P4d, HEAD `70b2dd5`):
```
cd dishnet-hybrid-sudan/tests && ./run.sh
→ 259 test files, 11831 passed, 0 failed, 0 fatals   (grand total summed across files)
```

**Targeted partner-security suites, re-run now at HEAD `70b2dd5` for this review:**
```
cd dishnet-hybrid-sudan/tests
DN_VAULT_FILE=$(mktemp) php test_dist_isolation.php    → 39 passed, 0 failed
                         php test_partner_session.php  → 46 passed, 0 failed
                         php test_partner_auth.php      → 40 passed, 0 failed
                         php test_partner_api.php        → 32 passed, 0 failed
                                                   total  157 assertions, 0 failed
```

**Automated vs manual vs unverified — stated plainly:**
- **Automated:** isolation (scope-from-session, composite-key 404, allow-lists, control-on-control), session lifecycle
  (HMAC storage, expiry, revocation, live re-read, CSRF rule, cookie attributes), sign-in (RFC-6238 TOTP vectors,
  two-factor gate, anti-enumeration, decaying lock, fail-closed key), dispatcher (deny-by-default, method/auth guards,
  staff-token refusal, gate presence). PD-1 remediation and PD-2/3/6/8/9 current state were read in this review.
- **Manual review only (no automated/HTTP proof yet):** the entry file `partner_api.php` end-to-end over real HTTP;
  the per-screen isolation of P4e (no screens exist); the staff-side distributor-management posture under PD-2/5/8.
- **Unverified / assumption:** that a chosen delivery channel will preserve response uniformity and fail closed
  (no channel exists); production *runtime* behaviour of the gate (asserted by code + the 5.18.65 deploy checks, not
  re-probed here); the historical web-access-log look for `col_export=csv` (operator action).

---

## 6. Blockers before a real distributor signs in

1. **Delivery channel for the one-time code** — choose, wire (replace the null seam), fail closed, preserve
   uniformity. No live send without explicit authorisation. *(P4f / its own approval.)*
2. **Authenticator recovery** — an audited, admin-only TOTP reset. Without it a lost authenticator locks a distributor
   out permanently. *(P4f.)*
3. **Staff-plane hardening for distributor data** — at minimum PD-2 (role-aware API permission for the distributor
   actions), PD-5 (the `distributors` tab has an explicit perm entry), PD-8 (the staff API's CORS/CSRF posture) —
   because the distributor data is *managed* on the staff plane. *(docs/47 Phase 0; each change is behaviour-changing
   and needs its own approval + test + weakened copy.)*
4. **End-to-end + per-screen isolation tests** once P4e exists (HTTP-level, every page/route/export/notification
   setting), plus `test_dist_preserve` proving SS/Uganda-flag-off byte-for-byte unchanged and the route absent.
5. **Deploy rehearsal** — a pinned deploy script + harness (the project's standard), proving off-by-default and SS
   untouched, before any staging/production step. *(P4f.)*

**Not blockers for building P4e against synthetic data, off:** PD-1 (fixed), and the portal's own isolation (built +
tested). Building the read-only pages behind the flag, synthetic-only, is safe; **opening the portal to a real
distributor is a separate approval gate** after 1–5 above.

---

## 7. Proposed sequence (for approval — nothing started)

- **Decision first (operator):** which code-delivery channel (SMS provider vs plugin WhatsApp vs defer), and whether
  the staff-plane PD items are fixed **before** P4e or in parallel. My recommendation matches yours: **secure the
  authentication and staff-side boundaries first, then build the read-only portal against synthetic data; real
  distributor onboarding stays a separate gate.**
- **P4e (safe now, off, synthetic):** the read-only pages (`?page=partner` shell + static bundle, `script-src 'self'`,
  no inline script, security headers), reading **only** through the existing session-scoped `PartnerApi`; add HTTP-level
  and per-screen isolation tests + `test_dist_preserve`. No delivery, no real data.
- **P4f (gated):** pinned deploy script + rehearsal; close the PD blockers (each its own change/test/weakened copy);
  wire a delivery channel + authenticator recovery; then — and only on explicit approval — a staging rehearsal, and
  real-distributor onboarding as its own final gate.

**Awaiting explicit approval before any implementation resumes.** Uganda and South Sudan behaviour preserved; Domain B
untouched.
