# PD-8 — staff JSON API integration-caller inventory + remediation design (read-only)

**Date:** 2026-10-02 · **Branch:** `claude/study-this-jhe2eg` · **HEAD:** `8227fc8` · **Requested by:** operator, as the next
work package after `docs/53` (OTP, commit `8227fc8`).
**Status:** INVENTORY + DESIGN ONLY. **No code, migration, config, record, or flag was changed; nothing was deployed; no
PD-8/PD-5/PD-2 fix was implemented; no P4e work; the OTP sender stays unwired; no OTP was sent and no provider was
contacted; the portal stays disabled.** Uganda and South Sudan behaviour preserved; Domain B untouched. **PD-1 remains
recorded as fixed in production (`docs/51` §2); this task does not re-verify it and claims no independent verification.**

Working-tree/flag state **read from code** (not production): tree clean at `8227fc8`; `distributors_enabled` is read as
`!empty($config['distributors_enabled'])` and is **off by default** (`partner_api.php:47`, `tabs/admin/distributors.php:22`,
`includes/navigation.php:527`). The production value is **not inferable from development code** and is not asserted here.

Facts below are marked **[CV]** (code-verified, with file:line) or **[RV]** (runtime-verification-required). Some browser
CORS/cookie behaviour is marked **[browser-semantics]** — standard and well-documented, but confirm empirically on the
real deployment. Two read-only sub-investigations (browser callers; integration callers) and my own reads back every
**[CV]** item.

---

## 1. The three staff-API surfaces (so "the staff API" is not treated as one thing) [CV]

| Surface | Entry | Reached as | Auth | Cookie fallback? | CORS |
|---|---|---|---|---|---|
| **A. Legacy JSON API** (the PD-8 subject) | `includes/api_handlers.php` (via `public.php:912→950`) | `public.php?page=api&action=<a>` | Bearer **or** session cookie | **YES — `$_SESSION['kyc_retailer']`** (`api_handlers.php:78-85`) | `Access-Control-Allow-Origin: *` (`:3`) |
| **B. Stock API** (a sibling with the **same gap**) | `includes/routes.php:1101` | `public.php?page=stock_api&action=<a>` | Bearer **or** session (`requireLogin()`) | **YES** (`routes.php:1108-1113`) | `Access-Control-Allow-Origin: *` (`routes.php:1102`) |
| **C. v1 / v2 REST** | `api/index.php`, `api/v2/router.php` | those paths directly (native app) | **Bearer / JWT only** | **NO** (`api/index.php:186`, `api/v2/router.php:138-156`; no `_SESSION`/`kyc_retailer`) | `PWA_ORIGIN` env, **`*` fallback** (`api/index.php:37-38`, `api/v2/router.php:40-42`) |

**The single most important fact:** the **only cookie-authenticated paths into any staff API are surface A
(`kyc_retailer`) and its twin surface B (`stock_api`).** Surface C is Bearer-only. Every non-browser integration
(§3) is Bearer / app-key / URL-token / a separate webhook entry / direct PHP — **none sends the staff cookie.** So a
same-site Origin check scoped to cookie-authenticated requests has, by construction, **zero integration blast radius**;
the only callers it touches are the **browser staff UI** and the **uCRM iframe** it runs in.

**Not staff-API entries (separate, non-cookie — listed so they aren't mistaken for gaps) [CV]:** `evo_webhook.php`
(`page=evo_webhook`, `EvoWebhookGuard` secret), `wa_webhook.php` (`page=wa_webhook`, `wa_webhook_secret`), `webhook.php`
(uCRM event, crm-webhook key), `dn_resolve_owner`/`dn_resolve_kits` (`public.php:150-211`, `InternalAuth` loopback),
`dpo_push.php`/`dpo_return.php`, and the public website endpoints `prices.php`/`shop.php`/`web_chat.php`
(their own CORS, no staff cookie).

---

## 2. The PD-8 gap, stated precisely (refining `docs/52`) [CV + browser-semantics]

On surface A (and B):
- **No CSRF check and no Origin/`Sec-Fetch-Site` check on the JSON path.** Every `csrfCheck()` caller in the repo is a
  **form/tab POST handler** (`tabs/**`, `includes/post/**`); **none** is in `api_handlers.php` or `includes/api/*.php`
  (verified: 40 `csrfCheck()` call sites, all form handlers). So the JSON API has *no* CSRF control at all — weaker even
  than the form path, which at least carries the stateless `_csrf` token.
- **Session cookie is `SameSite=None; Secure; HttpOnly`** (`public.php:57-63`) — deliberate, because the plugin renders
  in the uCRM **iframe** where `Lax` drops POST cookies. **Do not revert** (a prior fix).
- **`Access-Control-Allow-Origin: *`**, methods `GET,POST,OPTIONS`, `OPTIONS → 204` (`api_handlers.php:3-6`).

**Honest refinement of the risk (this corrects `docs/52` §PD-8 point 1, which said an attacker can "read the
responses"):** `Allow-Origin: *` is sent **without** `Access-Control-Allow-Credentials: true`. Per standard CORS
[browser-semantics]:
- A **credentialed** cross-origin read (`fetch(url,{credentials:'include'})`) has its **response blocked** from the
  attacker's JS (the `*`+credentials combination is not readable), and a **non-simple** credentialed request (JSON
  content-type or a custom header) is **blocked at preflight** (the 204 returns `*`, not a specific origin +
  `Allow-Credentials`). So cross-origin *reading* of a cookie-authed response, and credentialed cross-origin JSON POSTs,
  are **already largely prevented by the browser** — not by the plugin.
- The **real, live exposure is CSRF via a "simple" cross-site request**: because the cookie is `SameSite=None`, a
  cross-site page can issue a **GET**, or a **POST with `text/plain` / `application/x-www-form-urlencoded` /
  `multipart/form-data`** (no preflight), the browser attaches the cookie, and the server **executes the action**
  (the attacker cannot read the result, but a state change is a state change). Handlers that read `$_POST` **or**
  `json_decode(php://input)` are both reachable this way (`json_decode` ignores Content-Type).
  - **Concrete in-tree example:** caller #13 below (`includes/widgets/kyc_funnel.php:157/170`) POSTs **FormData**
    (`multipart/form-data` = a simple request, no preflight, no Bearer) to `kyc_exclude`/`kyc_restore` — a cookie-authed
    state change reachable cross-site today.

**Consequence for the fix:** the **same-site Origin/`Sec-Fetch-Site` check is the core remediation** (it closes the
simple-request CSRF hole regardless of content-type); **narrowing CORS is secondary** defence-in-depth (it does not
today enable reads, but it removes the `*` footgun and lets a future credentialed read be controlled). A per-action
"simple-request-reachable × state-changing" classification is a recommended input to the test matrix (§6).

---

## 3. Caller inventory

Legend — **Scope:** `COOKIE` = authenticates via the staff session cookie on surface A/B ⇒ **in scope** for the Origin
check; `EXEMPT` = Bearer / app-key / URL-token / separate entry / direct PHP ⇒ the Origin check must not affect it.

### 3a. Non-browser / integration callers — **all EXEMPT** [CV unless noted]

| # | Caller | Entry / action | Auth mechanism (file:line) | Browser? | Sends staff cookie? | Scope |
|---|---|---|---|---|---|---|
| I1 | **n8n Uganda AI bot** | A · `customer_context` (GET, read-only) | `hash_equals($config['webhook_secret'], ?key= \| X-DISHNET-KEY)` — `api_public.php:38-42`; caller `n8n/DishNet_Uganda_AI_Bot_v1.0.json:2999` | No (server) | No | **EXEMPT** (pre-auth, key-gated, GET) |
| I2 | **Evolution WhatsApp inbound** | `evo_webhook.php` (**not** A) | URL/header secret `evo_webhook_secret`, `EvoWebhookGuard` (`evo_webhook.php:65-73`, `lib/EvoWebhookGuard.php:107-127`) | No | No | **EXEMPT** (separate entry) |
| I3 | **Evolution fetches quote PDF** | A-adjacent · `serve_quote_pdf` (GET) | URL token `QuotePdfToken::verify` HMAC(day) (`api_public_files.php:60-86`, `lib/QuotePdfToken.php:136-157`) | No (server fetch) | No | **EXEMPT** (pre-auth, URL token) |
| I4 | **WASender fetches delivery/receipt PDF** | A-adjacent · `serve_delivery_pdf`, `serve_receipt_pdf` (GET) | URL token `PdfLinkToken::verify` HMAC (`api_public_files.php:91-157`, `lib/PdfLinkToken.php:103-146`) | No | No | **EXEMPT** (pre-auth, URL token) |
| I5 | **Legacy WASender inbound** | `wa_webhook.php` (**not** A) | `wa_webhook_secret` header/`?secret=` (`wa_webhook.php:84-100`) | No | No | **EXEMPT** (separate entry) |
| I6 | **`dishnet_wa_pusher` (Pusher)** | → `wa_webhook.php` | same `wa_webhook_secret` (`dishnet_wa_pusher.php:41/44`) | No (cron, separate WA host) | No | **EXEMPT** (feeds a webhook, not A) |
| I7 | **Customer PWA `app_*`** | A · `api_customer_app.php` | Customer-JWT Bearer **or** `dn_customer_session` cookie (a **different** cookie, not `kyc_retailer`) — `api_customer_app.php:391-409`, `lib/CustomerSession.php` | Yes (PWA) | **No** (never the staff cookie) | **EXEMPT** from the staff check; the customer cookie path **already self-guards** (§3c) |
| I8 | **Customer PWA `dpo_*`** | A · `api_dpo.php` | same `ca_require_auth` (`api_dpo.php:21/58`) | Yes | No | **EXEMPT** (same as I7) |
| I9 | **Cron / tools** | — | **No HTTP caller of A/B** — cron/tools call PHP directly (`cron/efris_sync.php:21`, `cron/master.php:3`) | — | — | **N/A** (not an HTTP caller) |

### 3b. Browser callers of surface A — the **only in-scope** class [CV unless noted]

There is **no single shared staff `apiCall()` wrapper**; each tab copy-pastes its own `api()`/`apiGet()`/`apiPost()`
idiom, and there are ~100+ inline `fetch()` calls. **All observed staff calls set `credentials:'same-origin'`** (the
`SameSite=None` cookie therefore rides along on essentially every request) and use **relative** URLs
(`?page=api&action=…`), so the browser's `Origin` is the **plugin document's own origin** (the iframe `src` origin),
not a hard-coded uCRM origin. Representative evidence (distinct patterns):

| # | File:line | Action(s) · method | Bearer token source | Server-side auth | Scope |
|---|---|---|---|---|---|
| B1 | `public.php:1801` | `change_password` POST | real, inline `$retailer['api_token']` (1804) | Bearer | (cookie also rides along) |
| B2 | `public.php:3198-3199` | `run_wallet_sync` POST | **empty** — reads `meta[name=api-token]` which is **never emitted** | **cookie** | **COOKIE** |
| B3 | `tabs/sales/all_apps.php:142-143` | `kyc_update_username` POST | real, `meta[name=dishnet-token]` | Bearer | — |
| B4 | `tabs/sales/kyc_form.php:122,186,262` | `check_phone/name_duplicate` GET | real, `_kycApiToken` | Bearer | — |
| B5 | `tabs/sales/kyc_form.php:423/668` | `lte_create_subscriber` POST, `lte_packages` GET | **empty** `meta[name=api-token]` | **cookie** | **COOKIE** |
| B6 | `tabs/support/splynx_noc.php:160,333-467` | `noc_*`, `splynx_*` POST | real, `API_TOKEN` | Bearer | — |
| B7 | `tabs/support/scheduling.php:155-161,490-502` | many GET+POST | real, `TOKEN`=`$apiToken` | Bearer | — |
| B8 | `tabs/support/bulk_dispatch.php:239-249` | many GET+POST | real, `TOKEN` | Bearer | — |
| B9 | `tabs/support/customer_lookup.php:226-228,435` | lookup GET, `wa_send_quote_pdf` POST | real, `TK` | Bearer | — |
| B10 | `tabs/lte/lte_dashboard.php:482/995` | LTE reads GET | real, from JS-readable `hybrid_token` cookie | Bearer | — |
| B11 | `tabs/lte/lte_dashboard.php:2102`, `tabs/lte/lte_bluecard.php` (5×) | LTE actions | **empty** `meta[name=api-token]` | **cookie** | **COOKIE** |
| B12 | `tabs/admin/app_logins.php:339` | `staff_revoke_customer_sessions` POST | **none** | **cookie** | **COOKIE** (already sends `X-Requested-With: DishNet`) |
| B13 | `includes/widgets/kyc_funnel.php:157/170` | `kyc_exclude`/`kyc_restore` POST (**FormData**) | **none** | **cookie** | **COOKIE** (simple request — concrete CSRF vector, §2) |
| B14 | `tabs/admin/starlink_pauses.php:269-300,789` | GET+POST | none (cookie) — uses `credentials:'include'` (outlier); also hits a separate `DR_BASE` service | **cookie** | **COOKIE**; `DR_BASE` same-origin? **[RV]** |

**Standalone PWAs (Bearer-from-storage, not the iframe):** `retailer/index.html` (localStorage `dn_token`) and
`pwa/support.html` (`dn_tok`) — Bearer, no cookie reliance; whether they are served same-origin with the plugin is **[RV]**.

**Verified linchpin [CV]:** `meta[name="api-token"]` is **read** at 9 sites but **never emitted with `content=`**
anywhere (the only emitted token meta is `dishnet-token`, `public.php:1710`). So B2/B5/B11 send an **empty** Bearer and
are authenticated by the **cookie**; B12/B13 send no Bearer at all. These are unambiguous **cookie-authenticated
mutating requests** — the exact targets PD-8 must protect without breaking the legitimate same-origin iframe.

### 3c. Existing same-site controls already in the codebase (the fix is not greenfield) [CV]

- `lib/CustomerSession.php:100-121` — `crossSite()`: rejects `Sec-Fetch-Site: cross-site` and `Origin`-host ≠ `Host`;
  `authenticate()` requires `X-Requested-With: DishNet` on non-GET cookie requests. **This is the proven template.**
- `lib/PartnerSession.php` — the partner portal's identical `crossSite()` + `X-Requested-With` rule (docs/51).
- `includes/api/api_crm_sync.php:17-22` — an existing **Referer-vs-Host** same-origin check for the UISP-admin case.
- `api/index.php:1251` — token-in-`?token=` for `<audio>` embedding (Bearer-equivalent, not a cookie).
- `dishnet-mikrotik-control-plane/src/Admin/Csrf.php:38` — Domain B's `Sec-Fetch-Site` check (out of scope; shows the
  house pattern).

---

## 4. Smallest safe PD-8 remediation (recommendation — NOT implemented here)

Preserve both deliberate fixes: **keep `SameSite=None`** and **keep the stateless `_csrf` token**. The low-regression
defence is the one the customer and partner portals already use.

- **(a) CORE — a same-site guard on COOKIE-authenticated mutating requests to surface A, keyed off the server's auth
  outcome, not the cookie's presence.** In `api_handlers.php`, *after* the auth block, when the request authenticated
  via the **session fallback** (i.e. `tokenAuth()` returned null and `$_SESSION['kyc_retailer']` was used) **and** the
  method is not GET/HEAD: reject when `Sec-Fetch-Site: cross-site`, or when an `Origin`/`Referer` host is present and does
  not match an allowed origin. **Bearer-authenticated requests skip the check entirely** (so every integration in §3a,
  which is Bearer/key/URL-token, is untouched). Reuse `CustomerSession::crossSite()`'s logic rather than inventing a new
  one. **This is the fix; it closes the simple-request CSRF hole (§2) regardless of the shared token.**
  - **Why "keyed off the auth outcome" matters [CV, Agent-A subtlety]:** the cookie rides along on ~every staff call
    (`credentials:'same-origin'`), so "has a cookie" is not "is cookie-authenticated." The guard must trigger only when
    the cookie was the *credential that authenticated the request* — exactly the `!tokenAuth()` branch at
    `api_handlers.php:78-85`.
- **(b) Apply the same guard to surface B (`stock_api`, `routes.php:1101`)** — it has the identical CORS `*` + session
  fallback. Omitting it would leave an equivalent hole open.
- **(c) SECONDARY — narrow CORS** for cookie-authed actions: stop emitting `Allow-Origin: *` on A/B; echo a specific
  allowed origin (+ `Allow-Credentials: true`) only for the legitimate plugin origin(s), and keep `*` only for the
  truly public, key-gated/GET reads (`customer_context`, the PDF serves). Defence-in-depth; not load-bearing today.
- **(d) Optional later hardening — require `X-Requested-With: DishNet`** on cookie-authed mutations. B12/B13 and the
  customer portal already send it, but **most staff callers do not**, so this needs touching many callers → larger
  blast radius; recommend as a follow-on, not part of the core fix.
- **Do NOT** make the `_csrf` token per-user (regressed before), and **do NOT** revert `SameSite=None`.

**The iframe-origin question is the one real regression risk [RV].** The plugin answers on **two** origins — the public
Traefik host and UISP's own `:8443` listener — and `CanonicalHost` deliberately **never** redirects `page=api` to a
canonical origin, so a staff session's document origin (hence the API `Origin`) may be **either**. The guard's
allowed-origin test must therefore accept **both** legitimate origins (or compare `Origin`-host to `Host` and accept
`Sec-Fetch-Site` same-origin/same-site), and the fix must be validated **on both origins in both tenants**. Whether the
iframe document origin equals the uCRM parent-app origin, and the exact `DR_BASE` origin (B14), are **[RV]**.

---

## 5. Integration compatibility matrix (does the §4 fix affect this caller?)

| Caller | Credential | Affected by (a)+(b) same-site guard? | Affected by (c) CORS narrowing? | Why |
|---|---|---|---|---|
| Browser staff UI, Bearer calls (B1,B3,B4,B6–B10) | Bearer | **No** | No | guard skips Bearer-authed requests |
| Browser staff UI, cookie calls (B2,B5,B11,B12,B13,B14) | staff cookie | **Yes — must still pass** (same-origin iframe) | No (same-origin) | these are the calls the guard protects; must allow legitimate same-origin |
| uCRM iframe (document origin) | cookie | **Yes — must pass on both origins** [RV] | possibly | the one real regression surface (§4) |
| n8n `customer_context` (I1) | app-key (query/header) | No | No (stays `*` or key-gated public) | not cookie; GET; pre-auth |
| Evolution webhook (I2) | webhook secret | No | No | separate entry, never reaches A |
| Evolution/WASender PDF fetch (I3,I4) | URL token | No | No | pre-auth GET, no cookie |
| WASender/Pusher inbound (I5,I6) | webhook secret | No | No | separate entry |
| Customer PWA `app_*`/`dpo_*` (I7,I8) | customer JWT / `dn_customer_session` | **No** (different cookie; already self-guards) | No | staff guard keys off `kyc_retailer` only |
| v1/v2 REST (surface C) | Bearer/JWT | No | separate CORS (`PWA_ORIGIN`) | no cookie fallback |
| Standalone PWAs (`retailer/`, `pwa/`) | localStorage Bearer | No | No | Bearer; same-origin? [RV] |

**Net:** the fix affects **only** the browser staff UI's cookie calls and the iframe — and those must *continue to
work*; **no integration is functionally affected.** The only thing that legitimately changes behaviour is that a
**cross-site** cookie call is refused (the point).

---

## 6. Test plan (for the eventual, separately-approved implementation)

- **Positive:** a same-origin cookie POST (both a JSON body and a FormData body, e.g. `kyc_exclude`) succeeds; a
  Bearer-authed call (valid `api_token`) succeeds unchanged with any/no Origin; `customer_context` with the key
  succeeds; a PDF-token GET succeeds.
- **Negative:** a cookie POST with `Sec-Fetch-Site: cross-site` is refused; a cookie POST with a foreign `Origin`/`Referer`
  host is refused; a cross-site simple FormData POST to `kyc_exclude` is refused (the §2 vector).
- **Regression:** the uCRM-iframe path works on **both** the Traefik origin **and** the `:8443` origin; n8n
  `customer_context`, the Evolution/WASender PDF fetches, `evo_webhook`/`wa_webhook`, and the customer PWA
  (`dn_customer_session`) are all unaffected; **South Sudan golden** unchanged; surface B (`stock_api`) behaves
  identically to A.
- **Weakened-copy:** remove the Origin check → the cross-site negative test fails (proves it has teeth); remove the
  Bearer exemption → an integration regression test fails (proves the exemption is load-bearing).
- **Control-on-control:** empty the allowed-origin list → the legitimate same-origin positive test fails (proves the
  test is actually exercising the allow path).
- All synthetic; no live provider; both tenants.

---

## 7. Proposed implementation sequence (nothing started; each step its own approval)

1. **This inventory (docs/54)** — read-only, done. ← you are here.
2. **Design the shared same-site guard** as a tiny helper (reusing `CustomerSession::crossSite()`), with the
   Bearer/key exemption and the two-origin allow-list, + the §6 tests. Review before code.
3. **Implement (a)+(b)** on `api_handlers.php` and `routes.php` (`stock_api`) behind the tests; rehearse both tenants
   and **both origins**.
4. **Narrow CORS (c)** as a follow-on commit once (a)+(b) are proven.
5. **PD-5 guard test**, then enumerate `$_tabPerms`, then default-deny (docs/52 §PD-5) — low risk, own package.
6. **PD-2** authorization matrix, then unify `$can` (docs/52 §PD-2) — platform-wide, own package.
7. **Then** P4e (read-only portal pages, disabled, synthetic) per the operator's roadmap.

Each of steps 3–6 is **behaviour-changing on the staff plane** and needs its own explicit approval, its own
positive/negative/regression/weakened-copy tests, and its own **UG + SS** rehearsal before a line is written.

---

## 8. Dependencies & scope notes (not silently expanded)

- **Surface B (`stock_api`) is a sibling gap**, not optional: fixing A without B leaves an equivalent CSRF hole. [CV]
- **Surface C (v1/v2 REST) `PWA_ORIGIN`-with-`*`-fallback** is a *related but separate* CORS item (it is Bearer-only, so
  not a cookie-CSRF issue); flag it for its own review, out of PD-8's cookie scope. [CV]
- **PD-3 / PD-4** (privileged credential exposure in the browser — the `dishnet-token` meta at `public.php:1710` is a
  live `api_token` in page HTML) are the G5 theme and **adjacent** to PD-8 but **separate items** (docs/52 §4); not in
  this scope. Noted because the inventory surfaced the emitted token meta.
- **PD-6** (IDOR) — the portal already avoids it; not in scope.
- **Runtime-verification list [RV]:** (1) the exact iframe document origin(s) and whether they equal the uCRM parent
  origin; which of Traefik vs `:8443` a staff session runs on; (2) `starlink_pauses.php` `DR_BASE` origin; (3) whether
  the standalone PWAs are served same-origin; (4) that the **live** n8n instance matches the committed workflow (only
  `customer_context`); (5) that Evolution/WASender fetch PDFs/webhook server-side with no browser cookie jar.
- **No dependency on the portal or on the OTP work** (`docs/53`); PD-8 is staff-plane hardening adjacent to the portal's
  shared origin, independent of it.

---

## 9. What this task did NOT do (boundaries honoured)

No PD-8/PD-5/PD-2 fix implemented; no P4e; the OTP sender (`docs/53`) remains unwired and `partner_api.php` still binds
`$deliver = null`; no OTP sent, no live provider contacted; no feature flag changed and the portal stays disabled; no
deployment; no production data/config touched; Uganda and South Sudan behaviour preserved; Domain B untouched; PD-1
carried as recorded-fixed without re-verification. Awaiting approval before any code change.
