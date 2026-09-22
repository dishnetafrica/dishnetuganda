# 82 — P1: the Customer PWA as a real client, and the API gaps found

**Status: F6-A P1. No production system modified. No endpoint invented.**

---

## 1. What was built

`public/pwa/api.js` and `public/pwa/store.js` — the PWA's data layer, replacing
the prototype's mock constants. **The audited UX is untouched**: the prototype's
`derive()` gate and its `{cust, services, sites, routers, vouchers, sessions,
mik}` shape are preserved exactly, so no view function changes. Only what feeds
them is different.

`derive()` was the prototype's single authorization gate, standing in for the
server's walk `token → customer → services → sites → routers`. The server now
does that walk, so the client never sees a customer id at all and the gate
reduces to *"render only what the server returned for this token."*

---

## 2. Screens wired, and the endpoints used

| Screen | Endpoint(s) | State |
|---|---|---|
| Login / OTP | `POST /auth/request-code`, `POST /auth/verify` | **wired** |
| Home | `/me`, `/me/services`, `/me/sites`, `/me/vouchers`, `/me/sessions` | **wired** |
| My Services | `/me/services` | **wired** |
| Locations | `/me/sites`, `/me/sites/{site_id}` | **wired** |
| Plans | `/me/plans` | **wired** |
| New Voucher | `POST /me/vouchers` → **202 + intent_id** | **wired, queued** |
| Vouchers | `/me/vouchers`, `POST /me/vouchers/{id}/revoke` | **wired** |
| Connected Devices | `/me/sessions`, `POST /me/sessions/{id}/disconnect` | **wired** |
| Usage | `/me/usage`, `/me/uplink` | **wired** |
| Account | `/me`, `/me/entitlements`, `POST /auth/logout` | **wired** |
| **My Wi-Fi** | — | **UNAVAILABLE** — gap G1 |
| **Access Points** | — | **UNAVAILABLE** — gap G1 |
| **Billing** | — | **UNAVAILABLE** — gap G2 |
| **Support** | — | **UNAVAILABLE** — gap G3 |

---

## 3. API gaps discovered — reported, not invented

Per the brief, no endpoint was created to match a prototype screen.

| | Gap | Evidence |
|---|---|---|
| **G1** | **No endpoint returns access points.** `Projection::accessPoint()` exists, is allowlisted and is *tested* (`test_api_me.php:114`) — but **no route emits it**. The serializer for a screen exists while the screen's data source does not | measured: no `/me/*` route returns routers |
| **G2** | **No billing or invoice endpoint.** The prototype has `INVOICES`; the API has nothing | docs/56 §9 already listed billing as not built |
| **G3** | **No support endpoint.** | docs/56 §9, "support chat" |

**These render as `unavailable`, never as `empty`.** The distinction is the
point: an empty Access Points screen tells a customer with four access points
that they have none. `store.js` marks G1–G3 `State.UNAVAILABLE` at
construction — not `LOADING`, because they are never coming — and
`MISSING_CONTRACTS` names each in the client so the reason is visible rather
than implied.

### 3.1 A contract correction — the client was wrong, not the server

The client initially assumed a generic `{items: [...]}` envelope. **It is not
one.** Every list endpoint returns a **named key**: `{"sites": [...]}`,
`{"plans": [...]}`, `{"services": [...]}`. A test asserting the wrong shape
caught it.

**The server is authoritative, so the client was changed to follow it** — no
endpoint was altered. `call()` now takes the expected key explicitly, which
also means a renamed key fails loudly instead of silently producing an empty
list.

---

## 4. Authorization tests

| | Assertion | Result |
|---|---|---|
| 1 | customer A cannot see B | already covered (`test_api_me.php`), unchanged |
| 2 | A cannot access B's site | **new** — `GET /me/sites/{B's id}` as A → **404** |
| 3 | A cannot access B's router | no router endpoint exists (G1); the projection guard stands |
| 4 | A cannot access B's voucher | covered by the cross-customer 404 suite |
| 5 | arbitrary customer id rejected | **new** — `customer_id` *and* `tenant_id` in the body change nothing; `/me` still resolves to the token's own customer |
| 6 | site filter cannot escape scope | **new** — `../..`, a nil UUID, `null`, `%` all 404 |
| 10 | logout invalidates the session | **new** — 204, then **401 on every `/me` route** |
| 11 | expired session cannot access `/me/*` | **new** — expiry forced in the database, then 401 |

## 5. Customer-data leakage tests

**New.** Ten `/me` endpoints are called, their JSON concatenated, and the blob
asserted to contain none of: `serial`, `wg_pubkey`, `wgIp`, `tunnel_ip`,
`endpoint`, `ros_version`, `secret_sealed`, `radius_ref`, `claimed_by`,
`staged_by`, `last_error` — nor `radcheck`, `Cleartext-Password`,
`radius_username`, `aaa_secret`.

Client-side guards read the shipped `.js` as text: no `customer_id`,
`customerId`, `tenant_id`, `tenantId` anywhere; the token lives in
`sessionStorage` and **no code touches `localStorage`** (a hotel front desk is a
shared device); and no `DeliveryPort`, `radcheck`, `RouterOs`, `WireGuard` or
`tunnel_ip` concept appears in the customer client.

**One guard caught its own author.** The B1-neutral check failed on api.js's
*comment*, which explained the rule using the words it forbids. The rule
concerns what a **screen** says, so the guard now strips JS comments first —
matching `strip_php_comments()` in the PHP guards — and a meta-assertion proves
the stripper actually strips, so the guard cannot pass for free.

## 6. Voucher flow

`New Voucher → POST /me/vouchers → 202 + intent_id → intent queue`. **Asserted
202, not 200.** The client classifies 202 as `QUEUED`, which is a distinct state
from `OK`, and a regex test pins that mapping. Nothing waits for a router; the
codes exist immediately and publication is queued. The commercial code never
enters the AAA path — the simulator refuses a code-shaped secret permanently
(docs/80 increment).

## 7. Test count

**911 assertions, all green** (was 841). `test_pwa_contract.php` adds 70.

## 8. Remaining PWA gaps

- **G1/G2/G3** above — three screens render `unavailable`.
- **The view functions still live in the prototype file.** `api.js` and
  `store.js` are the real data layer; assembling them with the prototype's
  markup into a served `public/pwa/index.html` is the remaining mechanical step.
- **Captive portal** — deliberately separate, not started (Decision 4 open).
- No offline caching or service worker; `State.OFFLINE` is distinguished but not
  yet recovered from.

## 9. Production

**Untouched.** No production PostgreSQL migration, schema change, backfill, RLS
modification, FreeRADIUS change, C-b deployment, AAA publisher deployment,
router provisioning or customer assignment. No migration was added. The Admin
cross-customer privilege remains an **open decision** and no bypass was
introduced. Q2, `dnb_site_nas` and F6-B untouched.
