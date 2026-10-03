# WS-A P4 — Partner portal (distributor visibility): pre-implementation review

**Date:** 2026-10-01 · **Branch:** `claude/study-this-jhe2eg` · **Status:** review — decisions recorded, no code yet.

This is the "decisions before code" review for **WS-A Phase 4** (`docs/49` §10, row 4), the distributor-facing
**partner portal**. P1–P3 (registry, uCRM link, territory + attribution, the notification pilot) and the 5.18.65
sidebar link are **deployed to production, pilot off/ON by the operator's own switch, nothing sent**. P4 is the first
*partner-facing* surface and the riskiest one, so it is reviewed against the authoritative design in **root `docs/47`
§10** before anything is built.

---

## A. The two business-approval decisions — now made (operator, 2026-10-01)

`docs/47` §17 reserves these for Bhavin. Put to the operator with recommendations; answered:

| | Decision | Chosen | Note |
|---|---|---|---|
| **D-9a** | Where the portal runs | **P-A' — inside the plugin, on the uCRM public address** (`crm.dishnetuganda.com`), a flag-gated route served by `public.php`, with its **own** distributor session + cookie | No new DNS or Traefik; fully reversible; fastest for a one-distributor pilot. `docs/47`'s own stated target was P-A (own hostname); the operator chose the uCRM address for the pilot. **Consequence to carry (docs/47 P-A' con):** it shares an origin with the staff pages, so the portal's cookie/CSRF/Origin discipline and the "no uCRM app key, no staff token in a partner page" rules are load-bearing — enforced and tested, not assumed. Moving to an own hostname later is a route change, not a rebuild. |
| **D-9b** | How distributors sign in | **Phone one-time code + TOTP** (`docs/47`'s stronger option) | A one-time code to the distributor's already-**verified** number (reusing the customer-portal code flow + `PhoneNumber`), **and** a TOTP authenticator. TOTP is a **new primitive for this plugin** (Domain B has one in migration 026, but that is a separate service); it is built here from scratch, in PHP, with its own tests. |

---

## B. The design being implemented (root `docs/47` §10.3, verbatim intent)

- **Accounts** — `dist_partner_users`, created/invited by staff (channel-manager / admin) and, for their own outlets,
  by a partner head-office user. For the **pilot**: one head-office owner user per appointed distributor.
- **Session** — `dist_partner_sessions`: an opaque 256-bit token **stored only as an HMAC**, 8-hour life, **revoked on
  logout, on disable, and on a role/outlet change**; every request re-reads status and scope. Modelled on
  `CustomerSession` (`lib/CustomerSession.php`; migration 073).
- **Cookie** — its own name, `HttpOnly; Secure; SameSite=Strict`; any non-GET needs the custom header **and** a
  matching `Origin`. (The customer-portal rule, `CustomerSession.php:15-19`.)
- **Entry (D-9a)** — `public.php?page=partner` (the screen shell) and `public.php?page=partner_api&action=…`
  (the data), served by the plugin — the one-public-file contract.
- **Dispatcher** — **one registry**: `action → method, capability, scope-kind, handler`. An undeclared action answers
  **404**; it **never falls through** to `includes/api_handlers.php`; a **staff token is refused** on the partner API,
  and a partner token on the staff API.
- **Scope — from the session, never the request.** A partner user's `partner_id` (and, later, outlet set) is read
  from the session row. **An id in the request outside that scope answers 404**, as if it did not exist, so a probe
  learns nothing.
- **Data access** — every repository method takes a `PartnerContext` and adds `partner_id = ?` (and, for outlet roles,
  `outlet_id IN (…)`), with **composite keys `(id, partner_id)`** so a row cannot point across partners.
- **Answers** — allow-listed fields only; **never a raw uCRM object, never another client's uCRM id, never the app
  key.** uCRM is reached only server-side via `CrmApiClient`, only for the partner's own client id (from
  `dist_partners`, never the request).
- **Pages** — one static bundle, `script-src 'self'`, no inline script, security headers — modelled on Domain B's
  operator app (`docs/127` §H).
- **Limits** — sign-in / code / write limits per phone and per address, decaying lockout (the customer login's limits).
- **Audit** — every partner write records a document row with the partner user as actor (+ `fin_audit` for money;
  none in the read-only pilot).
- **Isolation is enforced in PHP, not the database** (SQLite has no row-level security): one registry, one scoped
  repository layer, composite keys, **a test that enumerates every action × role × foreign id**, and **weakened
  copies proving each scope predicate is load-bearing** (`docs/47` §10.3, §15).

### Pilot scope (narrower than the full `docs/47` §10.1 matrix)

P4's pilot is **read-only distributor visibility of their own attributed customers/leads** (`docs/49` §10 row 4) plus
their own notification settings. It does **not** build stock, dispatch, orders, money, settlement, commission, outlet
users, approvals, or the warehouse/channel-manager staff roles — those are later `docs/47` phases. No partner *write*
path in the pilot ⇒ no `fin_audit`, no approvals engine yet.

---

## C. Sub-batches (smallest-first, each off by default, Uganda-gated, synthetic-only)

| Batch | Deliverable | Depends on | Decision-independent? |
|---|---|---|---|
| **P4a** | **The scoped data layer + the isolation test.** `PartnerContext` + a read-only `DistributorPortalData` reader returning only a partner's own attributed customers/leads (over P2's `dist_customer_links`), with composite-key discipline; `test_dist_isolation.php` — a second partner's rows absent **and** a deliberately-widened scope makes the test fail. No auth, no route. | P2 (shipped) | **Yes** — build first; it is the #1-risk floor and independent of D-9a/D-9b |
| **P4b** | **Accounts + sessions.** migration `dist_partner_users` + `dist_partner_sessions`; a `PartnerSession` service modelled on `CustomerSession` (HMAC-stored token, 8 h, revoke on logout/disable/role change, re-read scope per request). | P4a | — |
| **P4c** | **Sign-in: phone OTP + TOTP** + per-phone/per-address limits with decaying lockout. OTP reuses the customer-portal code flow to the **verified** `dist_contacts` number; TOTP is a new PHP primitive with its own tests. | P4b | — |
| **P4d** | **The portal API: the one-registry dispatcher** (`?page=partner_api`), deny-by-default, 404 on undeclared/out-of-scope, staff-token-refused, read-only actions over P4a's reader; cookie/CSRF/Origin discipline. | P4a, P4b | — |
| **P4e** | **The portal pages** (`?page=partner`): one static bundle, `script-src 'self'`, no inline script, security headers (the operator-app pattern); read-only screens for own customers/leads + own notification settings. | P4d | — |
| **P4f** | **Deploy artifacts + handover** (pinned script + rehearsal), and the **PD-blocker gate** (§E) that must clear before a real distributor signs in. | P4a–e | — |

Each batch ships behind the existing `distributors_enabled` flag (and the whole feature stays Uganda-gated); the portal
route additionally refuses to exist unless the flag is on. South Sudan sees nothing. No production deployment, no live
transport, no real partner data without explicit approval — the standing WS-A constraints.

---

## D. The isolation boundary (the one that matters)

The portal's whole safety rests on **scope from the session, composite keys, deny-by-default**. Concretely:
- `PartnerContext` carries the `partner_id` resolved from the **session row**, never a request field.
- Every read is `… WHERE partner_id = :ctx_partner` — and the join rows (`dist_customer_links`) are matched on the
  **pair**, so a link that names another partner cannot be reached even by a forged id.
- The API dispatcher answers **404** for any id the context cannot see (a probe cannot tell "absent" from "forbidden").
- `test_dist_isolation.php` enumerates: partner A sees only A's rows; partner B's rows are **absent** for A; a
  forged/foreign id → 404; **and** a deliberately-widened scope predicate makes the test **fail** (the control on the
  control — the boundary is real, not vacuous). This mirrors the weakened-copy discipline used throughout WS-A.

---

## E. Hard ordering caveat — the PD-series, before a real partner signs in

`docs/47` R-1/§14 is explicit: the staff-side security gaps **PD-1…PD-9** "expose partner and financial data once it
exists", and the portal "should open onto a ledger a pilot has exercised." These bind P4 as follows:

- **Building P4a–P4e against synthetic fixtures is safe now** — nothing is exposed; no real partner, no real data, flag
  off, Uganda-gated, tests only.
- **Before P4f opens the portal to a real distributor**, the PD items that touch partner-reachable surfaces must be
  cleared or shown not to apply. In particular **PD-1** (a CSV export with **no sign-in check** — a possible data
  exposure, `docs/47` flags it as a fix-now) is independent of the portal and should be fixed on its own; and the
  portal's own API must not inherit PD-2/PD-4/PD-8 (role-ignoring permission check; tokens in the page; open
  CORS/no-CSRF) — the dispatcher is built deny-by-default precisely so it does **not** inherit them. The build record
  will state, per PD item, whether it is a blocker for opening the pilot or not-applicable to the portal path.

This review does **not** authorise opening the portal to a real partner; it authorises building the surface, off, with
synthetic data and tests.

---

## F. Standing constraints (unchanged, restated)

Dev/test only on `claude/study-this-jhe2eg`; **off by default** (`distributors_enabled`, plus the portal route gated on
it); **additive migrations only**; **synthetic/fake data only**; **preserve Uganda and South Sudan** (SS byte-for-byte —
a `test_dist_preserve`-style golden); **Domain B untouched** (`docs/47` §10.5 — a distributor is never a Domain-B
operator); reviewable diff + migration/rollback + pinned deploy + rehearsal per batch. No live WhatsApp send, no real
distributor/uCRM records, no production config, no production deployment without explicit approval.

---

## G. Test plan (per `docs/47` §15 / `docs/49` §13)

- `test_dist_isolation.php` — the action × (role) × foreign-id matrix; a second partner's rows absent; a widened scope
  fails the test (control-on-control). **(P4a)**
- `test_partner_session.php` — HMAC-only storage; 8 h expiry; revoke on logout/disable/role change; scope re-read per
  request; a staff token refused on the partner API and vice versa. **(P4b/P4d)**
- `test_partner_signin.php` — OTP only to the verified number; TOTP required for the money-visible role; per-phone and
  per-address limits with decaying lockout; replay refused. **(P4c)**
- `test_partner_api.php` — deny-by-default dispatcher: undeclared action 404, no fall-through to the staff API, allow-
  listed fields only, no uCRM app key / no foreign uCRM id in any answer. **(P4d)**
- `test_dist_preserve.php` — with the flag **off**, Uganda and South Sudan behaviour byte-for-byte unchanged; the
  partner route does not exist. **(every batch)**
- Weakened copies for each guard, each shown to fail when its subject is removed.

---

## H. Deliberately NOT in the P4 pilot

Stock / dispatch / orders / money / settlement / commission; outlet users and the BM/BC/RD outlet scope; the
approvals engine (`dist_approvals`); the new `warehouse`/`channel_manager` staff roles; the own-hostname move (P-A);
any live WhatsApp (that is WS-B). These are later, separately-approved `docs/47` phases.

---

*Next: build **P4a** — the scoped read-only data layer + `test_dist_isolation.php` with its control-on-control.*
