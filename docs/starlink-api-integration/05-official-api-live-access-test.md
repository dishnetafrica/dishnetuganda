# 05 — Official API V2: live-access test record

**Task:** a READ-ONLY validation of the **official** Starlink Public API V2 against DishNet's real
service-account credentials, to prove scope/coverage **before** any change to the working
data-report integration. **No migration is started here.** No code, schema, flag, plugin, or
deployment is changed; no write/mutation is made against any system.

**Evidence labels (as the operator required):**
- **[LIVE]** VERIFIED FROM LIVE API — an actual response from the probe.
- **[DOC]** VERIFIED FROM OFFICIAL DOCUMENTATION — the V2 OpenAPI 3.0.4 spec (see `01`).
- **[SHOT]** VERIFIED FROM OPERATOR SCREENSHOT — the service-account permissions dialog (partial;
  dialog overlays some rows).
- **[INF]** INFERRED — reasoning from the above.
- **[NV]** NOT VERIFIED — pending the live probe run.

---

## 1. Executive result

**The live API test could not be executed from the Claude Code session, and no live results are
fabricated.** Two blockers, both evidenced:

- **This session cannot reach `starlink.com`.** A credential-free GET to the public OIDC discovery
  URL returns `CONNECT tunnel failed, response 403` from the egress proxy. The same host serves the
  OIDC token endpoint and every V2 path, so **all live calls are blocked here.** [LIVE — negative]
- **No Starlink credentials are present in this environment.** (They were later shared in chat, but
  that does not change egress, and that secret must be **rotated** — §12.)

**What was produced instead** (the build-here / run-on-your-infrastructure split this project uses
throughout): a safe, read-only probe — `probe/starlink_api_probe.py` (+ `probe/README.md`) — for the
operator to run where egress and credentials exist (the Mac/server). Its **SCOPE VERDICT** block
directly answers "one credential → all accounts?". Until it is run, §§4–10 are **[NV]**; §§2–3, 11
carry provisional answers from the spec and the screenshot, clearly labelled.

**Provisional headline [INF from DOC+SHOT]:** the credential as currently granted can likely read
*service-lines, user-terminals, products* but **not** *account* or *billing* (those View permissions
are unticked — §2); and whether it is **org-wide vs single-account** is **[NV]** until the probe's
`/managed/accounts/tree` call is seen. The official API remains a **read/billing/service/device-reboot**
surface with **no** WiFi/pause/auto-block/live-telemetry/orders (`01`§D, `20`) — so any outcome is at
most a **partial, hybrid** migration, never a full replacement.

## 2. Credential scope

- **Type:** an **account "service account credential"** used to *"obtain an authorization token"* —
  i.e. **OIDC client-credentials**, exactly the V2 auth model. [SHOT][DOC]
- **Permission grants, as currently saved** (from the permissions matrix; two columns = View / Manage;
  dialog overlaid the middle rows): [SHOT, partial]

  | Feature group | Granted now? | Maps to V2 `FeatureAccess` | Affects |
  |---|---|---|---|
  | Account information | **✗ (both columns)** | AccountInformation | `GET /account`, `/addresses` → **would be DENIED** |
  | Device command and configuration management | **✓ (both)** | DeviceCommand / DeviceConfigurationAssignment | reboot, config, L2VPN, public-IP (**write/command** — a read probe must not need this) |
  | Device … (reboot/telemetry rows) | **✓ (visible)** | DeviceManagement / DeviceTelemetry | `/user-terminals`, routers |
  | Financial | **✗ (visible)** | Financial | `GET /billing/*` → **would be DENIED** |
  | Service … (two rows) | **✓** | ServicePlan / ServiceAccountManagement | `/service-lines`, `/products`, usage |
  | User management | **✗ (both)** | UserManagement | `/contacts` |

- **[INF]** With these grants, the probe will likely return **DENIED** on `/account` and `/billing/*`
  (`403 UserLacksRequiredPermission`, naming `AccountInformation`/`Financial`), and **OK** on
  `/service-lines`, `/user-terminals`, `/products`. The operator can tick **Financial** and
  **Account information** (View) and Save to extend coverage — a deliberate choice, since withholding
  Financial keeps billing *off* the API path (which aligns with "uCRM/accounting stays authoritative").
- **[INF]** The credential currently holds **device command/config (write)** — more than a read
  validation needs. Recommendation: mint a **separate, view-only** credential for the probe (§12).

## 3. Account-access model (the pivotal question)

**"With ONE credential, can DishNet read ALL its Starlink accounts/service-lines/terminals, or only
one account?"**

- **[DOC]** The V2 API offers two surfaces: the **direct** endpoints (`/account`, `/service-lines`,
  `/user-terminals`, `/billing/*`) operate on **the single authenticated account**; the **Managed
  Accounts** endpoints (`/managed/accounts/tree`, `/managed/accounts`,
  `/managed/accounts/service-lines`, `/managed/accounts/user-terminals`) expose a **parent→child
  hierarchy** — *if* the account is a managed/parent account *and* the credential holds the
  `ManagedAccount*` permissions.
- **[INF]** The URL seen (`/account/service-account-v2`) is an **account-scoped** credential; a
  managed hierarchy is possible but **not evidenced**. The "Service" grants *may* include
  `ServiceAccountManagement`, which is adjacent to managed-account capability — but that is not proof.
- **[NV] Definitive answer is the probe's `/managed/accounts/tree` result:**
  - `tree` returns a hierarchy with **N>1** accounts → **ORG-WIDE** (one key reads all N).
  - `tree` **DENIED**/empty while `/service-lines` returns data → **SINGLE-ACCOUNT** (one key reads
    one account's lines only; DishNet would need one credential per Starlink account).

  The probe prints exactly this as its `SCOPE VERDICT`. **Do not assume either outcome.**

## 4. Accessible population  — **[NV] PENDING PROBE**
Fill from the probe `SCOPE VERDICT`:
```
accessible_account_count   = ____   (managed_accounts_count, or 1 if single-account)
accessible_service_count   = ____   (service_lines totalCount / managed_service_lines)
accessible_kit_count       = ____   (user_terminals totalCount / managed_user_terminals)
```
Compare against data-report's current population (prior audit: **~4 accounts / 16 service-lines /
2 kits**, `docs/39`) in §6.

## 5. Endpoint test results — **[NV] PENDING PROBE** (scaffold)
One row per endpoint the probe reports (`result`, `totalCount`/`count`, needed-permission on 403,
field names). Expected per the spec + grants:

| Endpoint | Method | Expect (provisional) | Permission |
|---|---|---|---|
| `/account` | GET | DENIED (AccountInformation unticked) | AccountInformation/View |
| `/managed/accounts/tree` | GET | **the scope test** | ManagedAccountInformation |
| `/managed/accounts` | GET (cursor) | hierarchy count or DENIED | ManagedAccountInformation |
| `/service-lines` | GET (page) | OK + totalCount | ServicePlan/View |
| `/user-terminals` | GET (page) | OK + totalCount | DeviceManagement/View |
| `/products` | GET (page) | OK (plan catalog + price) | ServicePlan/View |
| `/addresses` | GET (page) | DENIED or OK | AccountInformation/View |
| `/billing/invoices` | GET (page) | DENIED (Financial unticked) | Financial/View |
| `/billing/balance` | GET | DENIED (Financial unticked) | Financial/View |
| `/data-usage/query` | POST-read | opt-in follow-up only | ServicePlan/View |

## 6. Multi-account findings — **[NV] PENDING PROBE**
Report: hierarchy present? child-account count; do those accounts match the Starlink accounts
data-report currently syncs? any API-only or current-only accounts?

## 7. Multi-kit findings — **[NV] PENDING PROBE**
Report accessible kit/terminal count vs data-report's `wifi_router_map.json`/registry population;
list matched / API-only / current-only (pseudonymised `KIT-001…`).

## 8. Data Report comparison — **[NV] PENDING PROBE** (method fixed now)
For the same population, compare each field data-report consumes against the API, classifying
**EXACT MATCH / PARTIAL / NOT AVAILABLE / AVAILABLE BUT DIFFERENT SEMANTICS / UNKNOWN**. The
field-by-field expectation is already derived from the spec in **`20-mapping-data-report.md`** (kit/SL
identity, plan, active/endDate = match; pause/standby/`canPause`/`lastConnected` = not available;
usage GB = match-ish, allowance unknown; invoices = partial + Financial-gated; orders, WiFi, live
telemetry = not available). The probe confirms it against live data.

## 9. Coverage matrix — provisional [INF from DOC]; confirm with probe

| Data Report capability | Current source | Official API source | Match | Gap | Keep current? | Improvement |
|---|---|---|---|---|---|---|
| Auth/session | pasted cookie + refresh | **OIDC client-credentials** | — | none | **No — replace** | kills cookie fragility |
| Account/contact + billing dates | `accounts/v3/contact` | `/account` (+`/billing` dates) | Partial | billing-day, business flag | partial | needs AccountInformation grant |
| Service-line + router map | `webagg/service-lines` | `/service-lines`+`/user-terminals` | Partial | pause/standby, lastConnected, hw ver | **keep for device bits** | cleaner identity |
| Usage GB | `telemetryagg/annotated` | `/data-usage/query` | Partial(+) | allowance source, day-lag | partial | cleaner priority/standard split |
| Invoices (cost) | `webagg invoice-balances/invoice` | `/billing/*` | Partial | paymentMethod, msrp, adjustment; **Financial-gated** | partial | per-line `serviceLineNumbers[]` |
| Plans/subscription | webagg + diag | `/products` + SL product id | Yes-ish | pendingActivation flag | replace | official plan price |
| Orders / shipment | `webagg orders` | **none** | No | whole feature | **keep current** | — |
| Dish-online / client counts | gRPC `get_status` | **none** | No | all | **keep current** | — |
| WiFi SSID/password change | gRPC `wifi_*config` | **none** | No | all | **keep current** | — |
| Per-client pause / auto-block | gRPC + self-HTTP | **none** (reboot/deactivate only) | No | all | **keep current** | — |

**The objective is the best architecture, not forcing everything onto the official API.**

## 10. Discrepancies — **[NV] PENDING PROBE**
Quantify from the probe vs current records:
```
total current records (DR) = ____     total official API records = ____
matched = ____   API-only = ____   current-only = ____   ambiguous = ____
same kit / service / customer / status / dates / usage / billing?  (per-field)
```
Do not modify any record. A mismatch is a finding to investigate, **never** an auto-correction, and
**historical finance records are never recalculated from current API state** (refunds/cancellations/
re-dates would corrupt closed periods — `21`§H, §12).

## 11. Rate limits / reliability — [DOC]
- **250 requests/min per account**; **1,000 token requests / 15 min per IP** → cache the token,
  never mint per request. [DOC]
- **Pagination:** page-index (`pageIndex/limit/isLastPage/totalCount/results`) and cursor
  (`nextKey/results`) — probe handles both. [DOC]
- **Errors:** business failures = **HTTP 422** inside the `ServiceResponse` envelope (`isValid:false`,
  `errors[]`) → do not retry; a `2xx` with `isValid:false` is also a failure. `403
  UserLacksRequiredPermission` = permission gap → surface, don't retry. `401` → refresh once.
  `429/5xx` → backoff + retry. [DOC]
- **No webhooks** → scheduled polling only. [DOC] Reliability for scheduled jobs: adequate for the
  current cadence given the small fleet; confirm token-expiry/refresh behaviour with the probe. [NV]

## 12. Security observations
1. **A live client secret was shared in chat + a screenshot. ROTATE it.** Mint a fresh secret (the
   screen allows up to 20), use it, delete the exposed one. Secrets belong in environment variables
   on the run host, never in chat/screenshots/Git. This session has **not** stored or committed it.
2. **Use a dedicated view-only credential for the probe** — grant *View* on Account information,
   Financial, Service, Device; do **not** grant *Device command and configuration management*
   (write/reboot). The current credential carries device-command/config power a read validation
   should not.
3. The probe redacts all secrets/tokens, stores no raw payloads, and pseudonymises identifiers.
4. Pre-existing (from `11`§H, out of scope but recorded): `data/crm_invoice_export.json` ships with
   real customer PII; finance's WiFi queue stores plaintext passwords; its WiFi API is CORS-open.

## 13. Recommended architecture — provisional, pending probe
**Option C → trending B: existing integration stays primary; official API adopted selectively, in a
proven hybrid.** [INF]
- **Adopt first, independently valuable:** replace the cookie/session machinery with **OIDC** for the
  read endpoints the credential is granted (service-lines, user-terminals, products; usage; and
  invoices/account *iff* the operator grants Financial/AccountInformation).
- **Keep current, no official equivalent:** the **device plane** (WiFi config, per-client pause,
  auto-block actuation, dish-online/client telemetry) and **orders/shipment**.
- **Finance stays downstream and untouched** until the data-report source-of-truth transition is
  proven: it consumes `sl_invoice_lines.json` / `dr_kit_registry.json` and calls Starlink never
  (`11`, `30`). uCRM/accounting remains authoritative for customer price, payment, balance, revenue.
- **Final A/B/C choice is contingent on the probe** (scope + which reads are permitted + field parity).

## 14. Proposed migration strategy — **not started; approval-gated** (also see `60`)
1. Run the probe; record §§2–10 live. 2. If reads are permitted and parity holds, implement a V2
   client in data-report **behind a flag, read-only**, writing to **shadow** files. 3. **Side-by-side**
   for a full billing cycle: compare V2-derived vs scraped for the same population (kit/SL/customer/
   status/usage/invoice), diff reported, **nothing switched**. 4. Only after parity is demonstrated,
   flip the read source **per domain**, preserving every output-file contract. 5. Device plane +
   orders stay as-is. 6. Finance untouched throughout; it keeps reading the same files.

## 15. Rollback strategy (also see `60`)
Flag-controlled: the V2 reader is additive and off by default; disabling it reverts to the scraped
path instantly. No schema/file-contract change during side-by-side (shadow files only). Last-good
files are retained so a V2 outage degrades to stale-but-correct data. **No finance record is ever
rewritten**, so there is nothing to roll back downstream. Secret rotation + credential revocation are
independent of code.

## 16. Exact unanswered questions (the probe answers 1–6)
1. Is the credential **org-wide (managed hierarchy)** or **single-account**? (`/managed/accounts/tree`)
2. `accessible_account_count` / `service_count` / `kit_count`?
3. Do those match data-report's current population (matched / API-only / current-only)?
4. With current grants, which endpoints return **OK vs DENIED** (confirm Account/Financial gating)?
5. **Field parity** per `20`: does `/data-usage` carry an **allowance**? do `/service-lines` really
   omit pause/standby/lastConnected? does `/billing` omit paymentMethod/msrp/adjustment?
6. Token-expiry/refresh behaviour and any rate-limit headers in practice?
7. **Operator decisions (not probe):** grant Financial/AccountInformation View? mint a view-only
   credential? one credential per Starlink account if single-account scope?

---

## FINAL REPORT

1. **WHAT WORKS TODAY** — data-report scrapes Starlink's internal endpoints (cookie auth) for account,
   service-lines/routers, usage, invoices, orders, and drives the gRPC device plane (WiFi change,
   pause, auto-block, dish status). Finance consumes data-report's files + uCRM; it calls Starlink
   never and recognizes revenue nowhere. All of this is working and must be preserved.
2. **WHAT OFFICIAL API CAN REPLACE** — the fragile **auth/session** layer (OIDC), and the **reads** the
   credential is granted: service-lines, user-terminals, products, usage, and — if Financial/
   AccountInformation are granted — account + invoices. [INF; confirm with probe]
3. **WHAT OFFICIAL API CANNOT REPLACE** — WiFi SSID/password, per-client pause, **auto-block
   actuation**, live dish/client telemetry, and **orders/shipment**: no V2 equivalent exists (`01`§D).
4. **DOES ONE API KEY HANDLE ALL ACCOUNTS?** — **NOT VERIFIED YET.** Determined by the probe's
   `/managed/accounts/tree`: hierarchy with N>1 → org-wide; else single-account. The current
   credential also lacks Account/Financial View as issued (§2).
5. **DATA REPORT COVERAGE** — read/billing half = Partial-or-better (field losses listed in `20`/§9);
   device plane + orders = No. Hybrid is the ceiling.
6. **FINANCE IMPACT** — none until the data-report source transition is proven. Finance stays a
   downstream file consumer; uCRM/accounting stays authoritative for money; no historical recompute.
7. **RECOMMENDED NEXT STEP** — (a) rotate the exposed secret; (b) optionally mint a view-only
   credential and tick Financial/AccountInformation View; (c) **run `probe/starlink_api_probe.py`** and
   paste the redacted SUMMARY; (d) complete §§4–10; then decide A/B/C. **Do not start migration.**
8. **RISKS** — assuming org-wide scope without proof; assuming field parity (allowance, pause flags);
   the exposed secret; granting a write-capable credential to a read path; conflating Starlink cost
   with customer payment/revenue; breaking the `sl_invoice_lines.json`/registry file contracts.
9. **TEST EVIDENCE** — egress blocked (`CONNECT tunnel failed, 403`, credential-free); no creds in
   session env; probe compiles (`python3 -m py_compile` OK) and contains no secret; **no live API call
   was made from this session.**
10. **FILES/COMMIT** — `docs/starlink-api-integration/05-official-api-live-access-test.md` (this),
    `probe/starlink_api_probe.py`, `probe/README.md`. Documentation + safe local tooling only; no
    secret committed; no production file/config changed.

*Live sections complete once the probe is run. Migration remains unstarted and approval-gated.*
