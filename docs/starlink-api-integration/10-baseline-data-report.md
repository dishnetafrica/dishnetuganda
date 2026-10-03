# 10 — Baseline inventory: `dishnet-data-report` v2.8.80

**Deliverable #1a.** What the plugin **is and does today**, so the mapping in `20` has a fixed
reference. **Read-only**: produced from the real source (`dishnetafrica/datareport` @ `012810d6`,
= the deployed v2.8.80), read, not run. File:line citations point into that tree. No secret,
cookie, token, or customer identifier is reproduced here.

> **The one fact that governs the whole assessment.** Every Starlink call in this plugin is a
> **session-cookie scrape of Starlink's *internal* consumer-portal / app endpoints** — `webagg`,
> `telemetryagg`, `accounts/v3`, `auth-rp`, `account(s)/v1`, `subscriptions/v1`,
> `SpaceX.API.Device.Device/Handle`. **None is the official OIDC Public API V2.** Auth is a
> **pasted browser cookie** (AES-vaulted) + an XSRF token scraped from that cookie + silent
> token-refresh — no OIDC, no client credentials, no API key. So `20` is a **migration + field-
> parity** analysis, and some scraped data has **no official equivalent at all** (`40`).

---

## A. Identity & shape

- `manifest.json`: name `dishnet-data-report`, **v2.8.80**, display "DishNet Starlink Data Report".
  `executionPeriod` 60 min. `ucrmVersionCompliability` min 2.14.0 (**note the misspelling** vs the
  finance plugin's "Compliancy" — `docs/98` M-1 / prior audit flagged this). **No `configuration[]`
  block** — the plugin takes *no* uCRM-declared settings; everything lives in its own JSON files
  plus the pasted cookie.
- 22 PHP files + manifest + one doc. **Two consumers of one data layer:** `public.php` (admin
  iframe + client-portal router) and `client.php` (client-zone SPA). Both read the **finance
  plugin's** `sl_kits.json` / `sl_usage.json` — the two plugins are coupled at the filesystem.
- **Tests: none.** No `tests/`, no `test_*.php`, no harness, no fixtures. The runtime is
  inseparable from a live uCRM server + live Starlink cookies. **This is itself a top regression
  risk** (`50`): there is no offline way to prove a change preserves behaviour.

## B. What it scrapes from Starlink (by data domain)

All REST goes through `smCurl()` (`session_manager.php:311`) with a fixed Chrome-120 UA, cookie,
and `referer: https://starlink.com/`; `CURLOPT_FOLLOWLOCATION=false` is deliberate. Cross-account
scoping rewrites the `account_number=` cookie segment (`cron.php:559-565`).

| Domain | Internal endpoint(s) scraped | Written to | Drives |
|---|---|---|---|
| **Account/contact** | `accounts/v3/accounts/contact` | `dr_accounts.json` | account name, region, business flag, **billing-suspended flag, billing day, next/previous due date** |
| **Service-line + router discovery** | `webagg/v2/accounts/service-lines` (+ `account(s)/v1` SL-number fallbacks) | `wifi_router_map.json`, `sl_svc_cache.json` | SL↔kit↔router map, nickname, plan id, **subscription active/endDate/pendingActivation, isPaused/isSuspended/isStandby, canPause/canResume, lastConnected/Disconnected**, serviceAddress, router hardwareVersion/isBypassed/directLinkToDish |
| **Usage / data telemetry** | `telemetryagg/v1/data-usage/account/{acc}/service-line/{sl}/annotated` (+ `/mini/` for Starlink Mini) | `sl_usage.json` | per-SL, per-cycle, per-day **GB** split Local-Priority (blue) vs other (white), allowance, totals |
| **Billing / invoices (list)** | `webagg/v1/public/billing/invoice-balances/{page}` | `dr_invoices.json` | invoice no., date, total, **paymentAmount, paymentMethod, depositAmount**, due date, period, status(int), isCancelled, balance |
| **Invoice line detail** | `webagg/v1/public/invoice/{INV}` | `sl_invoice_lines.json` (**consumed by the finance plugin**) | per-line productId, description, qty, price, **msrpPrice, adjustment**, lineTotal, tax, period |
| **Orders / shipment** | `webagg/v1/public/orders/customer-account` | `dr_orders.json` | order no., status, **shipment/tracking/fulfillment dates, carrier**, line assets |
| **Subscriptions** | `subscriptions/v1/subscriptions` | *(none — diagnostic probe only)* | — (liveness comes from webagg `subscription.*`) |
| **Device control (gRPC-Web)** | `POST SpaceX.API.Device.Device/Handle` (methods `get_status`=1004, `wifi_get_config`=3009, `wifi_set_config`=3001) | `wifi_router_map.json` (`cached_ssid/password/clients`), overlay DB | **WiFi SSID/password read+write, per-client list, per-client pause/block, dish-online state** |

**Field-level read maps** (exact source→target field lists) are in the function-by-function
table in `20`; they are the backbone of the parity verdicts.

## C. Auth / session machinery (what V2 would replace wholesale)

- **Primary cookie** pasted from a browser → `sl_sync_settings.json.starlink_cookie` (plaintext),
  validated against `accounts/v3/accounts/contact`.
- **Per-account vault** `dr_accounts.json`: cookies **AES-256-GCM encrypted** (`cookie_enc`),
  key = PBKDF2-SHA256(100k) of uCRM's own `config.json` `parameters.secret` + a per-install random
  salt — a copied vault can't be decrypted without the uCRM secret. Plaintext stripped, chmod 0600.
- **Token refresh / XSRF / throttle:** `auth-rp/auth/user` before each device call; `session/refresh`
  & `token/refresh` for silent re-auth; XSRF scraped from the cookie / `starlink.com/account`;
  per-host exponential backoff in `request_throttle.json` (429/503 → `min(7200, 300·2^(n-1))` ±jitter).
- **Two independent auth layers** observed: contact can pass while telemetry 401s.

> V2's OIDC client-credentials flow replaces **all** of this (cookie vault, refresh, XSRF, dead-
> cookie reimport, round-robin) with a standard token mint. **This is the single clearest win of a
> migration** — and the only part that is strictly simpler, not lossy. (`30`, `20` row AUTH.)

## D. Crons (the sync engine)

Dispatched by `main.php` (flock, per-cron interval ledger in `main_schedule.json`):

| Cron | Cadence | Does | Writes |
|---|---|---|---|
| `cron.php` | 60 min (hard floor 120) | Phase 0 router discovery (6h) + batch gRPC `get_status` (≤80/run); Phase 2 usage; Phase 1 invoices; KIT registry; Phase 3 CRM plans; Phase 4 auto-block sweep | `sl_usage`, `sl_svc_cache`, `wifi_router_map`, `dr_invoices`, `dr_kit_registry`, `dr_plan_cache` |
| `cron_session.php` | 5 min | one account/tick: contact → service-lines → usage → invoices | `dr_accounts`, `sl_svc_cache`, `sl_usage`, `dr_invoices` |
| `cron_orders.php` | 1 h (adaptive 24h/7d) | paginate orders, flatten lines, reclassify shipment state | `dr_orders` |
| `cron_invoice_details.php` | 24 h | fetch `invoice/{INV}` line items (new + <7-day refund window), atomic write | `sl_invoice_lines` (→ finance) |
| `cron_auto_block.php` | inline (cron.php Phase 4) / 2 h | pull uCRM `clients/services`, bucket by status (4 suspend→block, 1 active→unblock, 2 postpone→skip), map KIT→router, **self-HTTP-call** block/unblock, WhatsApp digest | `auto_block_runs`, `auto_block.sqlite3` |
| `cron_backup.php` | 8 h | ZIP own + finance data, rotate 30, **Google Drive** upload | `backup_log` |
| `cron_test_block_extend.php` | 10 min (+ piggybacks admin loads) | re-pause newly-connected MACs on blocked routers (leak closure) | `wifi_test_block_state`, heartbeat |

Also: `full_history_scan.php` (admin deep backfill), `cleanup_stavilo_usage.php` (one-off fix).

## E. The WiFi control plane + auto-block (operationally the most important feature)

- **Change SSID/password** is token-gated: `dr_wifi_request_token` requires the caller to supply the
  *current* SSID (proves app read the router), mints a 5-min single-use token; `dr_wifi_change_password`
  validates it, builds a `wifi_set_config` (3001) protobuf for 2.4/5 GHz, writes an audit row.
- **Pause / block** a client or a whole router: `drWifiSetClientPause` → device `client_configs`;
  **test_block** probes the dish, reads original creds, enumerates live MACs, pauses each, optionally
  renames SSID to `DishNet-PAY-NOW` + a random suspended SSID, and persists state for restore.
- **auto-block** ties uCRM service status → this device control: suspended clients get blocked,
  reactivated clients unblocked, all over a **self-HTTP call** to the plugin's own `dr_wifi_test_block`
  endpoints, with a SQLite retry queue and a WhatsApp digest.
- A **pause overlay** SQLite masks Starlink's 20–40 min propagation lag.

> This whole plane runs on the **gRPC-Web device relay** (`SpaceX.API.Device.Device/Handle`). The
> official V2 API has **no WiFi SSID/password management, no per-client list, and no per-client
> pause** (it has only UT/router *reboot* and router *configs*). **This is the feature least likely
> to be portable** and the one most important to preserve — see `20` rows DEV-\* and `40` G-1.

## F. Dependencies that stay authoritative regardless of any Starlink migration

- **uCRM REST** (`X-Auth-App-Key`): `/clients`, `/clients/services`, `/service-plans/{id}`,
  client-zone identity via cookie forwarding. **uCRM is authoritative for client identity, service
  status (the block/unblock trigger), plan names, and KIT→client linkage.** No Starlink API —
  official or scraped — changes that. The auto-block trigger is **uCRM status**, never a Starlink
  field.
- **Sibling plugins:** `dishnet-starlink-finance` (shares `sl_kits.json`/`sl_usage.json`, is fed
  `sl_invoice_lines.json`); `dishnet-hybrid-telecom` (reads its `plugin.sqlite3`, posts WhatsApp
  digests, shares `_dishnet_shared/internal_auth.json`).
- **Google Drive** (`drive.file` scope) for backups. **Local-derived, from no API:** the router↔kit
  map, pause overlays, throttle, dish-online state, audit logs, all run bookkeeping.

## G. What "preserve existing behaviour" concretely means here (the preservation set)

Carried into `20`/`50` as must-not-break:

1. **The SL↔KIT↔router↔customer map** and the KIT registry contract ("others must not write").
2. **Usage GB** per SL/cycle/day with the Local-Priority/other split (what customers see).
3. **Invoice + invoice-line data** feeding the finance plugin (`sl_invoice_lines.json`).
4. **The WiFi control plane + auto-block** (change password, pause, test-block, suspend-on-nonpay).
5. **The client-zone report** (share-token + uCRM client-zone views).
6. **Orders / shipment tracking** (`dr_orders.json`).
7. **Backups** (incl. finance data) and the **version manager**.
8. uCRM remaining the authoritative identity/billing/suspension source.

*Baseline only — no judgement here on what should change. The function-by-function official-API
mapping and the Yes/Partial/No/Unknown preservation verdicts are in `20`.*
