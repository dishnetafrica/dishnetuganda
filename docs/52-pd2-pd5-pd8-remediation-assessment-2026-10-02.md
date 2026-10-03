# PD-2 / PD-5 / PD-8 — remediation assessment + OTP-delivery proposal (read-only)

**Date:** 2026-10-02 · **Branch:** `claude/study-this-jhe2eg` · **Requested by:** operator, after `docs/51`.
**Status:** assessment only. **No code, migration, config, record, or flag was changed; nothing deployed; the portal
stays disabled; Uganda and South Sudan behaviour preserved; Domain B untouched.** PD-1 remains **fixed in production**
(docs/51 §2). This does not expand to PD-4 or other PD items; dependencies are listed separately in §4.

Read from current code at HEAD `70b2dd5`. Each item follows the operator's seven points.

---

## Cross-cutting finding (important, verified)

**Neither PD-2 nor PD-5 currently reaches the distributor feature, and PD-8 reaches it only through the weak shared
CSRF token — not through the CORS-`*` JSON API.** Evidence:

- Distributor **writes** are form-POST handlers in `includes/post/post_distributors.php`; every `$act` block calls
  `$auth->requireAdmin()` **and** `StaffJobsGate::applies` (Uganda) **and** `!empty($config['distributors_enabled'])`
  (lines 12-16, 40-44, 74…). They are **not** JSON-API `$act` handlers, so the API `$can` of PD-2 never gates them.
- Distributor **tabs** are explicit in `$_tabPerms`: `'distributors' => '*admin'`, `'partner_applications' => '*admin'`
  (`public.php:2857-2858`), and `tabs/admin/distributors.php` **self-gates** (`if (empty($isAdmin)) return;` +
  `distributors_enabled`). So PD-5's "unlisted tab is open" does not apply to them.
- Distributor form writes are covered by the global form CSRF token (`post_distributors.php:6` notes the global gate
  covers `dist_appoint`) + `requireAdmin`.

**So PD-2 and PD-5 are platform-plane hygiene, not current distributor exposures.** They matter for the wider platform
and for *future* distributor surfaces (a distributor JSON-API action, or a new distributor tab added without a perm
entry), and PD-8 matters most because the staff plane shares an origin with the planned portal (D-9a). The partner
portal (P4a–P4d) already avoids all three (docs/51 §2). This refines docs/51's framing: **these three are valuable
hardening, but only PD-8's same-site defense is adjacent to the portal; none currently leaks distributor/partner data.**

---

## PD-2 — the staff JSON API's permission check ignores roles

**1. Current code path & demonstrated risk.**
- Page check — `public.php:2353` `$can`: `is_admin` → true; else `RbacService::canLegacy(role, mod)`; else the
  per-account `$retailer['modules']` override; else the legacy role defaults in `$ALL_MODULES[*]['roles']`.
  **Role-aware** (RBAC + role defaults + per-account override).
- API check — `includes/api_handlers.php:94-99` `$can`: `is_admin` → true; else
  `in_array($perm, $me2['modules'] ?? [])`. **Only the per-account `modules[]` override** — it consults neither RBAC
  role-permissions nor the `$ALL_MODULES` role defaults.
- **Risk (code-reading; not reproduced):** a staff member whose access to a module comes from their **role** (the
  common case — most accounts carry no explicit `modules[]` override) is allowed on the page but **denied at the API**
  for the same capability; and conversely, if an account's `modules[]` is broader than its role, the API **grants more
  than the role** would. It is an authorization **inconsistency in both directions**, not a straight escalation. Any
  new API action inherits it.

**2. Affected roles / modules / operations.** Every non-admin staff role (sales, support, support_leader, accountant,
field_*, collection, dealer) for **every JSON-API action that calls `$can`** — i.e. the post-auth handlers in
`includes/api/*.php`. **Not** admins (short-circuit true). **Not** the distributor feature (its writes are
`requireAdmin` form handlers, not `$can` API actions).

**3. Smallest safe remediation (preserving intended behaviour).** The clean fix is **one permission function used by
both planes**. But swapping the API `$can` for the page `$can` changes authorization for *every existing* API action
at once (it would start honouring RBAC + role defaults), so it is **not** a small change and risks both over- and
under-granting existing staff. Options, smallest first:
  - **(a) Forward rule only (zero regression):** leave the global API `$can` as-is; mandate that any *new*
    distributor/partner API action uses the page-equivalent check. Since there is **no distributor JSON-API action
    today**, this is a standard, not a fix, and costs nothing.
  - **(b) Union/superset align:** make the API `$can` return true when **either** the current modules test **or** the
    page `$can` would grant. This only *adds* API access to match what the user already has on the page (closes the
    "denied at API" half), and never removes access. Lower risk, but still a behaviour change requiring an action-by-
    action check that no API action was intentionally stricter than its page.
  - **(c) Full unification (one function):** highest value, highest regression surface; needs the full matrix below.
  **Recommendation:** treat PD-2 as its own hardening task built on an **authorization matrix** (every API action ×
  every role, current vs intended), then do (c); adopt (a) immediately as the rule for the portal/distributor work.
  **It is not on the portal's critical path.**

**4. Does it affect distributor management or UG/SS workflows?** **Distributor management: no** (form handlers,
`requireAdmin`). **UG/SS staff workflows: yes, platform-wide** — any change to `$can` touches every staff JSON-API
call in both tenants, which is exactly why it needs the matrix + regression tests and is behaviour-changing.

**5. Required tests.** Positive: each role reaches the API actions its role grants on the page. Negative: each role is
refused the API actions its role is refused on the page. Regression: a representative action per role unchanged before/
after; SS staff unaffected. Weakened-copy: revert the unified check to modules-only and show the page/API parity tests
fail. **Control-on-control:** show the parity test fails when a role's permission is removed.

**6. Compatibility / migration / session / API / client impact.** No migration. No session/cookie change. **API
behaviour changes** for role-based users (the point) — any external caller (n8n/Evolution) that hits a `$can`-gated
action could see a different allow/deny; those callers are key/bearer-gated and mostly admin-context, so impact is
likely nil but must be checked in the matrix. Client (staff PWA) unaffected (it already respects the page perms).

**7. Sequence / rollback / approval.** Sequence: build the matrix (read-only) → agree intended state per action →
implement (c) with tests → rehearse. Rollback: single-commit revert of the `$can` change restores modules-only.
**Explicit approval required** before any code: this is behaviour-changing platform-wide and should be its own change,
separate from the portal.

---

## PD-5 — a tab with no `$_tabPerms` entry is open to any signed-in user

**1. Current code path & risk.** `public.php:~2957` (dispatch): `$perm = $_tabPerms[$tab] ?? null; $allowed = true;`
then it only *narrows* `$allowed` inside `if ($perm !== null)`. So a tab present in `$_tabFiles` but **absent from
`$_tabPerms`** stays `$allowed = true` for **any authenticated staff**. Risk: a future admin-only screen added to
`$_tabFiles` without a matching `$_tabPerms` entry is visible to every signed-in user.

**2. Affected roles / modules / operations.** Any authenticated non-admin role, for any `$_tabFiles` tab lacking a
`$_tabPerms` entry. **The distributor tabs are NOT affected** — `distributors` and `partner_applications` are explicit
`*admin` (`public.php:2857-2858`) and self-gate in-file.

**3. Smallest safe remediation.** **Do not flip the global default to deny in one step** — some tabs may intentionally
rely on the default-allow, so a blanket flip risks hiding legitimate screens. Smallest safe path:
  - **(a) A guard test (zero runtime change):** assert every key in `$_tabFiles` has an explicit `$_tabPerms` entry.
    This is cheap, catches the gap, and **protects future distributor tabs** without changing behaviour.
  - **(b) Enumerate & list:** add an explicit `$_tabPerms` entry for each currently-unlisted tab, set to its *current
    effective* access (`true` for genuinely all-staff tabs; the right perm/`*admin` otherwise) — a no-op to real access.
  - **(c) Then** flip the default (`$perm === null` ⇒ deny, e.g. `*admin`) as a belt-and-suspenders, once (a)+(b) prove
    nothing legitimate relies on the default.
  **Recommendation:** (a) now (even before P4e, as a standing guard), (b)+(c) as a small reviewed change.

**4. Does it affect distributor management or UG/SS workflows?** **Distributor management: no** (explicit `*admin`).
**UG/SS: yes if (c) is applied** — flipping the default could hide any tab that currently relies on default-allow in
either tenant, which is why (b) must precede (c) with the golden SS test.

**5. Required tests.** Positive: a listed `*admin` tab renders for admin. Negative: a non-admin is denied an `*admin`
tab, and (after (c)) an unlisted tab is denied. Regression: every currently-reachable tab still reaches the same roles
(UG + SS golden). Weakened-copy: remove a tab's `$_tabPerms` entry and show the guard test (a) fails. Control-on-
control: the guard fails when a real `$_tabFiles` key is added without an entry.

**6. Compatibility / migration / session / API / client impact.** No migration, session, API, or client change. Only
the staff tab-visibility default changes (step (c)); (a) is test-only, (b) is access-preserving.

**7. Sequence / rollback / approval.** (a) guard test → (b) enumerate entries → (c) default-deny. Rollback: revert the
dispatch default; (a)/(b) are inert to behaviour. **Explicit approval** for (b)/(c); (a) is a test and low-risk but
still awaits your go-ahead per your instruction.

---

## PD-8 — JSON API `Access-Control-Allow-Origin: *`, no CSRF/Origin; one shared daily CSRF token

**1. Current code path & risk.**
- **CORS / JSON API:** `includes/api_handlers.php:3-6` sends `Access-Control-Allow-Origin: *`,
  `Allow-Methods: GET,POST,OPTIONS`, `Allow-Headers: Authorization,Content-Type,Accept`, and `OPTIONS → 204`. No
  Origin/`Sec-Fetch-Site` check anywhere in the dispatcher.
- **Session cookie:** `public.php:57-63` sets the PHP session cookie `SameSite=None; Secure; HttpOnly` (deliberate —
  the plugin renders inside the uCRM **iframe**, where `Lax` would drop POST cookies).
- **Form CSRF:** `public.php:90-108` — a **stateless** token `date('Ymd') . ':' . HMAC(secret,'csrf:'.day)`, verified
  by `csrfCheck()` accepting **today or yesterday**. It is **not bound to a user or session** ("one daily value shared
  by everyone"), by design (a per-uid token mismatched across the iframe GET/POST).
- **Risk (code-reading):** `SameSite=None` means the session cookie **is sent cross-site**; the JSON API applies **no
  Origin/CSRF check**, so a malicious site a logged-in staff member visits can call cookie-authenticated JSON-API
  actions **and read the responses** (CORS `*`). The shared daily CSRF token protects form POSTs only weakly (it is the
  same value for everyone that day; its secrecy is the only barrier, and it is embedded in every rendered page).

**2. Affected roles / modules / operations.** All authenticated staff (cookie-authed), for cookie-authed JSON-API
actions and form POSTs, in both tenants. **Distributor writes** use the form path + `requireAdmin` + the shared token,
so they inherit the *weak-token* property but not the CORS-`*` JSON read path. **This is the item most adjacent to the
portal**, because D-9a puts the portal on the **same origin** as these staff pages.

**3. Smallest safe remediation (preserving the iframe).** The iframe needs `SameSite=None` and the stateless token —
**do not revert either** (both were deliberate fixes). The clean, low-regression defense is the one the partner portal
already uses:
  - **(a) Enforce a same-site Origin / `Sec-Fetch-Site` check on mutating requests** (cookie-authenticated POSTs —
    forms and JSON). A cross-origin page cannot forge a matching `Origin`, so this defeats CSRF **regardless of the
    shared token** and without touching `SameSite=None`. Bearer/app-key-authenticated integration calls (n8n/Evolution
    `customer_context`, etc.) are **exempt** (they are not cookie-authed), so those flows are unaffected.
  - **(b) Stop sending `Access-Control-Allow-Origin: *` for cookie-authenticated actions** (keep it only, if at all,
    for explicitly public/key-gated read actions). This stops cross-origin reading of cookie-authed responses.
  - **(c) Leave the stateless token as a second factor** (do not make it per-user — that regressed before).
  **Recommendation:** (a) is the core fix and matches the portal's rule exactly; (b) narrows CORS; (c) unchanged.

**4. Does it affect distributor management or UG/SS workflows?** **Distributor management: indirectly** — its form
writes gain real CSRF protection from (a). **UG/SS: yes, platform-wide**, and **the iframe + n8n/Evolution flows are
the regression risk**: (a) must exempt non-cookie (bearer/app-key) calls, and (b) must not break the uCRM iframe's own
same-origin calls or the key-gated integration reads. Both tenants must be verified.

**5. Required tests.** Positive: a same-site cookie POST (form + JSON) succeeds; a bearer/app-key call succeeds
unchanged. Negative: a cross-origin cookie POST is refused (missing/foreign Origin); a cross-origin read of a
cookie-authed action is refused. Regression: the uCRM-iframe path and the n8n `customer_context` key path both still
work; SS unaffected. Weakened-copy: remove the Origin check and show the CSRF negative test fails; remove the CORS
narrowing and show the cross-origin-read test fails. Control-on-control: the negative test fails when the Origin allow-
list is emptied.

**6. Compatibility / migration / session / API / client impact.** No migration. **Session cookie unchanged**
(`SameSite=None` kept). **API clients:** cookie-authed cross-origin callers (should be none legitimately) break by
design; **bearer/app-key callers unaffected** — this must be confirmed against every integration (n8n, Evolution,
the staff PWA, the uCRM iframe). Client (staff PWA): same-origin, unaffected.

**7. Sequence / rollback / approval.** Inventory every cookie-authed vs bearer/app-key API caller (read-only) → add the
Origin check with the integration exemptions + tests → narrow CORS → rehearse in both tenants. Rollback: revert the
dispatcher header/Origin change (single commit). **Explicit approval required**; this touches the shared staff API and
the iframe, so it is the highest-care of the three.

---

## 4. Dependencies and scope notes (not silently expanded)

- **PD-3** (login answer returns `api_token`/reset token/app key, `api/api_public.php:13`) and **PD-4** (bearer token
  in a page meta tag) are **related to PD-8** (they are the G5 "no privileged credential in a browser" theme) but are
  **separate items**; this assessment does **not** include them. If PD-8's CORS narrowing is done, PD-3/PD-4 remain
  independently open and should be their own task.
- **PD-6** (the `customer_360` IDOR pattern) is the anti-pattern the portal already avoids; **not** in this scope.
- **RbacService** is the dependency for any PD-2 unification (the page already uses it); its tables must exist (the page
  `$can` already try/catches their absence).
- **No dependency on the portal** for PD-2/PD-5; PD-8 is adjacent (shared origin) but independent.

---

## 5. OTP delivery & TOTP recovery — proposal only (no provider, no messages)

Non-implementing. No live provider is connected and no message is sent. This proposes the design for your decision.

### 5.1 Delivery options (to the distributor's **verified** number)

| Option | What | Pros | Cons / cost / ops |
|---|---|---|---|
| **A. SMS provider** (e.g. Africa's Talking, the Domain-B pattern) | A new outbound SMS adapter, credential sealed + provisioned at install | Purpose-built for OTP; independent of WhatsApp; works for any phone | A new paid dependency (per-SMS cost); a new credential to manage; delivery/latency varies by carrier; needs a sandbox for testing |
| **B. Plugin WhatsApp (Evolution)** | Reuse the plugin's `NotificationService` / Evolution sender | No new provider; already in the plugin | Currently **Admin-approval-gated and bound to `NullWhatsAppChannel`** for distributors; WhatsApp OTP has deliverability/Meta-policy caveats; couples sign-in to the WhatsApp number's health |
| **C. Defer (manual)** | No automated send in the pilot; a DishNet admin reads/sets the first code out-of-band | Zero new dependency for a 1-distributor pilot | **Violates "never show an OTP to staff"** if done naively; not scalable; not recommended beyond a controlled bootstrap |

**Recommendation:** **Option A (SMS)** for a real distributor, because it is independent of the WhatsApp number and is
the established Domain-B pattern; **but do not connect it** until you approve a provider and a test sandbox. In the
meantime P4e can be built and demonstrated with the **null seam** (nothing sent) exactly as now. **Domain A's WhatsApp
gateway stays off-limits without explicit authorisation.**

### 5.2 Verified-recipient enforcement

- The code is sent **only** to a `dist_contacts` row with `verified = 1`, resolved **server-side from the signed-in-
  attempt's account** (`dist_partner_users.phone` → the partner's verified contact), **never** from a request field.
- If the account has no verified number, `requestLoginCode` still returns the uniform `{status:"sent"}` and the seam is
  simply not invoked — **no disclosure** that the number is unknown/unverified.
- Verification of a distributor's number (`dist_contact_verify`) is an **existing admin action** (`post_distributors.php`)
  — it is the human step that makes a number eligible to receive a code.

### 5.3 Failure handling

- A send failure (provider error/timeout) **must not change the response** — the client still sees `{status:"sent"}`;
  the code row is already written; the distributor can re-request within the rate cap.
- A **dead/unconfigured channel fails closed**: no code goes out, sign-in cannot complete, and **the code is never
  surfaced to staff or logs**. No fallback that exposes the code.
- Rate/replay/lockout are **already built and tested** (docs/51 §1): per-account + per-IP send caps, single-use code,
  decaying lockout, uniform anti-enumeration.

### 5.4 TOTP recovery (today there is none — a gap)

- Today `beginEnrol` refuses once an authenticator is confirmed, so a lost device locks the distributor out
  permanently. Proposed: a **staff-side, admin-only, audited TOTP reset** (clears `totp_secret` + `totp_confirmed`,
  revokes the account's live sessions, writes an audit row with the acting admin), after which the distributor
  re-enrols on next sign-in (which still requires a fresh login code). **No self-service reset** (it would be a second
  account-takeover path). This mirrors the W-1 pattern (actor = the admin from the identity boundary).

### 5.5 Audit requirements

- Every code **request**, **sign-in success/failure**, **enrolment**, **TOTP reset**, and **account disable/role
  change** writes an audit row. **The code itself and the TOTP secret are never written to the audit log or any log**
  (consistent with the standing "never log an OTP" rule and the existing OTP-log-privacy posture). Rate-limit hits and
  lockouts are recorded as counters/outcomes, not with the code.

### 5.6 What is needed from you before any of this is built

1. **Channel decision** (A/B/C) and, for A, a provider + a test sandbox (no production credential yet).
2. Approval to design the **TOTP-reset** admin action (staff-side; its own small change + test).
3. Confirmation that OTP delivery stays **off** (null seam) through P4e, and that a real send is a **separate** later
   gate.

---

## 6. Proposed order (for your approval — nothing started)

1. **PD-8 first** (same-site Origin check + CORS narrowing) — it is the one adjacent to the portal's shared origin and
   hardens the distributor form writes; build on an integration-caller inventory, with the iframe/n8n regression tests.
2. **PD-5 guard test now**, then enumerate `$_tabPerms` entries, then default-deny — low risk, protects future
   distributor tabs.
3. **PD-2** as its own matrix-backed change — valuable platform-wide but **not** on the portal's path; can run in
   parallel or after.
4. **OTP channel decision + TOTP-reset design** — in parallel; no provider connected.
5. **Then** P4e (read-only pages, off, synthetic) per docs/51 §7; real distributor access remains a separate final gate.

**Each change above is behaviour-changing on the staff plane and requires its own explicit approval, its own tests
(positive/negative/regression/weakened-copy), and its own UG+SS rehearsal before it is written.** I have changed
nothing and await your direction on which item to take first.
