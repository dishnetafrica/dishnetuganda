# 06 — Starlink account mapping: existing (cookie-era) ↔ official API

**Task (operator):** determine whether the Starlink accounts already known to Data Report can be
**deterministically mapped** to official-API accounts, so the estate can eventually be pulled through
the official API (per account), **preserving everything that works today**. **READ-ONLY
investigation — no migration, no cookie removal, no production/schema/Finance change.**

**Evidence labels:** **[LIVE]** verified against the live API (`05`) · **[DOC]** official spec (`01`) ·
**[CURRENT]** verified from the existing plugin source/data model · **[INFERRED]** logical inference ·
**[NV]** not verified.

> **Headline [CURRENT + LIVE]:** the mapping is **deterministic, and already materialised in the
> plugins' own data.** Finance's `sl_kits.json` stores **`starlink_account_number`** *and*
> **`crm_client_id`/`crm_service_id`** on every kit record, and both plugins store `account_number`
> + `service_line` + `kit_number` — the **exact primary keys the official API returns**
> (`accountNumber` / `serviceLineNumber` / `kitSerialNumber`). So the full chain
> **kit → Starlink account → service line → uCRM customer/service is present locally, no guessing.**
> The only thing missing to pull the whole estate via the official API is an **API credential per
> account** (the live credential is single-account). Mapping is an **access** problem, not an
> identity problem.

---

## 1. Existing Data Report / Finance account representation [CURRENT]
Storage is flat JSON (no DB). The Starlink-account dimension lives across:

| File (plugin) | Keyed by | Account-relevant fields it stores |
|---|---|---|
| `dr_accounts.json` (data-report) | **account number** | accountName, accountType, regionCode, isBusinessCustomer, billingDayOfMonth, nextDueDate, `service_lines[]`, session/cookie state (vaulted) |
| `sl_svc_cache.json` (data-report) | service line | `service_line`, `kit_number`, `account_number`, nickname, plan_id, subscription_active/endDate, status, serviceAddress |
| `wifi_router_map.json` (data-report) | router | `account_number`, `service_line`, `kit_serial`, `terminal_id`, customer_name, nicknames, hardware_version, last_connected |
| `sl_kits.json` (**finance, authoritative**) | **kit_number** | `kit_number`, `account_number`, **`starlink_account_number`**, `starlink_account_status` (manual), **`crm_client_id`**, **`crm_service_id`**, billing fields, history |
| `sl_accounts.json` (finance) | account | Starlink account list (finance-authoritative) |

Field-frequency evidence (grep of plugin source): data-report — `kit_number`×123, `account_number`×101,
`service_line`×73, `kit_serial`×22, `terminal_id`×4, plus API-shaped `accountNumber`/`serviceLineNumber`/
`kitSerialNumber`/`dishSerialNumber`/`userTerminalId`; finance `sl_kits.json` — `kit_number`×367,
`account_number`×219, `starlink_account_number`×109, `crm_client_id`×118, `crm_service_id`×34. [CURRENT]

> **The actual per-account VALUES live only on the server** (runtime JSON), not in the repo. §Appendix
> gives a read-only command to emit a **redacted** account inventory (ACCOUNT-001 …) from the server.

## 2. Existing identifiers available for mapping [CURRENT]

| Identifier | Stored as | Strength as a join key |
|---|---|---|
| Starlink **account number** | `account_number` / `starlink_account_number` | **STRONG** (exact, globally unique) |
| **service line number** | `service_line` | **STRONG** (exact, globally unique, `SL-DF-…`) |
| **kit serial** | `kit_number` / `kit_serial` | **STRONG** (exact, globally unique, `KIT…`) |
| dish serial | `dishSerialNumber` (×12, partial) | MEDIUM (present but not on every record) |
| user-terminal id | `terminal_id` / `userTerminalId` | MEDIUM-STRONG (exact where present) |
| address | `serviceAddress` (text); `addressReferenceId` ×1 only | **WEAK** (text/geo; the API's UUID barely captured) |
| uCRM link | `crm_client_id`, `crm_service_id` | bridge to uCRM (not a Starlink key) |

## 3. Official API identifiers returned [LIVE + DOC]
`accountNumber`, `serviceLineNumber`, `kitSerialNumber`, `dishSerialNumber`, `userTerminalId`,
`addressReferenceId`, `productReferenceId`, `regionCode`, `active`, `endDate`, invoice `invoiceId`, …
(the exact shapes observed in `05`§5).

> **The three STRONG local keys (`account_number`, `service_line`, `kit_number`) are byte-for-byte the
> three primary keys the API returns.** No transformation, no fuzzy matching. [CURRENT + LIVE]

## 4. Current-account mapping (the one the live credential sees) [LIVE + CURRENT]
The live credential returns **1 account / 4 service-lines / 1 user-terminal**, with `accountNumber`,
`serviceLineNumber`, `kitSerialNumber`, `dishSerialNumber`. Data Report stores those same fields for
that account. **Verified value-level match is the one remaining read-only check** — run
`probe/starlink_kit_lookup.py` with that account's kit/SL pairs: FOUND + matching `serviceLineNumber`
proves the deterministic key. (The 8 kit=SL pairs you supplied are the ideal input; the FOUND ones are
this account, the NOT_FOUND ones are other accounts — `05`§7.) **[NV → run the lookup]**

## 5. Other-account mapping possibilities [CURRENT + INFERRED]
For the other ~3 accounts, Data Report/Finance **already stores** their `starlink_account_number`,
`service_line`, and `kit_number` (from the cookie era). So each is **identity-mappable today** — the
API would return the identical keys *if a credential for that account existed*. The missing element is
**authorised access per account**, not identifiers. **Do not use old cookies to probe Starlink during
this exercise** (operator rule); identity mapping needs no live call for the non-visible accounts — it
is a local join, confirmed per-account as each account's credential comes online.

## 6. Mapping confidence per account
| Class | Meaning | Which accounts |
|---|---|---|
| **A — DIRECTLY MAPPABLE** | strong exact key (`account_number`+`service_line`+`kit_number`) present locally | **all accounts that carry these fields in `sl_kits.json`/`dr_accounts.json`** (expected: all) [CURRENT] |
| B — PROBABLY MAPPABLE | only partial/medium keys | any record missing `starlink_account_number` but having kit/SL |
| C — NOT MAPPABLE YET | no strong key, or needs Starlink info | none expected; confirm via the §Appendix inventory |

**Expected outcome [INFERRED]:** essentially all accounts are **Class A** — the plugins were built to
store these exact keys. The §Appendix extraction confirms it with real counts.

## 7. Official multi-account authentication model [LIVE + DOC]
| Option | Supported? | Evidence |
|---|---|---|
| **A** — one credential → many *unrelated* accounts | **NO** | [LIVE] this credential saw only its own account |
| **B** — one parent/managed credential → child accounts | **MECHANISM EXISTS, not configured** | [LIVE] `/managed/accounts/tree` works but **0 children**; [DOC] the `/managed/accounts/*` endpoints + `ManagedAccount*` permissions exist |
| **C** — one credential → one account | **YES (observed)** | [LIVE] 1 acct / 4 SLs / 1 kit |
| **D** — many credentials → many accounts | **YES (workable)** | [INFERRED from C + DOC] mint a service-account credential inside each Starlink account |

> **Whether DishNet's existing accounts can be *linked* under one managed/parent hierarchy (Option B)
> is a Starlink account-structure question** — it needs the operator/Starlink, and **must not be
> changed just for testing.** If it is possible, one parent credential could read the whole estate via
> `/managed/accounts/*`; if not, **Option D (per-account credentials)** is the path.

## 8. One-account vs multi-account findings [LIVE]
**One-account, confirmed.** The estate (~4 accounts) is **not** reachable from the single live
credential. Coverage = per-account credentials (D) or a populated managed hierarchy (B).

## 9. Proposed Starlink account registry (design only — NOT implemented)
A small registry that lets Data Report pull per account. **Reference, don't store, secrets.**
```
starlink_account_registry (one row per Starlink account)
  internal_id              stable local id
  starlink_account_number  the API accountNumber  (STRONG join key)   [the deterministic anchor]
  account_name             accountName / local nickname
  region_code              regionCode
  access_method            'official_api' | 'cookie' | 'none'
  credential_ref           REFERENCE to a secret-store entry (NEVER the secret itself)
  access_scope             'single_account' | 'managed_parent' | 'managed_child'
  parent_account_number    null unless a managed child
  status                   active | read_only | unauthorised | retired
  last_successful_sync     timestamp
  last_sync_method         'api' | 'cookie'
  mapping_confidence       A | B | C
  ucrm_link                how this account's kits tie to uCRM (via sl_kits crm_client_id/service_id)
```
Join model: `kit_number → sl_kits.starlink_account_number → registry.starlink_account_number →
credential_ref`. Everything but `credential_ref` already exists in `sl_kits.json`. [CURRENT]

## 10. Credential-management design (design only)
- **Never** store client secrets in ordinary JSON/DB fields. `credential_ref` points to a secure
  store. Reuse the plugin's **existing proven vault** (AES-256-GCM, key = PBKDF2 of uCRM
  `config.json` secret + per-install salt; chmod 0600 — the same mechanism that holds the cookies
  today), or an environment/secret manager. [CURRENT — mechanism already exists]
- One credential entry **per account** (Option D), or one parent entry (Option B).
- **Least privilege & read-only** for sync (View on Account/Financial/Service/Device; **not** Device
  Command). Rotate on exposure. The secret never appears in logs, UI, backups-in-clear, or Git.
- The backup cron zips `data/` — keep the credential store **encrypted** so backups never carry a
  usable secret (as the cookie vault already is).

## 11. Cookie → API migration concept (concept only — not now)
```
CURRENT:   Data Report → dr_accounts (cookie, vaulted) → Starlink internal endpoints → data
FUTURE:    Data Report → account registry → per-account official credential → official API →
                         normalised data   (falling back to the cookie path only where the API
                         cannot provide a function — device plane, orders)
```
Per-account cutover, flagged, shadow files first, side-by-side compared on the STRONG keys, then flip
read source per domain — **cookies retained until each account is proven** (`05`§14, `60`).

## 12. Hybrid fallback architecture [LIVE-grounded]
The official API cannot do WiFi config, per-client pause, **auto-block actuation**, live telemetry, or
orders (`05`§3, `20`). So even for a fully-credentialled account, the **device/gRPC plane and the
orders scraper remain**. The registry's `access_method` is **per-account**; an account with no API
credential stays fully on cookies. **Nothing is removed until its replacement is proven per account.**

## 13. Risks
- **Assuming every credential behaves like this one.** Only one account was tested; confirm each.
- **Assuming Option B is available.** Managed hierarchy has 0 children; linking accounts is a Starlink
  structural change, operator-gated — not assumed.
- **Secret sprawl** across per-account credentials → must use the secure store + least-privilege +
  rotation; never per-account secrets in JSON/Git.
- **Address is a weak key** — do not map on address; use account/SL/kit.
- **Finance invariants** — mapping must never drive customer price/payment/revenue from the API; cost
  vs billing stays separate (`21`).
- **Don't break file contracts** (`sl_invoice_lines.json`, `dr_kit_registry.json`) during any change.

## 14. Exact information still required (from Starlink / DishNet)
1. **A credential per remaining account** (operator mints inside each Starlink account), **or**
2. Confirmation that the accounts **can be linked under a managed/parent hierarchy** (Starlink
   support/account-structure) so one parent credential reads all via `/managed/accounts/*`.
3. The **value-level confirmation** for the visible account (run `starlink_kit_lookup.py`).
4. The real **per-account inventory counts** (§Appendix extraction on the server).
5. `/products` 422 cause; usage (`/data-usage/query`) parity.

---

## FINAL ANSWER

```
CURRENT STARLINK ACCOUNTS:     ~4   (Data Report / prior audit; confirm via Appendix extraction)
OFFICIAL API ACCOUNTS:         1    (this credential; LIVE)
DIRECTLY MAPPABLE:             all accounts carrying account_number+service_line+kit_number locally
                               (expected: all — the STRONG keys are stored; confirm counts via Appendix)
PROBABLY MAPPABLE:             only records missing starlink_account_number (expected: few/none)
NOT MAPPABLE:                  expected 0 (confirm via Appendix)
CURRENT ACCOUNT MATCH:         accountNumber / serviceLineNumber / kitSerialNumber / dishSerialNumber
                               — the API returns the exact keys the plugins store (value-level check
                               pending the kit-lookup run)
OFFICIAL MULTI-ACCOUNT MODEL:  single-account per credential observed; managed-hierarchy mechanism
                               exists but has 0 children → use per-account credentials (Option D), or
                               link accounts under a managed parent (Option B, Starlink-gated)
RECOMMENDED NEXT TEST:         run probe/starlink_kit_lookup.py with the known KIT=SL pairs on the
                               current credential → confirm FOUND+matching serviceLineNumber (proves
                               deterministic mapping); separately run the §Appendix read-only
                               extraction to emit the redacted per-account inventory.
```

**DO NOT implement migration. DO NOT remove cookies. DO NOT change production.**

---

## Appendix — read-only per-account inventory extraction (operator runs on the server)
Emits a **redacted** account inventory from the plugins' own data (no secrets; account numbers
shortened). Adjust the path if the plugin dir differs.
```bash
# Finance sl_kits.json is the authoritative kit↔account↔uCRM map.
F=/data/ucrm/data/plugins/dishnet-starlink-finance/data/sl_kits.json
python3 - "$F" <<'PY'
import json,sys,collections
raw=json.load(open(sys.argv[1]))
recs=[v for v in (raw.values() if isinstance(raw,dict) else raw) if isinstance(v,dict)]
by=collections.defaultdict(lambda:{"kits":set(),"sls":set(),"crm":0})
for r in recs:
    acct=str(r.get("starlink_account_number") or r.get("account_number") or "UNKNOWN")
    b=by[acct]
    if r.get("kit_number"): b["kits"].add(r["kit_number"])
    if r.get("service_line"): b["sls"].add(r["service_line"])
    if r.get("crm_client_id"): b["crm"]+=1
for i,(acct,b) in enumerate(sorted(by.items()),1):
    red = acct[:6]+"…" if acct not in ("UNKNOWN","") else acct
    print(f"ACCOUNT-{i:03d}  acct={red}  kits={len(b['kits'])}  service_lines={len(b['sls'])}  kits_with_crm_link={b['crm']}")
print(f"\nTOTAL accounts={len(by)}  kits={sum(len(b['kits']) for b in by.values())}  service_lines={sum(len(b['sls']) for b in by.values())}")
PY
```
Paste the redacted output back to fill §1/§6 and the FINAL ANSWER counts. (It prints only shortened
account numbers + counts — no secrets, no customer PII.)
