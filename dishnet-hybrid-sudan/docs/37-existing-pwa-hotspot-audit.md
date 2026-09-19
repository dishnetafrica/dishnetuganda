# Existing PWA and HotSpot audit — what not to rebuild

Read-only audit, 19 September 2026. No code, configuration, migration or
database was changed.

---

## 0. This corrects docs/30 §0.1, which was wrong

docs/30 opened with:

> **"The actor the whole brief depends on does not exist."** … There is **no
> authenticated customer**, anywhere.

**That is false.** A complete customer-facing application already exists,
with its own authentication, its own API, its own PWA, and a working hotspot
feature.

The error came from looking in one place. `api/v2/router.php` *is* a staff
field-app API — that part was right — but it is not the only API. The
customer one lives at `includes/api/api_customer_app.php`, **5,104 lines**,
and it was never opened because the search stopped at the `api/` directory.

The consequence matters: docs/30 put "build the customer principal and
tenancy model" at the front of the schedule as a prerequisite. **Much of
that already exists.** Section 9 revises the boundary.

---

## 1. The three PWAs

| | location | audience | auth |
|---|---|---|---|
| **Customer app** | `tabs/customer_app/portal.php` (418 KB), `login_web.php` | **customers** | OTP → JWT `kind='app'` |
| Staff support | `pwa/` — `manifest.json`, `sw.js` | staff — WhatsApp inbox | staff session |
| Retailer portal | `retailer/` — `index.html`, `sw.js`, `manifest.json` | DishNet retailers — expenses, photos | JWT, **different `kind`** |

**Stack:** PHP 7.4 server-rendered, vanilla JS, no framework, no build step.
`portal.php` is a single-file SPA of 418 KB. Service workers and web
manifests are present, so all three are installable.

**Three token kinds already coexist**, and `ca_require_auth` explicitly
rejects the wrong one:

```php
// Must be a customer-app token (kind='app'), not a retailer token
if (($claims['kind'] ?? '') !== 'app') { $er2('Wrong token type.', 401); }
```

That is a working multi-audience authorisation model. It is not the
multi-tenant model docs/30 §7 describes, but it is not nothing either.

---

## 2. HotSpot — what exists, and what it actually is

**EXISTING and working.** But it is **Starlink's built-in router Wi-Fi**,
driven through Starlink's cloud API — not MikroTik, not RADIUS, not a
captive portal.

The control verbs are `dr_*` calls into the Starlink dealer API:

```
dr_wifi_get_status · dr_wifi_get_config · dr_wifi_change_password
dr_wifi_pause_client · dr_wifi_test_block
dr_snapshot · dr_kit_registry · dr_kit_map · dr_accounts · dr_orders
```

### 2.1 Customer-facing hotspot actions — all live

| action | does |
|---|---|
| `app_hotspot_status` | current mode and state |
| `app_hotspot_toggle_mode` | switch hotspot mode |
| `app_hotspot_prepare` | ready a router for hotspot use |
| `app_hotspot_resync` | reconcile router against stored config |
| `app_hotspot_rotate_password` | generate and push a new Wi-Fi password |
| `app_wifi_get` / `app_wifi_save` | read and write Wi-Fi configuration |

### 2.2 Paid access — the voucher analogue, already built

| action | does |
|---|---|
| `app_paid_access_grant` | grant time-based access to a device |
| `app_paid_access_extend` | extend an existing grant |
| `app_paid_access_list` | list active grants |
| `app_paid_access_revoke` | revoke one |

Backed by **`hotspot_paid_access`, a real SQL table** (not a JSON
collection), with `router_id`, `status`, `expires_at`, and a companion
`hotspot_session_log`.

`cron_paid_access.php` (189 lines) expires grants and pauses the device via
`dr_wifi_pause_client`. Its design note is worth quoting, because the same
reasoning will apply to a MikroTik fleet:

> *"Hammering all 44 routers every minute would be 63,360 API calls a day for
> zero benefit."* — so it selects distinct routers **with active grants
> only**, and exits in under 10 ms when there are none.

**This is a working paid-access system with grant, extend, revoke, expiry and
enforcement.** It is the closest thing to a voucher the codebase has — and it
is not a voucher: it grants access **to a known device**, rather than issuing
a **sellable code** a stranger redeems.

### 2.3 Device tracking — exists

`hotspot_seen_devices`, `app_devices_get_seen`, `app_devices_record_seen`,
`app_devices_acknowledge`, `app_device_blocklist_get`,
`app_device_blocklist_toggle`. MAC-level visibility and blocking.

### 2.4 Router → customer authorisation — exists, and is the tenancy model

`ca_hotspot_authz_router(string $routerId, int $clientId)` is the function
that stops customer A touching customer B's router. It resolves
**router → kit → customer** through two sibling plugins:

```
sl_kits.json           (dishnet-starlink-finance)  router_id_full → kit
wifi_router_map.json   (dishnet-data-report)       router → kit_serial   ← fallback
```

**This is docs/30 §6.3's device registry, already in production** — as JSON
files in sibling plugins rather than a database, with a documented fallback
path added after the primary lookup was found stale in v4.15.2.

---

## 3. Capability map

| Capability | Exists? | Location | Reusable? | Notes |
|---|---|---|---|---|
| **PWA shell** | **YES** | `tabs/customer_app/portal.php` | **directly** | 418 KB single-file SPA, installable |
| **Customer login** | **YES** | `login_web.php`, `app_send_otp`/`app_verify_otp` | **directly** | OTP → JWT, `app_jwt_blacklist` revocation |
| **Customer authorisation** | **YES** | `ca_require_auth` | **directly** | rejects wrong token kind |
| **HotSpot screen** | **YES** | `app_hotspot_*` | **pattern only** | Starlink Wi-Fi, not MikroTik |
| **Paid access / grants** | **YES** | `app_paid_access_*`, `hotspot_paid_access` | **pattern + table shape** | device grants, not sellable codes |
| **Session log** | **YES** | `hotspot_session_log` | **pattern** | not RADIUS accounting |
| **Device registry** | **YES** | `ca_hotspot_authz_router` + 2 JSON files | **pattern, not data** | Starlink kits; MikroTik needs its own |
| **Plans** | partial | `app_plan`, uCRM catalogue | **directly** | service plans, not hotspot plans |
| **Payments / invoices** | **YES** | `app_invoices`, `app_payments`, PDF, receipts | **directly** | full billing view |
| **Push notifications** | **YES** | `app_register_fcm` | **directly** | FCM |
| **Usage** | **YES** | `app_usage` | **pattern** | Starlink data usage |
| **Diagnostics** | **YES** | `app_site_diagnostics`, `app_site_refresh` | **directly** | |
| **Legal / consent** | **YES** | `app_record_consent`, `app_legal_version` | **directly** | |
| **RADIUS integration** | **NO** | — | — | zero files. Built fresh in docs/36 |
| **MikroTik / RouterOS API** | **NO** | — | — | the `mikrotik` hits are AI knowledge-base *text*, not code |
| **Captive portal** | **NO** | — | — | zero hits for `captive` |
| **Voucher codes** | **NO** | — | — | HRM/cashbook "voucher" is an accounting journal voucher |
| **Router provisioning** | **NO** | — | — | Starlink routers arrive already claimed |
| **WireGuard management** | **NO** | — | — | built in docs/34 |
| **Reseller hierarchy** | **NO** | `retailer/` is expenses | — | no reseller→end-user model |
| **CoA / Disconnect** | **NO** | — | — | `coa` hits are substrings |

---

## 4. Integrations already in place

| | how |
|---|---|
| **uCRM** | `CrmApiClient` — clients, services, invoices, payments |
| **Starlink dealer API** | `dr_*` calls — router status, Wi-Fi config, client pause |
| **Sibling plugins** | `SiblingPlugin::pathOrEmpty()` → `dishnet-starlink-finance`, `dishnet-data-report` |
| **WhatsApp** | Evolution API; `app_invoice_send_whatsapp` sends an invoice from the app |
| **FCM** | push registration |
| **SQLite** | the plugin's own store |
| **PostgreSQL / Redis / RADIUS** | **none from the plugin.** The Phase 0 stack in docs/36 is separate and unconnected |

---

## 5. Production-ready vs. mock

**Everything found is real.** No mock screens, no placeholder endpoints, no
stubbed handlers were identified.

Evidence it is live rather than aspirational: `cron_paid_access.php` carries
operational cost reasoning about 44 real routers; `ca_hotspot_authz_router`
has a fallback path added in v4.15.2 *after a specific production failure*,
with the diagnostic note preserved in the comment. Code with scars is code
that ran.

**Not verified in this audit:** whether customers actually *use* the hotspot
features, and how many. That is a database question on the live system, and
it changes the reuse argument — a feature nobody uses is easier to reshape
than one people depend on. **Worth answering before Phase 2 design.**

---

## 6. What is missing for Zero-Touch MikroTik

1. **Any MikroTik or RouterOS code.** Nothing. Every command in docs/35 would
   be new.
2. **RADIUS.** Built in docs/36, entirely outside the plugin.
3. **Captive portal / voucher codes.** The existing model grants access to a
   *device you already know*. A hotspot voucher is a *code sold to a stranger*.
   Different data model, different lifecycle.
4. **Device provisioning.** Starlink routers arrive already bound to a
   customer through the kit registry. A MikroTik must be staged, claimed and
   tracked through the state machine in docs/30 §6.3 — none of which exists.
5. **Reseller → end-user hierarchy.** The brief's central relationship. The
   existing model is DishNet → customer → their own devices, one level deep.
   `retailer/` is an expense-claim app, not a reseller portal.
6. **WireGuard management.** Built in docs/34, not connected to the plugin.

---

## 7. Do not duplicate

| do not build | because |
|---|---|
| customer authentication | OTP + JWT + blacklist exists and works |
| a second customer PWA | `portal.php` is installable and in use |
| invoice / payment / receipt views | complete, with PDF and WhatsApp delivery |
| push notification plumbing | FCM registration exists |
| a second uCRM client | `CrmApiClient` is the one reader |
| a "paid access" concept from scratch | grant/extend/revoke/expire exists; extend its vocabulary rather than inventing a parallel one |

---

## 8. Recommended reuse boundary

**PROPOSED.** The revision docs/30 needs:

```
    EXISTING customer PWA  (portal.php, OTP, JWT kind='app')
                    │
        ┌───────────┴────────────┐
        ▼                        ▼
  EXISTING backend         NEW network control plane
  api_customer_app.php     (docs/30 artefacts 4-9)
        │                        │
        ▼                        ▼
  Starlink dr_* API        WireGuard → MikroTik REST
  hotspot_paid_access      FreeRADIUS + PostgreSQL
  (Starlink Wi-Fi)         (MikroTik HotSpot)
```

**The PWA becomes the single interface over two different network backends.**
A customer with a Starlink router sees today's hotspot screen; a customer
with a managed MikroTik sees a new one. Same login, same app, same billing.

**Reuse: authentication, the app shell, billing views, push, uCRM access.**
**Build new: the MikroTik control plane, RADIUS, provisioning, vouchers,
the reseller hierarchy.**

**Do not extend `hotspot_paid_access` to cover MikroTik.** It is shaped for
device grants on a Starlink router; vouchers are codes with a different
lifecycle. Two tables, one vocabulary in the UI.

### 8.1 What this changes in docs/30

| docs/30 said | revised |
|---|---|
| §0.1 no authenticated customer | **wrong** — exists |
| Phase 1: build customer principal | **build tenancy only** — authentication exists |
| §7 tenancy from scratch | extend `ca_require_auth`'s kind check and the `authz_router` pattern |
| §9 new API surface | **add** to `api_customer_app.php`'s action set, don't start a new one |

**Unchanged:** the network control plane must still be a separate service
(docs/30 §9, docs/34 §3.3). Nothing found here argues for putting RADIUS or
router provisioning inside a uCRM plugin — the opposite: the plugin's SQLite
store is exactly what §3 said it was.

---

## 9. Open questions

1. **How many customers actually use the hotspot features?** A live count
   from `hotspot_paid_access` and `hotspot_session_log`. It decides whether
   the existing model is a foundation or a prototype.
2. **Does the reseller in the brief already exist as a uCRM client?** The
   retailer app suggests a retailer concept, but for expenses.
3. **Should a Starlink hotspot customer and a MikroTik hotspot customer see
   the same screen?** A product decision that shapes how much of the existing
   UI can be shared.
4. **What is `dishnet-starlink-finance` and `dishnet-data-report`'s status?**
   Two sibling plugins hold the router→customer binding. They are load-bearing
   for authorisation and were not audited here.
