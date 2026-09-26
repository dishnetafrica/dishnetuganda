# 37 — Customer journey and three-plugin synchronisation audit (26 September 2026)

**Status: READ-ONLY. No code change, no migration, no sync, no unstick, no customer modification, no
deployment, no change to Starlink Finance, Data Report or Hybrid.** Requested by the operator before the
data-report redesign (docs/36 §J) is approved. Controlled customer: **Family Shoppers, CRM client #1**
(the operator's own record). Every personal identifier in this document and in the command's output is
masked; secrets and tokens are never printed.

Two kinds of statement appear below and are labelled: **REPOSITORY** (read in `dishnet-hybrid-sudan`
and in the earlier server-measured audits docs/38–40, docs/35–36) and **PENDING** (needs one of the four
read-only runs of `scripts/journey-audit.sh` on the server, §H). Nothing PENDING is asserted.

---

## A. The customer journey as it actually runs today (REPOSITORY)

```
 uCRM (system of record for client, service, invoice, payment)
   │ webhooks client.add/edit, invoice.add, payment.add, service.*     │ 60-second delta cron (clients)
   ▼                                                                    ▼
 dishnet-hybrid-sudan ─ client_search_index (id, name, phone, email, flags) ← the SIGN-IN KEY
   │  ucrm_clients_cache · ucrm_services_cache · ucrm_plans_cache · ucrm_invoices_cache · payments cache
   │  equipment_assignments + stock_units  (the hybrid's OWN kit register, Uganda binding)
   │
   │  OTP by WhatsApp (Evolution) or e-mail → HttpOnly cookie session (customer key set, kid/iss/aud)
   ▼
 Customer portal / PWA  (home · account · plans · invoices · usage · sites · support · devices)
   │ app_me/app_account/app_plan/app_invoices/app_payments/app_equipment/app_usage/app_legal_version …
   │
   ├─ reads FILES of  dishnet-starlink-finance : sl_kits.json (kit ↔ crm id, typed by staff)  ← Sudan-style register
   ├─ reads FILES of  dishnet-data-report      : wifi_router_map · sl_svc_cache · dr_kit_registry · sl_usage · block state
   ├─ calls HTTP      dishnet-data-report      : ?action=dr_wifi_*  (pause/unpause/password/config/status)
   └─ hands the browser to dishnet-data-report/public.php?clientId=…&token=…  → 404 on Uganda (docs/36)

 dishnet-data-report  = the Starlink client (api.starlink.com), the WiFi control plane, its own crons + SQLite,
                        its own copy of uCRM services (via its app key); tick = main.php behind main.lock
 dishnet-starlink-finance = kit register + finance, file-backed, its OWN copy of uCRM clients/services/invoices
```

**Step table** (source of truth · owner · where · id used · copied/referenced · how it moves · failure mode):

| Step | Source of truth | Owner / store | Identifiers | How it reaches the others | If it fails |
|---|---|---|---|---|---|
| Lead → customer | uCRM client | uCRM | client id | webhook `client.add` → hybrid index + clients cache; 60 s delta cron; **Finance keeps its own `crm_clients_cache.json`** (its cron, PENDING) | hybrid: the customer cannot sign in until the next delta or webhook; Finance: unknown |
| Service | uCRM service | uCRM | service id, plan id, price | webhook `service.*` (block/unblock, notifications); `ucrm_services_cache` refreshed by the staff sync actions / cron (`api_crm_misc`, `cron_sync`); Finance's `crm_services_cache.json`; Data Report reads services live with its app key | stale plan/price in the portal until refresh |
| Invoice / payment | uCRM | uCRM | invoice id, payment id | webhook `invoice.add`/`payment.add` → surgical cache refresh (`ClientInvoiceCacheRefresher`), else on-demand when stale; `app_account` asks uCRM live | a missed webhook shows a paid invoice as due until the next app open |
| Starlink kit | **DISPUTED** — Finance `sl_kits.json` (typed) *and* hybrid `equipment_assignments` (validated, DB-enforced) | two owners | kit number/serial, crm id, service line, account | Data Report reads `sl_kits.json`; hybrid reads both; nothing reconciles them | a kit in one register and not the other: authorisation and usage disagree |
| Starlink account / session | Data Report `dr_accounts.json` (+ `sl_sync_settings.json` cookie) | Data Report | account number | its crons; Finance `sl_accounts.json` (billing days) | sync stops (the stuck lock) |
| Usage | Starlink API via Data Report cron → `sl_usage.json` (**empty on Uganda**, docs/39) or hybrid's own hourly collector (`cron/starlink_usage.php`) | Data Report / hybrid | kit number, service line | portal `KitUsage` joins on `equipment_assignments`; the API `app_usage` is **hard-coded unavailable** | no usage shown; "0" never shown by design |
| Customer login | hybrid (`client_search_index`, OTP, `customer_sessions`) | hybrid | phone (last 9 digits) / e-mail → uCRM client id (`sub`) + `accounts` | uCRM contacts are the only source of the identifiers | a contact edit in uCRM reaches the index by webhook/delta |
| Suspension / block | uCRM service status → webhook → `StarlinkBlockService` / `StarlinkBlockBridge` → Data Report `dr_wifi_test_block` | uCRM (state), Data Report (execution) | client id → kits → router id | HTTP with `X-DishNet-Internal-Auth` | silent (caught) — SAFETY.md |
| Support | none in the plugin — static contacts (WhatsApp, phone, e-mail from the tenant profile) | — | — | — | tickets are uCRM-only and not shown |

## B. Identity map — Family Shoppers, CRM #1

| System | Presence | Evidence |
|---|---|---|
| uCRM | client #1, "Residential (up to 400 Mbps)", UGX 329,000/month, e-mail and phone on the record | operator's screenshot (REPOSITORY-external) |
| Hybrid | in `client_search_index`: the 05:40 e-mail sign-in matched exactly one account, eligible, and resolved to it | docs/35 §2 |
| Starlink Finance | **not among the three kits/customers it shows** (CRM #7, #47, #69) | operator's screenshot; `--identity 1` confirms (PENDING) |
| Data Report | **not among its three clients** (the same three) | operator's screenshot; `--identity 1` confirms (PENDING) |
| Kit / Starlink account / usage | expected **none** in every system (a non-Starlink service) | PENDING |

So the controlled customer exists in exactly two of the four systems, by design of the data rather than by
a defect: no kit, no Starlink account, no usage. The field-by-field comparison (name, e-mail, phone, service,
plan, price, invoices, payment status, state) between uCRM live and the hybrid's caches is what
`--identity 1` prints, with a **Same?** column computed on structured values and the source of truth per field.

## C. Synchronisation matrix (REPOSITORY; PENDING marks what the server run adds)

| Data | uCRM | Hybrid | Data Report | Finance | Truth | Movement |
|---|---|---|---|---|---|---|
| Customer id | native | copy (index, clients cache) | derived from kit/line records only | own cache | uCRM | webhook + 60 s delta → hybrid; Finance cron PENDING |
| Name / e-mail / phone | native | copy — **phone and e-mail are the sign-in keys** | name typed into router map (`customer`) | typed into `sl_kits.json` | uCRM | as above; Finance/Data Report: manual |
| Service / plan / price | native | cache | live GET with its own app key | own cache | uCRM | staff sync actions, cron, webhook |
| Invoice / payment status | native | cache + on-demand refresh; `app_account` live | — | invoice export (its own) | uCRM | webhook → surgical refresh |
| Kit / serial | attribute "Kit Number" on the service (typed) | `equipment_assignments` (validated) | `dr_kit_registry` (generated from Finance) | `sl_kits.json` (typed) | **two registers, none authoritative for both readers** | `KitAttributeIntake` reads the attribute → assignment; Data Report regenerates its registry from Finance |
| Starlink account | — | assignment column | `dr_accounts.json` | `sl_accounts.json` | Data Report (session) | its crons |
| Usage | — | own collector (hourly) | `sl_usage.json` (empty on Uganda) | `sl_usage.json` (Finance copy, docs/38) | Starlink API | crons; the API endpoint never answers |
| Active / suspended | native (service status) | webhook-driven block | pause state (`wifi_test_block_state`, absent on Uganda per docs/39) | — | uCRM (state) / Data Report (execution) | webhook → bridge → `dr_wifi_test_block` |

## D. Current failures (confirmed unless marked PENDING)

**Critical** — none that exposes another customer's data on this host (docs/36 §0: the hand-off forgery is
not possible here).

**High**
1. **The installed Data Report is the South Sudan build** (docs/36 §I): hand-off dead (404 for every
   customer), "Back to Portal" link dead, auto-block admin alerts posted to a non-existent path.
2. **Data Report auto-sync is blocked** — its own banner: *"main.lock is stuck (previous dispatch crashed)"*,
   "2 Needs Sync · Active · no data". Which process crashed, when, and what the two records are: **PENDING**
   (`--siblings` prints the lock's age and content type, the code around it, the log's last lock/crash
   lines, the Needs-Sync rule and the kits without usage rows, and the unstick handler's code so its safety
   can be judged **before** anyone clicks it). Not unstuck by this audit.
3. **Two kit registers.** Finance's typed `sl_kits.json` (three kits) and the hybrid's validated
   `equipment_assignments` are both read as authority by different code; nothing reconciles them; the
   blocking path and the customer's Starlink screens can disagree.
4. **A third customer master.** Finance keeps `crm_clients_cache.json` / `crm_services_cache.json` (docs/39
   §3) — its own copy of uCRM, refreshed by its own means (PENDING).
5. **Both siblings keep their live data in the directory uCRM deletes on upgrade** (docs/40 Q2): the kit
   register, the router map, the Starlink session cookies. Not new, not fixed.

**Medium**
6. `app_usage` is hard-coded `unavailable: true` (a TODO); the portal's usage view has a source only through
   the hybrid's own collector, because Data Report's `sl_usage.json` is empty on Uganda (docs/39).
7. `app_plan` and `app_invoices` fall back to **`USD`** when a uCRM record carries no `currencyCode`
   (`api_customer_app.php`: `?? 'USD'`) — a South Sudan-era default; the tenant's currency should be the
   fallback. Whether any live record hits the fallback: PENDING (the walk prints the currency shown).
8. Sibling access control, pre-existing, separate track (docs/36 §H.2–H.3): "View as Client" trusts the URL
   for any session holder; `dr_wifi_*` handlers appear reachable without a session.
9. Data Report's `dr_accounts.json` holds Starlink session material in the upgrade-deleted directory (docs/38).
10. `sl_account_cycles.json` / `accounts.json` read by the hybrid do not exist on Uganda (docs/39).

**Cosmetic**
11. The Data Report heads every page *"DISHNET AFRICA · JUBA, SOUTH SUDAN"* on the Uganda install.
12. Existing customers meet the consent step once on the web before the portal renders (by design; the
    audit does not accept it on their behalf).

## E. Customer-experience scorecard — expected from the code for a customer WITHOUT a Starlink kit; the live values come from `--login-phone` and `--login-email` (PENDING)

| Screen | Expected | Why |
|---|---|---|
| Login (phone / e-mail) | WORKING | both proven in production (docs/35 §2; 26 Sep 02:59 phone) |
| Account | WORKING | `app_me` + `app_account` (uCRM live refresh) |
| Services / plan | WORKING if the service is in `ucrm_services_cache`, else PARTIAL (404 "No plan found.") | cache-dependent |
| Starlink / sites / WiFi | N/A (no kit) — the screens exist and answer "needs a kit/router" | `equipment_assignments` empty for #1 |
| Usage | NOT IMPLEMENTED (API) / no source (portal) | hard-coded unavailable; no kit |
| Invoices | WORKING | cache + refresh |
| Payments / receipts | WORKING (statement) / PARTIAL (receipt list needs uCRM live) | `CustomerAccountService`, live payments |
| Documents (invoice PDF) | WORKING if uCRM serves the PDF (live), else 502/503 | `app_invoice_pdf_download` |
| Support | WORKING as static contacts; **no tickets** | the view is contact tiles from the tenant profile |
| Logout | WORKING (revocation proven) | docs/35 §2 |
| Branding | UGX · DishNet Africa Limited · Kampala · +256 705 993 348; no SSP/Juba | `profiles/uganda.json`; the walk counts the words on the rendered page |

## F. Architecture recommendations — the top ten

1. **One customer identity = the uCRM client id, everywhere.** Finance and Data Report must reference it,
   never a typed name; retire Finance's own client cache as a *master* (keep it as a cache with a refresh time).
2. **One kit register.** Decide the owner (the hybrid's `equipment_assignments`, DB-enforced, is the stronger
   candidate) and make Finance's `sl_kits.json` a projection of it or vice-versa — never two authorities.
3. **Move both siblings' live data out of the upgrade-deleted directory** (persistent `.<plugin>-data`).
4. **Data Report discovers the hybrid** (docs/36 §J.5): a discovery record under `_dishnet_shared/`, no
   hard-coded plugin names; the hand-off key beside it.
5. **Repair the tick**: whatever the `--siblings` read shows about `main.lock`, give the dispatcher a stale-lock
   rule with an alert rather than a silent stop, and keep the crash reason in its log.
6. **Usage has one path**: the hybrid's own collector or Data Report's, not both; wire `app_usage` to it and
   drop the hard-coded unavailable.
7. **Tenant currency as the only fallback** (`USD` defaults removed).
8. **Real-time vs cached vs eventual, stated in code:** live = balance/payments on account open, WiFi
   control; cached with a visible refresh time = plan, invoices, kits; eventual = usage, Starlink state.
9. **Sibling access control** (docs/36 §H): gate "View as Client" on an admin session; gate `dr_wifi_*` on the
   internal-auth header or a real admin session without breaking the block bridge.
10. **Uganda branding in the Data Report** from the same tenant record the hybrid uses.

## G. Phases

- **Now (read-only):** run the four modes of `scripts/journey-audit.sh` (§H); record the results here.
- **Phase 1:** data-report redesign (docs/36 §J) including discovery, the hand-off key, Back-to-Portal, alert
  URL, branding; move sibling data to persistent directories; the stale-lock rule.
- **Phase 2:** one kit register; `app_usage` on the real source; tenant-currency fallback.
- **Phase 3:** sibling access-control hardening (View as Client, `dr_wifi_*`); Finance client cache demoted.

## H. Safety, and the read-only commands (what the operator runs; nothing is changed)

```
cd /opt/dishnet && git pull origin claude/study-this-jhe2eg && mkdir -p /root/dnb-verify
bash scripts/journey-audit.sh --siblings                        2>&1 | tee /root/dnb-verify/journey-siblings-$(date -u +%Y%m%dT%H%M%SZ).log
bash scripts/journey-audit.sh --identity 1                      2>&1 | tee /root/dnb-verify/journey-identity-$(date -u +%Y%m%dT%H%M%SZ).log
bash scripts/journey-audit.sh --login-phone +211927797217       2>&1 | tee /root/dnb-verify/journey-login-phone-$(date -u +%Y%m%dT%H%M%SZ).log
bash scripts/journey-audit.sh --login-email bhavin.madlani@outlook.com 2>&1 | tee /root/dnb-verify/journey-login-email-$(date -u +%Y%m%dT%H%M%SZ).log
bash scripts/journey-audit.sh --compare                         2>&1 | tee /root/dnb-verify/journey-compare-$(date -u +%Y%m%dT%H%M%SZ).log
```

- `--siblings` and `--identity` read files and a copy of the store; the identity mode also issues **GET**
  requests to uCRM through the plugin's own client (client, services, invoices, payments of #1).
- The two logins send one WhatsApp message and one e-mail to the operator's own contacts; the code is typed
  on the server and never printed; the session is logged out; the code and cookie are searched for in the
  container log afterwards; consent is **not** accepted; the Data Report link is opened once with the
  hand-off token to record its answer.
- Rehearsed end to end in a sandbox (fake Evolution, SMTP sink, fake uCRM, fake siblings with a stuck lock
  and a crash log): **48 of 48 checks, two consecutive runs**, including the leak checks (planted keys,
  cookies, kit numbers, the address, the phone number and the OTP code never appear) and a control that a
  differing fingerprint is reported as DIFFERENT.
- Never: sync, unstick, delete, rebuild, deploy, modify a customer, change any plugin.

*Results are appended below as §I when the log files arrive.*
