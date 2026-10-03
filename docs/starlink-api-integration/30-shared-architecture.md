# 30 — Cross-plugin shared-architecture recommendation

**Deliverable #5.** How a Starlink **official API V2** client *would* be structured across the two
plugins, if migration is later approved. **Assessment only — no code, no decision, no change.**
Grounded in the verified catalogue (`01`) and the two baselines (`10`; finance confirmed below).

## 0. The fact that decides the shape

Measured, not assumed:

- **Only `dishnet-data-report` calls Starlink.** It is the sole Starlink client (scraped today).
- **`dishnet-starlink-finance` makes *no* Starlink call of any kind** — verified by grep across its
  whole tree: its outbound hosts are **uCRM** (`X-Auth-App-Key`), **Google** (Drive backup/fonts),
  **DHL** (tracking links) and front-end CDNs — **no `api.starlink.com`, no `webagg`, no
  `telemetryagg`, no gRPC**. Finance obtains all Starlink-origin data by **reading data-report's
  sibling files** (`sl_invoice_lines.json` via `main.php`'s auto-import; `dr_kit_registry.json`
  read-only in the UI).
- The live data flow is therefore **Starlink → data-report → (files) → finance → (files) →
  hybrid**. (`10`§A, `docs/39`.)

> **Recommendation R-SHARED: one Starlink client, owned by `dishnet-data-report`. Do NOT build a
> second client in finance, and do NOT introduce a shared cross-plugin Starlink library yet.**
> Finance has no Starlink need; giving it API credentials would widen the secret's blast radius for
> zero benefit. Finance stays a **consumer of data-report's output files** exactly as today. A
> shared library is justified only if a *second* genuine Starlink consumer ever appears — at which
> point the client would move to `_dishnet_shared/` (the existing shared-secret location), never be
> duplicated.

This keeps the migration's blast radius inside one plugin and preserves the file contracts that
finance and hybrid depend on (the single most important preservation property — `20`§6, `21`).

---

## 1. Client placement & plane separation

- **The OIDC V2 client lives in data-report**, beside (not replacing) the existing device channel.
- **Two auth domains stay separate and must not be merged:**
  1. **Billing/read plane** → official **OIDC client-credentials** (new). Covers ACCT, SL, USE,
     INV-\*, SUB (`20`).
  2. **Device control plane** → the existing **gRPC-Web** channel (`SpaceX.API.Device.Device/Handle`)
     with its cookie/XSRF. Covers WiFi config, per-client pause, test-block, dish status — **none of
     which the official API exposes** (`20` DEV-\*). This plane is **untouched** by any V2 migration.
- Rationale: the official API cannot drive the device plane, and the device plane's auth is
  unrelated to OIDC. Entangling them would couple an upgradeable read path to an un-migratable
  control path.

## 2. Authentication & token management

- **Flow:** register an API client in the Starlink account → read `/api/auth/.well-known/
  openid-configuration` → mint a **Bearer** token via client-credentials → `Authorization: Bearer`.
  ([D] `01`§A — the JSON has no `securitySchemes`; confirm the exact token endpoint from the
  well-known doc at implementation time.)
- **One token per account**, cached with its expiry; **refresh on expiry or on a 401** (single retry,
  then surface). The fleet is small (≈4 accounts / 16 SLs — `docs/39`), so token churn is trivial.
- **Respect the token-request cap** (1,000 / 15 min / IP [D]): cache aggressively; never mint
  per-request.
- **Replaces** the entire cookie vault / `auth-rp` / `session/refresh` / XSRF / dead-cookie-reimport
  / round-robin machinery (`10`§C, `20` AUTH) — a net simplification.

## 3. Secret storage (client id + secret)

- **Reuse the existing proven mechanism, do not invent one.** data-report already encrypts the
  Starlink cookie vault with **AES-256-GCM**, key = **PBKDF2-SHA256(100k)** of uCRM's own
  `config.json parameters.secret` + a per-install random salt (`session_manager.php:41-96`). Store
  the OIDC client secret the same way: encrypted at rest, **chmod 0600**, plaintext never persisted.
- **Never** in source control, **never** echoed to the admin UI, **never** written to a log, and —
  important — **the backup cron zips `data/`** (`cron_backup.php`), so the secret file must be the
  encrypted form so backups (incl. Google Drive copies) never carry a usable credential. (The cookie
  vault is already encrypted for exactly this reason — match it.)
- Entry: a single admin field that **writes the encrypted secret and never reads it back** (show only
  "configured ✓"), mirroring how secrets are handled elsewhere in the estate.

## 4. Caching & freshness

- **The "last-good file" model is already the cache** — `sl_svc_cache.json`, `sl_usage.json`,
  `dr_invoices.json`, etc. Keep it: the UI and finance read files, never the API live. A V2 fetch
  updates the file; readers are decoupled.
- **Honour V2 freshness markers:** usage carries `lastUpdated` (typically yesterday — [D] `01`§D);
  record it and show usage as **day-lagged** rather than implying real-time.
- **Token cache** separate from data cache; expiry-aware.

## 5. Rate limits, pagination, retries

- **Limit:** 250 req/min **per account** [D]. Implement a real per-account token-bucket; the current
  politeness heuristics (≤80 routers/run, per-SL freshness skip, adaptive order cadence) map onto it.
  At fleet scale this is ample, but the limiter must exist to avoid 429s under backfill.
- **Pagination — handle BOTH styles** (`01`§A.2): page-index (`{pageIndex,limit,isLastPage,
  totalCount,results}`) for most lists; **cursor** (`{nextKey,results}`) for managed-accounts +
  roam-restrictions. A single paginator helper that detects which envelope is returned.
- **Retries / error taxonomy** (this is where the official API differs most from scraping):
  - **422 = business failure** carried in the `ServiceResponse` envelope (`isValid:false`,
    `errors[]`) — **not** a transport error; **do not retry**, surface the error. A 2xx with
    `isValid:false` is also a failure.
  - **429 / 5xx** → exponential backoff + retry (reuse the existing `request_throttle.json` backoff).
  - **403 `UserLacksRequiredPermission`** → a **permission gap**, not a transient error: surface it,
    name the missing `FeatureAccess`, do **not** retry (`01`§A.3).
  - **401** → refresh token once, then surface.

## 6. Logging without secrets

- Log **endpoint + HTTP status + `ServiceResponse.isValid` + request id + duration** only. **Never**
  the Bearer token, client secret, cookies, or raw response bodies containing customer data. The
  existing `sync_log.json` / `session_log.json` patterns stay, with a redaction pass asserted (no
  `Authorization`, no `cookie`, no `client_secret` substrings).

## 7. Identifier mapping (the join model)

- **Canonical internal key stays the kit serial** (`KIT…`), as the KIT registry already does
  (`lib/KitRegistryWriter.php`). V2 join keys (`01`§A.4): account `ACC-…`, service line `SL-DF-…`,
  UT id `00020900-…`, kit `KIT…`, dish `2DHT…`, address UUID; `deviceId` accepts UT/kit/dish.
- **Rebuild SL↔kit↔router** from `GET /service-lines` + `GET /user-terminals` joined on
  `serviceLineNumber` (replacing the webagg discovery — `20` SL).
- **Resolve addresses** via `addressReferenceId` → `GET /addresses/{id}` (lazy; cache).
- **uCRM linkage stays in finance** (`crm_client_id` / `crm_service_id` in `sl_kits.json`), set by the
  operator KIT-mapping dialog. **Never infer a customer from a phone number** (the estate's standing
  rule — `docs/101`); the Starlink API has no customer-identity concept anyway.
- **One mapping owner:** data-report writes `dr_kit_registry.json` ("others must not write"); finance
  reads it. A V2 migration must preserve that contract and the registry's output schema.

## 8. Scheduled sync

- **Keep the `main.php` dispatcher + per-cron cadence model** (`10`§D); swap the *transport* under
  each read cron from scraping to V2. Suggested cadence (unchanged in spirit): account/SL hourly;
  usage daily-ish (it is day-lagged anyway); invoices daily; invoice detail daily.
- **The device-plane crons (status, pause-extend, auto-block) keep their gRPC transport and cadence
  unchanged** — they are not part of the V2 read migration.
- **Orders cron:** no V2 source (`20` ORD) — it stays scraped or is retired by a separate decision.

## 9. Outage / degradation fallback

- **Fail soft on reads:** on V2 outage, serve the **last-good files**; show a staleness marker; never
  block a customer-zone view on a live API call.
- **Fail safe on money and control:** **uCRM/accounting stays authoritative** for customer billing
  and revenue, so a Starlink outage cannot corrupt financials; **auto-block's trigger is uCRM status**
  (`10`§E/F), so suspend/reactivate keeps working during a Starlink API outage. The device plane has
  its own path.
- **No auto-applying write queue** against the live API (there are no customer-facing V2 writes in
  scope anyway; all in-scope V2 use is **read-only**).

## 10. What this architecture deliberately does NOT do

- Does **not** give finance Starlink credentials or a client.
- Does **not** route the device/WiFi/pause plane through the official API (it can't — `20` DEV-\*).
- Does **not** put any uCRM call, or any customer-identity inference, on the Starlink path.
- Does **not** use the official API to compute, recognize, or recalculate **revenue** or **customer
  payment** — those are uCRM/accounting-authoritative and operator-manual (`21`). The V2 billing
  endpoints are **DishNet's cost from Starlink**, a cost-side input only.
- Does **not** change the `sl_invoice_lines.json` / `dr_kit_registry.json` file contracts without the
  consumer (finance/hybrid) changing in lockstep.

---

### One-paragraph recommendation

**Put a single OIDC V2 client in `dishnet-data-report`, alongside — not replacing — the gRPC device
channel; keep `dishnet-starlink-finance` as a pure file consumer with no Starlink access.** Migrate
only the read/billing plane (AUTH first, then account/SL/usage/invoices/plans), preserve every output
file contract, keep uCRM authoritative for identity/billing/revenue and for the auto-block trigger,
reuse the existing AES/PBKDF2 secret mechanism for the client secret, and leave the device-control and
orders paths on their current transports because the official API has no equivalent for them.

*Finance-specific details (its financial model, the exact `sl_invoice_lines.json` keys it depends on)
are confirmed in `11`/`21`. Gaps/questions `40`, regression matrix `50`, phased plan + rollback `60`
follow.*
