# 05 — Official API V2: live-access test record

**Task:** a READ-ONLY validation of the **official** Starlink Public API V2 against DishNet's real
service-account credential, to prove scope/coverage **before** any change to the working
data-report integration. **No migration started.** No code/schema/flag/plugin/deploy changed; no
write/mutation made against any system.

**Status: EXECUTED 2026-10-03** on the DishNet server (which has egress to `starlink.com`), using
`probe/starlink_api_probe.py`. The Claude Code session itself cannot reach `starlink.com`
(egress `403`), so the operator ran the probe and pasted back the redacted summary.

**Evidence labels:** **[LIVE]** verified from the live API · **[DOC]** from the V2 OpenAPI spec
(`01`) · **[SHOT]** from the service-account screenshot · **[INF]** inference · **[NV]** not verified.

---

## 1. Executive result

**Authentication succeeded and the credential is SINGLE-ACCOUNT.** [LIVE]
- Auth **PASS** (OIDC client-credentials, body auth; 13 GET calls; token never printed).
- It sees **1 Starlink account · 4 service-lines · 1 user-terminal (kit) · 8 addresses · 5 invoices**.
- The **managed-accounts** hierarchy is reachable (`/managed/accounts/tree` → `rootAccountNumber`,
  `tree`) but has **0 child accounts**, and the managed queries return the **same** 4 service-lines as
  the direct ones — so nothing beyond this one account is visible.
- The existing Data Report tracks ~**4 accounts / 16 service-lines / 2 kits** (prior audit `docs/39`),
  so **this credential covers only part of the estate.** *Single-account access observed;
  organization-wide access not proven.*
- **Permissions are broader than the screenshot implied:** `/account` and `/billing/*` returned **OK**
  despite "Account information"/"Financial" appearing unticked (`§2`). Live evidence overrides the
  screenshot.
- The official API remains a **read/billing/service/device-reboot** surface — **no** WiFi config,
  per-client pause, auto-block actuation, live telemetry, or orders (`01`§D, `20`). A migration is
  **hybrid at best**; the mapping of the rest of the estate is pursued in `06`.

## 2. Credential scope — LIVE permission results (corrects the screenshot)

| Feature | Live result | Endpoint evidence |
|---|---|---|
| **Account information** | **ALLOWED** | `GET /account` → OK (accountName, accountNumber, activeSuspensions, regionCode) |
| **Financial** | **ALLOWED** | `GET /billing/invoices` → OK (5); `GET /billing/balance` → OK |
| **Service** | **ALLOWED** | `GET /service-lines` → OK (4); `/products` → 422 (validation, not permission) |
| **Device (read)** | **ALLOWED** | `GET /user-terminals` → OK (1; routers nested) |
| **Device command** | **NOT TESTED** | never invoked (read-only probe) |

[LIVE] — so the earlier [SHOT] reading ("Account info / Financial ungranted") is **superseded by live
evidence**: this credential can read account, service-lines, user-terminals, addresses, invoices, and
balance for the account it belongs to.

## 3. Account-access model [LIVE + DOC]
- **Observed: one credential → one account.** `/account` = 1; `/managed/accounts` = **0 children**;
  managed service-lines (4) == direct service-lines (4). The credential's reach **is** this one
  account.
- **The managed-accounts *mechanism* exists** (`/managed/accounts/tree` works, returns a
  `rootAccountNumber` + `tree`) [LIVE] — so an **org-wide** model is *possible in principle* if the
  other accounts were linked as children, but **none are today** [LIVE]. Whether DishNet's other
  accounts *can* be placed under a managed hierarchy is a Starlink account-structure question
  (`06`§5, operator/Starlink). **Do not change account structure for testing.**
- **Verdict:** *Single-account access observed; organization-wide access not proven.*

## 4. Accessible population [LIVE]
```
accessible_account_count   = 1
accessible_service_count   = 4   (service-lines; managed == direct)
accessible_kit_count       = 1   (user-terminal; routers nested inside)
addresses                  = 8
invoices                   = 5
```

## 5. Endpoint test results [LIVE]

| Endpoint | Method | Result | Count | Key fields returned |
|---|---|---|---|---|
| `/account` | GET | **OK** | 1 | accountName, accountNumber, activeSuspensions, regionCode |
| `/managed/accounts/tree` | GET | **OK** | 1 | rootAccountNumber, tree |
| `/managed/accounts` | GET | **OK** | **0** | (no children) |
| `/managed/accounts/service-lines` | GET | **OK** | 4 | accountNumber, serviceLineNumber, productReferenceId, active, endDate, addressReferenceId, nickname, … |
| `/managed/accounts/user-terminals` | GET | **OK** | 1 | accountNumber, serviceLineNumber, kitSerialNumber, dishSerialNumber, userTerminalId, routers, nickname |
| `/service-lines` | GET | **OK** | totalCount=4 | (same SL shape) |
| `/user-terminals` | GET | **OK** | totalCount=1 | kitSerialNumber, dishSerialNumber, userTerminalId, serviceLineNumber, routers |
| `/products` | GET | **ERROR 422** | — | business-validation error (not 403/permission) — needs a param or no catalog at this level |
| `/addresses` | GET | **OK** | totalCount=8 | addressReferenceId, formattedAddress, latitude, longitude, region, regionCode, … |
| `/billing/invoices` | GET | **OK** | totalCount=5 | invoiceId, amount, currency, dueAmount, dueDate, invoiceDate, status, description |
| `/billing/balance` | GET | **OK** | 1 | balances |
| `/data-usage/query` | POST-read | **NOT TESTED** | — | deliberately skipped (POST-style read; follow-up) |

## 6. Multi-account findings [LIVE]
**NOT PROVEN.** `/managed/accounts` returned **0** children and managed==direct scope. This single
credential exposes exactly one account's resources. To cover the other ~3 accounts, DishNet needs
either a credential per account (`06`§5 Option D) or the accounts linked under a managed hierarchy
(Option B — not configured today).

## 7. Multi-kit findings [LIVE]
This credential exposes **1 user-terminal (kit)** with its nested routers. Data Report tracks ~2 kits
across the estate, so the other kit(s) live in other accounts this credential cannot see. The
`06` mapping shows the plugins already store `kitSerialNumber`↔`accountNumber` for all of them.

## 8. Data Report comparison [LIVE + CURRENT]
| | This API credential | Data Report (prior audit) |
|---|---|---|
| accounts | **1** | ~4 |
| service-lines | **4** | ~16 |
| kits | **1** | ~2 |
→ **Partial coverage.** The field *shapes* match exactly (accountNumber / serviceLineNumber /
kitSerialNumber / dishSerialNumber / addressReferenceId are the same identifiers Data Report stores —
`06`§2), so for the account it can see, the mapping is **deterministic**. The gap is **access**, not
identity. A per-field value-level reconciliation (does the API's 4 SL numbers equal Data Report's 4
for this account?) is the recommended next read-only check (`06`, kit-lookup tool).

## 9. Coverage matrix — provisional, now with live permissions
Unchanged from `20` on *capability* (reads = Partial+; device plane + orders = No), **plus** the live
fact that this credential **can** read account + billing (so those reads are available per-account,
subject to a credential existing for each account).

## 10. Discrepancies [LIVE]
```
current records (DR, prior audit): ~4 accounts / ~16 SLs / ~2 kits
official API (this credential):      1 account  /   4 SLs  /  1 kit
matched (this account):              pending value-level check (06 / kit-lookup)
API-only:                            none observed
current-only:                        ~3 accounts / ~12 SLs / ~1 kit (in other accounts, unreachable
                                     by this credential)
```
No record modified. A mismatch is a finding to investigate, never an auto-correction; **historical
finance is never recomputed from current API state** (`21`§H).

## 11. Rate limits / reliability [DOC + LIVE]
- [DOC] 250 req/min per account; 1,000 token/15min per IP; no webhooks (poll only); 422 = business
  error in the `ServiceResponse` envelope; 403 = `UserLacksRequiredPermission`.
- [LIVE] 13 calls completed without a rate-limit block; no rate-limit headers were surfaced in this
  run. Token mint via **body auth** worked (no Basic needed).

## 12. Security observations
1. **ROTATE the client secret** — it was shown in chat + a screenshot (operator will rotate after
   review). This session never stored/committed it.
2. The credential also holds **device command/configuration (write)** — more than reads need; a
   dedicated **view-only** credential is recommended for ongoing read use.
3. The probe redacted all secrets/tokens, saved no raw payloads, pseudonymised ids.

## 13. Recommended architecture [INF, from live + `20`/`30`]
**Hybrid, per-account.** The official API can source **account, service-lines, user-terminals,
addresses, invoices, balance** for *each account that has a credential*; the **device plane**
(WiFi/pause/auto-block/telemetry) and **orders** stay on the current mechanism (no V2 equivalent).
Multi-account requires **either** one credential per Starlink account **or** a managed hierarchy
(`06`). Finance stays downstream and untouched; uCRM/accounting authoritative for money.

## 14. Proposed migration strategy — not started; approval-gated (also `60`)
Build the per-account registry (`06`) → mint/collect a credential per account → V2 read client in
data-report **behind a flag, shadow files** → side-by-side for a full cycle → flip per domain,
preserving file contracts → device plane + orders unchanged → finance untouched.

## 15. Rollback strategy (also `60`)
Flag-off reverts to the scraped path instantly; shadow files during side-by-side; last-good files on
outage; no finance record ever rewritten; secret rotation/revocation independent of code.

## 16. Exact unanswered questions
1. [answered — LIVE] org-wide vs single-account → **single-account** (0 managed children).
2. Value-level match: do the API's 4 SL numbers / 1 kit for this account equal Data Report's stored
   values for it? (next read-only check — `06` kit-lookup)
3. Can DishNet's other accounts be **linked under a managed hierarchy** (one parent credential), or is
   **per-account credentials** the only path? (Starlink account-structure question)
4. `/products` 422 cause (param needed? empty catalog?).
5. Usage (`/data-usage/query`, POST-read) field parity incl. allowance.
6. Per-account credential + secret-storage design (`06`§6/§10).

---

## FINAL REPORT

1. **WHAT WORKS TODAY** — data-report scrapes Starlink (cookie auth) for account/SL/usage/invoices/
   orders + drives the gRPC device plane; finance consumes data-report files + uCRM, calls Starlink
   never, recognizes revenue nowhere. All preserved.
2. **WHAT OFFICIAL API CAN REPLACE** — [LIVE] per-account: auth (OIDC), account, service-lines,
   user-terminals, addresses, invoices, balance. (Usage likely, pending the POST-read check.)
3. **WHAT OFFICIAL API CANNOT REPLACE** — WiFi config, per-client pause, **auto-block actuation**,
   live dish/client telemetry, **orders/shipment**. No V2 equivalent.
4. **DOES ONE API KEY HANDLE ALL ACCOUNTS?** — **NO (not with this credential).** [LIVE] It is
   single-account (1 acct / 4 SLs / 1 kit; 0 managed children). The estate needs per-account
   credentials or a managed hierarchy (`06`).
5. **DATA REPORT COVERAGE** — read/billing half = Partial-or-better *per account*; device plane +
   orders = No. This credential covers ~1 of ~4 accounts.
6. **FINANCE IMPACT** — none until the data-report source transition is proven; finance stays a file
   consumer; uCRM/accounting authoritative; no historical recompute.
7. **RECOMMENDED NEXT STEP** — run `probe/starlink_kit_lookup.py` for the known kits to confirm the
   deterministic per-account match, and build the account registry (`06`). Then decide per-account
   credentials vs managed hierarchy. **Do not start migration.**
8. **RISKS** — assuming other credentials behave like this one; the exposed secret; a write-capable
   credential on a read path; conflating Starlink cost with customer payment/revenue; breaking the
   `sl_invoice_lines.json`/registry file contracts.
9. **TEST EVIDENCE** — [LIVE] run from the DishNet server: auth PASS, 13 calls, the §5 matrix; probe
   redacted, no secret printed/stored; no write/mutation; device command never invoked.
10. **FILES/COMMIT** — this doc; `probe/starlink_api_probe.py`, `probe/starlink_kit_lookup.py`,
    `probe/README.md`. Documentation + safe local tooling only; no secret committed; no production
    change.

*Account-to-credential mapping for the rest of the estate is investigated in `06`.*
