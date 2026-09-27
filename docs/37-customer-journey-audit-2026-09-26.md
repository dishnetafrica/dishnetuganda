# 37 — Customer journey and three-plugin synchronisation audit (26 September 2026)

**Status: READ-ONLY. No code change, no migration, no sync, no unstick, no customer modification, no
deployment, no change to Starlink Finance, Data Report or Hybrid.** Requested by the operator before the
data-report redesign (docs/36 §J) is approved. Controlled customer: **Family Shoppers, CRM client #1**
(the operator's own record). Every personal identifier in this document and in the command's output is
masked; secrets and tokens are never printed.

Three kinds of statement appear below and are labelled: **REPOSITORY** (read in `dishnet-hybrid-sudan`
and in the earlier server-measured audits docs/38–40, docs/35–36), **MEASURED** (read on the server by one
of the read-only runs of `scripts/journey-audit.sh`, recorded in §I) and **PENDING** (needs a run that has
not arrived yet, §H). Nothing PENDING is asserted.

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
| Starlink account / session | Data Report `dr_accounts.json` (+ `sl_sync_settings.json` cookie) | Data Report | account number | its crons; Finance `sl_accounts.json` (billing days) | sync stops when the account sessions die — **MEASURED: all five are dead or cookie-less (§I.1.1)**; the "stuck lock" banner is a false alarm |
| Usage | Starlink API via Data Report cron → `sl_usage.json` (14 historical rows for two kits, nothing since the sessions died — §I.1.1) or hybrid's own hourly collector (`cron/starlink_usage.php`; its one Uganda session, imported 13 Sep, is expired — §I.6) | Data Report / hybrid | kit number, service line | portal `KitUsage` joins on `equipment_assignments`; the API `app_usage` is **hard-coded unavailable** | no usage shown; "0" never shown by design |
| Customer login | hybrid (`client_search_index`, OTP, `customer_sessions`) | hybrid | phone (last 9 digits) / e-mail → uCRM client id (`sub`) + `accounts` | uCRM contacts are the only source of the identifiers | a contact edit in uCRM reaches the index by webhook/delta |
| Suspension / block | uCRM service status → webhook → `StarlinkBlockService` / `StarlinkBlockBridge` → Data Report `dr_wifi_test_block` | uCRM (state), Data Report (execution) | client id → kits → router id | HTTP with `X-DishNet-Internal-Auth` | silent (caught) — SAFETY.md |
| Support | none in the plugin — static contacts, **hard-coded to South Sudan in `portal.php` (§J.1), not read from the tenant profile** | — | — | — | tickets are uCRM-only and not shown; a Uganda customer is handed a South Sudan number |

## B. Identity map — Family Shoppers, CRM #1

| System | Presence | Evidence |
|---|---|---|
| uCRM | client #1 (a company), 1 contact with e-mail and phone; 1 service, id 6, plan 3 "Residential (up to 400 Mbps)", UGX 329,000, status active since 20 Sep 2026, **service attribute `starlinkDetails` set** (value withheld); 1 unpaid invoice of 329,000, 0 payments, balance −329,000; **`isActive false`** on the client while its service is active (§I.4) | **MEASURED** live through the plugin's own uCRM client (§I.4) |
| Hybrid | in `client_search_index`: the 05:40 e-mail sign-in matched exactly one account, eligible, and resolved to it. **MEASURED 08:41: the phone route and the e-mail route both resolve to CRM #1 with one account and an identical fingerprint (§I.3)** | docs/35 §2; §I.3 |
| Starlink Finance | **not among the three kits/customers it shows** (CRM #7, #47, #69) | operator's screenshot; **MEASURED** by `--siblings`: `sl_kits.json` holds exactly #7, #47, #69 (§I.1.1) |
| Data Report | **not among its clients** (the same three, plus one kit with no client at all) | operator's screenshot; **MEASURED** by `--siblings`: `dr_kit_registry.json` = #7, #47, #69 and one unassigned kit (§I.1.1) |
| Kit / Starlink account / usage | **none in either sibling register (MEASURED)**; the hybrid's own `equipment_assignments` for #1: PENDING (`--identity 1`) | §I.1.1 |

**MEASURED (§I.4):** the controlled customer exists in three of the four systems — uCRM, the hybrid, and
Finance's own copy of the uCRM client list — and every shared field agrees between them (id, name, e-mail,
phone, service, plan, price, invoice, payment status, state). Data Report holds nothing for #1 because it keys
on kits, and **no kit is registered for #1 anywhere: not in the hybrid's `equipment_assignments` (its
`stock_units` holds 2 units, both other customers', none carrying #1's serial — §I.6), not in Finance's `sl_kits.json`, not in Data Report's registry —
although uCRM's service carries a `starlinkDetails` attribute and the portal classifies the service as
Starlink.** That is a defect of the kit chain, not a property of the customer.

## C. Synchronisation matrix (REPOSITORY; PENDING marks what the server run adds)

| Data | uCRM | Hybrid | Data Report | Finance | Truth | Movement |
|---|---|---|---|---|---|---|
| Customer id | native | copy (index, clients cache) | derived from kit/line records only | own cache | uCRM | webhook + 60 s delta → hybrid; **Finance refreshes its own 91-client copy from `public.php` at render time (MEASURED, §I.1.2)** |
| Name / e-mail / phone | native | copy — **phone and e-mail are the sign-in keys** | name typed into router map (`customer`) | typed into `sl_kits.json` | uCRM | as above; Finance/Data Report: manual |
| Service / plan / price | native | cache | live GET with its own app key | own cache | uCRM | staff sync actions, cron, webhook |
| Invoice / payment status | native | cache + on-demand refresh; `app_account` live | — | invoice export (its own) | uCRM | webhook → surgical refresh |
| Kit / serial | attribute "Kit Number" on the service (typed) | `equipment_assignments` (validated) | `dr_kit_registry` (generated from Finance) | `sl_kits.json` (typed) | **two registers, none authoritative for both readers** | `KitAttributeIntake` reads the attribute → assignment; Data Report regenerates its registry from Finance. **MEASURED: no kit in either register carries a service line or a Starlink account (§I.1.2)** |
| Starlink account | — | assignment column | `dr_accounts.json` | `sl_accounts.json` | Data Report (session) | its crons — **MEASURED: 4 of 5 accounts dead, 1 without a cookie, nothing fetched in the log window (§I.1.1)** |
| Usage | — | own collector (hourly) | `sl_usage.json` (14 historical rows, two kits) | `sl_usage.json` (Finance copy, docs/38) | Starlink API | crons; the API endpoint never answers |
| Active / suspended | native (service status) | webhook-driven block | pause state (`wifi_test_block_state`, absent on Uganda per docs/39) | — | uCRM (state) / Data Report (execution) | webhook → bridge → `dr_wifi_test_block` |

## D. Current failures (MEASURED unless marked otherwise)

**Critical** — none that exposes another customer's data on this host (docs/36 §0: the hand-off forgery is
not possible here; §I.5: the invoice PDF endpoint checks the session, the account and the invoice's owner, and
the link answers 401 after logout).

**High**
1. **The installed Data Report is the South Sudan build** (docs/36 §I): hand-off dead (404 for every customer —
   confirmed live in both walks, §I.5), "Back to Portal" link dead, auto-block admin alerts posted to a
   non-existent path.
2. **Data Report's Starlink synchronisation has nothing to log in with (§I.1.1).** Of its five Starlink
   accounts four are marked dead and the fifth has no session cookie; every fetch in the log window was
   skipped (`0 fetched` on twelve consecutive order runs; `skipped_dead_acct` 21 → 24 on the daily run). This —
   not a lock — is why two Active kits show "no data", and why usage can flow to nobody. Re-authenticating the
   accounts is an operator act inside Data Report; not done by this audit.
3. **The "main.lock is stuck (previous dispatch crashed). Auto-sync is blocked." banner is a FALSE ALARM
   (§I.1.1).** The dispatcher ran at every tick in the log window (5 starts, 5 finishes, 0 skips, 0 errors).
   The banner and the log's "hard-killed" line come from a 30-minute staleness rule on a lock file whose
   timestamp advances only once an hour, on a dispatcher that ticks every 30 minutes; the banner is therefore
   on for about half of every hour while sync runs normally. Not unstuck by this audit — the buttons would not
   help, and one of them forces a sync.
4. **Three kit registers, and the source feeds none of them on this install.** uCRM's service for #1 carries a
   `starlinkDetails` attribute (§I.4) — the key the hybrid's own `CrmKitAttribute` prefers. Yet the hybrid's
   `equipment_assignments` holds no kit for #1 because no stock unit carries its serial (**2 units are in stock, both other customers' — §I.6**), and
   `KitAttributeIntake` refuses to assign a serial that no stock unit carries ("assigning it would invent"
   stock); Finance's typed `sl_kits.json` has no kit for #1 either; Data Report's registry is generated from
   Finance. So a Starlink customer has a kit in uCRM and nowhere else, and the portal's Starlink screens are
   empty for them. Across the estate the two registers that do hold kits (§I.1.2) carry **no service line, no
   router and no Starlink account on any kit**, and nothing reconciles them.
5. **A third customer master.** Finance keeps `crm_clients_cache.json` — 91 clients, its own copy of every uCRM
   client, refreshed by `public.php` at render time (§I.1.2); #1 is in it (§I.4).
6. **Both siblings keep their live data in the directory uCRM deletes on upgrade** (docs/40 Q2). MEASURED:
   `.dishnet-data-report-data` and `.dishnet-starlink-finance-data` are both absent; the kit register, the
   router map, the Starlink session material and every cache live in `data/` (§I.1.2–3).
7. **The post-login portal ignores the tenant profile (§J.1; MEASURED live, §I.5).** `portal.php` and
   `portal_data.php` never read `TenantProfile`: the Support tab (the "Help" screen), all sixteen WhatsApp
   buttons, the displayed phone, the e-mail, the location default, the service-status view and the legal pages
   carry South Sudan values on the Uganda install, while the sign-in page is tenant-aware. **Live: the Support
   tab renders 23 South Sudan literals and 0 Uganda contacts; every portal page embeds `+211` 6 times and shows
   `Juba` once; the public Terms page names South Sudan 3 times, Juba 3 times** — identical to the sandbox.

**Medium**
8. `app_usage` is hard-coded `unavailable: true` (a TODO) — live: NOT IMPLEMENTED (§I.5); the portal's usage
   view has a source only through the hybrid's own collector, because Data Report's `sl_usage.json` holds 14
   historical rows for two kits and cannot grow while every account is dead (§I.1.1).
9. `app_plan`, `app_invoices` and `portal_data.php:1031` fall back to **`USD`** when a uCRM record carries no
   `currencyCode` (`?? 'USD'`) — a South Sudan-era default. Live for #1 the plan shows **UGX**, so the fallback
   was not hit; it remains wrong for the next record without a currency.
10. Sibling access control, pre-existing, separate track (docs/36 §H.2–H.3): "View as Client" trusts the URL
    for any session holder; `dr_wifi_*` handlers appear reachable without a session; the Sync Status page
    offers one-click destructive recovery (`dr_cron_nuclear_reset` forces a sync, §I.1.1).
11. Data Report's `dr_accounts.json` holds Starlink session material in the upgrade-deleted directory
    (docs/38; present, 5 accounts, never opened beyond the count).
12. `sl_account_cycles.json` / `accounts.json` read by the hybrid do not exist on Uganda (docs/39).
13. Finance's `config.json` is empty (2 bytes) and `crm_invoice_export.json` is 141 days old (§I.1.2); Finance
    has **one PATCH helper towards uCRM** (`public.php:101–106`) whose callers were not captured — to be read
    before Finance is called read-only towards uCRM.
14. **The customer pages answer on whatever host and port a request arrives on, and on `:8443` UISP presents a
    self-signed certificate (§J.2; MEASURED §I.2).** 443 = Let's Encrypt for `crm.dishnetuganda.com`, verify 0;
    8443 = self-signed `CN=localhost`, verify 18; the bare `/crm` still answers 301 to `:8443/crm/`; uCRM's own
    address is `…:8443`. The plugin's invoice-PDF link is origin-relative and carries no credential, so it
    inherits the origin the customer is on. The plugin's generated links and the website are on 443. **The
    invoice PDF document itself carries 2 links to `crm.dishnetuganda.com:8443` (MEASURED 09:44, §I.6)** —
    uCRM's own address, written by its renderer — the likeliest door for the operator's own warning; only C-2
    in docs/38 changes what uCRM writes there.
15. **Consent is keyed by the sign-in identifier, not by the customer (§I.5).** The phone route had accepted
    the terms, so every portal page rendered; the e-mail route — the same customer, CRM #1 — was sent to the
    consent step on every page. `app_tos_consent` stores `crm_client_id` but `hasCurrentConsent()` looks up the
    identifier only.
16. **uCRM reports `isActive false` for client #1** while its one service is active (status 1) and invoiced
    (§I.4). The hybrid's index stores `is_active`, but sign-in eligibility consults only archived / lead /
    no-service (`ca_login_eligibility`), so the flag has no effect on the portal. What uCRM means by it for
    this client is for the operator to read off the CRM screen; whether it should gate anything is a decision.

**Cosmetic**
17. The Data Report heads every page *"DISHNET AFRICA · JUBA, SOUTH SUDAN"* on the Uganda install; its
    generated registry names generator "2.8.73" on the 2.8.80 build.
18. Existing customers meet the consent step once on the web before the portal renders (by design; the audit
    does not accept it on their behalf). The KYC tables (`customer_identities`, `kyc_applications`) do not
    exist in the Uganda store: the onboarding path has never been used there, and the account screen tolerates
    their absence.

## E. Customer-experience scorecard — MEASURED on 26 September 08:40–08:41 UTC (both sign-in routes, CRM #1)

| Screen | Result | Evidence (§I.5) |
|---|---|---|
| Login by WhatsApp/phone | **WORKING** | 1 account matched, eligible, "Code sent via WhatsApp", verified, HttpOnly · SameSite=Lax · Secure cookie, no token in the body |
| Login by e-mail | **WORKING** | 1 account matched, "Code sent via Email", verified, same cookie |
| Account | **WORKING** | id 1, service type `starlink`, 1 service, 1 account, not paused, unpaid 329,000; uCRM live and the caches agree (§I.4) |
| Services / plan | **WORKING** | "Residential (up to 400 Mbps)", UGX 329,000, active — UGX shown, the USD fallback not hit |
| Invoices / invoice detail | **WORKING** | 1 pending invoice, detail with 1 item |
| Invoice PDF | **WORKING on the standard port** | 200, `application/pdf`, 5,976 bytes, `private, no-store`, no redirect; origin `https://crm.dishnetuganda.com` with no port; **401 after logout**. A browser on `:8443` warns (§I.2, §J.2); **the PDF document itself contains 2 links to `crm.dishnetuganda.com:8443` (§I.6, §J.2)** |
| Receipts / payments | **WORKING** | 0 payments (none made), live from uCRM |
| Equipment | **PARTIAL** | 0 items: no kit bound to #1 in the hybrid's register; its 2 units are other customers' (§D.4, §I.6) |
| Starlink diagnostics / sites | **N/A** | 400 "kit or router_id required": no kit bound |
| WiFi | **PARTIAL** | 200 but no router resolved, empty SSID, a diagnostic payload explains |
| Usage | **NOT IMPLEMENTED** | `unavailable: true` by design (a TODO in the API) |
| Data Report hand-off | **BROKEN** | token minted; the sibling answers 404 "Report not found" (docs/36) |
| Support ("Help") | **WRONG TENANT** | 23 South Sudan literals, 0 Uganda contacts on the rendered tab (§J.1) |
| Legal / consent | **WRONG TENANT**, and asked per identifier | Terms: South Sudan ×3, Juba ×3, `+211` ×2; the e-mail route was sent to consent although the phone route had accepted (§D.15) |
| Branding | sign-in page **Uganda** (DishNet Africa, Kampala, Uganda, 256 705 993 348; no South Sudan); portal pages **`+211` ×6, `Juba` ×1** | `login_web.php` reads the profile; `portal.php` does not |
| Logout | **WORKING** | 200; the cookie and the PDF link both 401 afterwards |
| Log hygiene | **WORKING** | neither the code nor the cookie appears in the container log |

Phone walk: 29 ok, 3 failed (L5, L9, L10 — the tenant findings), 6 notes. E-mail walk: 22 ok, 1 failed (L10),
14 notes (the consent redirects). The two walks produced the same identity fingerprint (§I.3).

## F. Architecture recommendations — the top twelve

1. **One customer identity = the uCRM client id, everywhere.** Finance and Data Report must reference it,
   never a typed name; retire Finance's own client cache as a *master* (keep it as a cache with a refresh time).
2. **One kit register.** Decide the owner (the hybrid's `equipment_assignments`, DB-enforced, is the stronger
   candidate) and make Finance's `sl_kits.json` a projection of it or vice-versa — never two authorities.
3. **Move both siblings' live data out of the upgrade-deleted directory** (persistent `.<plugin>-data`).
4. **Data Report discovers the hybrid** (docs/36 §J.5): a discovery record under `_dishnet_shared/`, no
   hard-coded plugin names; the hand-off key beside it.
5. **Repair the alert, not the tick** (§I.1.1): the dispatcher runs. Make the UI's staleness threshold at
   least twice the tick period, make the end-of-run timestamp refresh land (or unlink the lock on normal
   completion), and reword "crashed" to what was measured. Then alert on what actually fails — dead Starlink
   sessions, four of five today — which is only a counter in a cron log.
6. **Usage has one path**: the hybrid's own collector or Data Report's, not both; wire `app_usage` to it and
   drop the hard-coded unavailable.
7. **Tenant currency as the only fallback** (`USD` defaults removed).
8. **Real-time vs cached vs eventual, stated in code:** live = balance/payments on account open, WiFi
   control; cached with a visible refresh time = plan, invoices, kits; eventual = usage, Starlink state.
9. **Sibling access control** (docs/36 §H): gate "View as Client" on an admin session; gate `dr_wifi_*` on the
   internal-auth header or a real admin session without breaking the block bridge.
10. **Uganda branding in the Data Report** from the same tenant record the hybrid uses.
11. **The tenant profile through the whole customer portal and the legal pages** (§J.1): one
    `TenantProfile` load in `portal_data.php`, every contact, location, currency and legal footer from it;
    the Terms/Privacy wording parameterised by entity, jurisdiction and regulator — after the business
    decides Uganda's courts and clauses, which the repository does not hold.
12. **One public origin for customers** (§J.2): keep customers off `:8443` (uCRM's configured address or a
    real certificate on 8443, the website's 443 sign-in link) and a canonical-host redirect for the customer
    pages when `crm_public_url` is set, so a stale bookmark or a uCRM e-mail link lands on the trusted
    address. The PDF endpoint itself stays as it is.

## G. Phases

- **Now (read-only):** run the four modes of `scripts/journey-audit.sh` (§H); record the results here.
- **Phase 1:** data-report redesign (docs/36 §J) including discovery, the hand-off key, Back-to-Portal, alert
  URL, branding; move sibling data to persistent directories; the false stale-lock alert replaced by a real
  one for dead Starlink sessions; **the portal tenant pass and the canonical-host redirect (§J), with the
  legal wording after the business decision on Uganda's courts.**
- **Phase 2:** one kit register; `app_usage` on the real source; tenant-currency fallback.
- **Phase 3:** sibling access-control hardening (View as Client, `dr_wifi_*`); Finance client cache demoted.

## H. Safety, and the read-only commands (what the operator runs; nothing is changed)

```
cd /opt/dishnet && git pull origin claude/study-this-jhe2eg && mkdir -p /root/dnb-verify
bash scripts/journey-audit.sh --siblings                        2>&1 | tee /root/dnb-verify/journey-siblings-$(date -u +%Y%m%dT%H%M%SZ).log
bash scripts/journey-audit.sh --identity 1                      2>&1 | tee /root/dnb-verify/journey-identity-$(date -u +%Y%m%dT%H%M%SZ).log
bash scripts/journey-audit.sh --login-phone '<the customer phone, international form>'       2>&1 | tee /root/dnb-verify/journey-login-phone-$(date -u +%Y%m%dT%H%M%SZ).log
bash scripts/journey-audit.sh --login-email '<the customer e-mail>' 2>&1 | tee /root/dnb-verify/journey-login-email-$(date -u +%Y%m%dT%H%M%SZ).log
bash scripts/journey-audit.sh --compare                         2>&1 | tee /root/dnb-verify/journey-compare-$(date -u +%Y%m%dT%H%M%SZ).log
bash scripts/journey-audit.sh --urls                            2>&1 | tee /root/dnb-verify/journey-urls-$(date -u +%Y%m%dT%H%M%SZ).log
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
- `--urls` (added 26 Sep for §J.2) runs the plugin's own link-builder report (`tools/crm_url_check.php`,
  report mode), reads the customer manifest, inspects the certificate on 443 and on 8443 with `openssl`,
  records where `/crm`, `/crm/`, `http://` and `:8080` send a browser, and whether the live website links
  the portal sign-in. HEAD/GET requests and TLS handshakes to the server's own public name; no login.
- The login walks (extended 26 Sep for §J) also fetch the first invoice's PDF with the cookie exactly as
  the portal does (the answer's shape only, never the bytes), fetch the same link after logout, count the
  contact literals on the Support tab and on the public Terms page.
- Never: sync, unstick, delete, rebuild, deploy, modify a customer, change any plugin.

## I. Results from the server (MEASURED; appended as the log files arrive)

### I.1 `--siblings` — run about 06:25 UTC, 26 September

The operator pasted the terminal. The paste begins inside Data Report's log section, so the command's
header, Data Report's manifest (with its `executionPeriod`), its PHP inventory and its data-directory
metadata are only in the log file `/root/dnb-verify/journey-siblings-*.log`, which has not been read yet.
Everything below is from the part that arrived.

**One defect in the command itself, fixed in this commit:** the "Starlink API hosts" line printed PHP
warnings instead of the hosts (`"$k×$v"` — PHP reads `$k×` as one variable name). Nothing else in the output
is affected. The hosts are known from the 19 September measurement (docs/38: Data Report is the
`api.starlink.com` client); no re-run is needed for this audit.

#### I.1.1 Part 5 — the "stuck main.lock" / "Auto-sync is blocked" alert: diagnosis

**What the lock guards.** `data/main.lock` is the single-instance guard of **`main.php`**, Data Report's own
cron *dispatcher*: on each uCRM tick it decides which of its crons to `include` — `cron.php` (the Starlink
data sync, 60-min slot with a 120-min floor), `cron_session.php` (cookie keepalive), `cron_backup.php` (8 h),
`cron_orders.php` (hourly, smart-daily per account), `cron_invoice_details.php` (24 h) and the auto-block
sweep. It does **not** guard the sync itself; that is `cron.lock`, which has its own 25-minute stale rule
(`cron.php:87–93`). `main.php` never deletes the lock on a normal exit — it releases the `flock` and
touches the file (`main.php:105–107`) — so the file's existence is normal.

**What the dispatcher log shows** (`main_dispatch.log`, the last twelve lines):

| tick (UTC) | "Stale main.lock … hard-killed. Auto-clearing." first? | finished | duration |
|---|---|---|---|
| 04:00:11 | (before the twelve lines) | 04:00:12 | 1 s |
| 04:30:03 | no | 04:30:04 | 0.3 s |
| 05:00:06 | **yes** | 05:00:06 | 0.4 s |
| 05:30:05 | no | 05:30:34 | 28.8 s |
| 06:00:06 | **yes** | 06:00:06 | 0.3 s |

Five starts, five finishes, zero *"SKIP — previous main.php still running"*, zero error or fatal lines.
**No process crashed in the window.** The "hard-killed" line is printed by `main.php:91–93` whenever the
lock's modification time is more than 1800 s old — a threshold v2.8.69 lowered from 60 to 30 minutes "to
match the UI" (`main.php:84–89`). The dispatcher ticks every 30 minutes (measured from the spacing above).
So the lock is at the threshold every other tick by arithmetic, and the line fires with nothing having died.

**The end-of-run timestamp refresh does not land.** The 06:00:06 stale line came 29 min 32 s after the
previous run finished (05:30:34). Had `main.php:107`'s `touch` taken effect, the lock would have been
28 seconds too young to be stale. It was stale, so its modification time was still that of the 05:00:06
re-creation — 60 minutes old. Consequence: the timestamp advances only when a :00 tick auto-clears and
recreates the file. Why the touch does not land (a shutdown function that never fires under uCRM's runner,
or a permission) cannot be read off the log; it is a Data Report question.

**The banner.** `public.php:109–112` applies the same 30-minute rule to the same file and prints
*"main.lock is stuck (previous dispatch crashed). Auto-sync is blocked."* With the timestamp refreshed only
at :00, the banner is ON from about :30 to :00 of every hour and OFF from :00 to :30 — about half the time —
while the dispatcher runs normally at both ticks. The operator's screenshot fell in an ON window; the audit
ran in an OFF window (lock 25 minutes old, 0 bytes). **Both claims of the alert are false: nothing crashed,
and auto-sync is not blocked.**

**What actually stops the data.** Every Starlink fetch in the window was skipped for lack of a working
account session:

- `orders_cron.log`, twelve consecutive runs ending 05:30: *"5 total · 0 fetched · 0 cadence-skip ·
  0 errors · 1 no-cookie · 4 dead"*, identical every time;
- the daily run (its log name is above the cut; by cadence and wording the invoice-detail cron):
  `fetched=0` every day since the 21st, `skipped_dead_acct=21` through the 24th and `24` on the 25th,
  `cookie_401=1` on the 25th.

Of the five Starlink accounts in `dr_accounts.json`, four are marked dead and the fifth has no cookie.
**Nothing has been fetched from Starlink in the whole log window.** Auto-sync is not blocked by a lock; it
has nothing to log in with.

**The "2 Needs Sync · Active · no data" records.** The generated `dr_kit_registry.json` (05:30:27) lists
four kits:

| kit (masked) | CRM client | rows in `sl_usage.json` |
|---|---|---|
| KIT…JJ4 | **none** | 7 |
| KIT…5DH | #69 | 7 |
| KIT…BX6 | #7 | **0** |
| KIT…KFR | #47 | **0** |

`sl_usage.json` holds 14 rows for exactly two kits. The two Active kits with no usage rows — the kits of CRM
#7 and #47 — are the two "Needs Sync · Active · no data" records: the fleet page counts two and these are the
only two candidates. (The template's exact rule was not matched by the command's pattern search; confirm it
in `templates/fleet_home.php` when that file is next read.) The 14 rows are historical: with every account
dead, no new row can be written for any kit.

**Is "unstick" safe?** It is unnecessary, and the buttons do not do what the banner implies:

- **"Clear cron.lock only"** (`dr_cron_unstick`, `public.php:4391–4405`) unlinks `data/cron.lock` — a
  different file from the one the banner names, and one with its own 25-minute self-heal. Harmless, useless.
- **"Nuclear reset"** (`dr_cron_nuclear_reset`, `public.php:4411–`) unlinks `cron.lock`, `main.lock` and the
  legacy pattern file **and forces a synchronous sync**. That mutates the sibling's state, would run the
  Starlink sync against dead sessions, and the banner would be back within 30 minutes of the next :00 tick.

**Recommendation: press nothing.** The remedy is in Data Report's code, for its owner, outside this task's
scope: a UI staleness threshold of at least twice the tick period; an end-of-run refresh that lands (write
to the file, or unlink the lock on normal completion); a log line that states what it measured (the
timestamp's age) rather than what it inferred ("hard-killed"). The operational action that would restore
Starlink data is re-authenticating the five accounts in Data Report's Sessions tab — an operator act inside
the sibling, only on the operator's go-ahead.

**Family Shoppers, CRM #1: no kit in either register.** `sl_kits.json` holds CRM #7, #47 and #69;
`dr_kit_registry.json` holds those three and one kit with no client. §B's expectation is confirmed.

#### I.1.2 Part 6 — Starlink Finance's data model

- **File-backed, everything in the upgrade-deleted `<plugin>/data`**; `.dishnet-starlink-finance-data` is
  absent. Live (modified within two days): `sl_kits.json` (3 kits, 17 h), `sl_accounts.json` (5 Starlink
  accounts), `sl_invoices.json` (25), `sl_plan_pricing.json` (36, 27 min), `sl_hardware.json` (3),
  **`crm_clients_cache.json` (91 clients — Finance's own copy of every uCRM client, 32 min old)**,
  `crm_services_cache.json` (5, 5 distinct clients), `crm_starlink_reference.json` (3), `processed_pdfs.json`,
  `missing_invoice_alert.json`, `activity_log.json`, with a `.bak` twin beside each register. Stale or empty:
  `crm_invoice_export.json` (467 rows, 141 days), `sl_orders.json` (empty), **`config.json` (2 bytes)**.
  `sl_usage.json` is absent here — Finance reads Data Report's.
- **The kit register links kits to CRM clients (#7, #47, #69, all Active) and to nothing else:** no service
  line, no router, no Starlink account on any kit. Data Report's generated registry shows the same emptiness.
  At the other end, Data Report's `sl_svc_cache.json` has 19 service lines, 17 with an empty kit number and 0
  with a CRM id (3 marked active, 16 unknown), and `wifi_router_map.json` has 8 routers, all with a service
  line, none with a customer. The chain customer → kit → service line → router is broken at kit → service
  line in both registers.
- **Identity fields:** `public.php` alone names the customer id four ways (`crm_client_id` ×92,
  `assigned_client_id` ×22, `crm_id` ×81, `clientId` ×49) and the customer's name two ways (`customer_name`
  ×41, `assigned_client_name` ×66); `kit_number` ×252, `starlink_account` ×192, `account_number` ×249.
- **Writers:** `saveJSON` (`public.php:167–175`, temporary file + `LOCK_EX`, atomic); the uCRM copies are
  written at render time (`public.php:547`, `1604`, `1613`) and by the hourly import in `main.php` behind
  `api_import.lock`, which reads `../dishnet-data-report/data/` ("never writes there", `public.php:992`);
  `fix_kit_links` in `mode=apply` rewrites `sl_kits.json` after a timestamped backup and logs to
  `activity_log.json` (`public.php:5265–`); v7.3.9 reads `dr_kit_registry.json` (`10945–10950`) and
  `sl_usage.json` (`form.php:3624`).
- **Towards uCRM:** GET helpers on `/api/v1.0/` with `X-Auth-App-Key` (`public.php:50–106`, settings at
  `334`) and **one PATCH helper (`public.php:101–106`)** — a write path whose callers the pattern search did
  not capture.
- 11 PHP files, all 141 days old (a 7.3.9 build untouched since May); `templates/form.php` alone is 15,243
  lines and `public.php` 13,692.

#### I.1.3 Part 7 — Data Report's synchronisation architecture

- **Dispatcher:** `main.php` behind `main.lock`, ticked every 30 minutes (measured), includes six crons on
  their own cadences behind their own locks (`cron.lock` with a 25-minute stale rule, `cron_auto_block.lock`,
  `backup_cron.lock`, `invoice_details_cron.lock`, `orders_cron.lock`, `cron_session.lock`,
  `full_history_scan.lock`, `cron_test_block_extend.lock`). Progress in `data/main_dispatch.log`, last 200
  lines kept.
- **Starlink side:** five accounts with encrypted session material in `dr_accounts.json` (`smSaveAccounts`;
  never opened by the audit beyond the count) and `sl_sync_settings.json` (18 entries); `cron.php` writes
  `wifi_router_map.json`, the discovery files, the invoices file and `dr_plan_cache.json` (3 entries);
  `KitRegistryWriter` regenerates `dr_kit_registry.json` from Finance's `sl_kits.json`, which
  `templates/wifi_tab.php:485` also reads directly. **All five sessions are dead or cookie-less (I.1.1).**
- **uCRM, read:** `cron.php:2259` `clients/services?limit=5000`; `cron_auto_block.php:117–118`
  `clients/services?limit=5000` and `clients?limit=5000` every 2 h; `public.php:179` `service-plans/{id}`
  and `232` `clients/services?clientId=`; the customer view resolves identity through uCRM's `/current-user`
  with the client-zone cookie (`client.php:41–50`, "same method as Finance plugin").
- **uCRM, write:** exactly one — `cron.php:2525` POST `/api/v1.0/email/send`, mail through uCRM's mailer.
  No customer, service or invoice record is written by Data Report (`backup.php:264`'s POST is the Google
  Drive backup; `fleet_home.php:944` is a browser form).
- **Cross-plugin coupling by file path:** Finance's `data/` for backup and for `sl_kits.json`; the hybrid at
  the **telecom** path in four places (`public.php:779`, `798` — the verifier; `client.php:410` — Back to
  Portal; `cron_auto_block.php:366` — the admin alert URL), as docs/36 §I found; the shared
  `_dishnet_shared/internal_auth.json` (`public.php:1005–1006`).
- **Recovery surface, one click each from the Sync Status page:** `dr_cron_unstick`, `dr_cron_nuclear_reset`,
  `dr_cron_force_run`, `dr_debug_single_account`, `dr_sync_now&rediscover=1`, `dr_cron_test_cookie`,
  `dr_cron_reset_schedule`, `dr_kit_registry_view`, `dr_full_history_scan_start`.
- Data lives in the upgrade-deleted `data/`; `.dishnet-data-report-data` is absent (docs/40 Q2 stands).
  `cron.php:1510–1537` also appends raw Starlink response dumps to a diagnostics file there.
- `dr_kit_registry.json`'s envelope names its generator "dishnet-data-report 2.8.73" on the 2.8.80 build — a
  stale version constant, cosmetic.

**Still PENDING:** the contents of `--identity 1`, `--login-phone` and `--login-email` (run at 08:40–08:41,
files on the server). §B's field comparison, §C's hybrid columns and §E's live scorecard wait for them.

### I.2 `--urls` — 08:40:39 UTC, 26 September (pasted in full)

- **U1 — the plugin's own link builder.** uCRM reports itself as `https://crm.dishnetuganda.com:8443/crm/`
  and the plugin's address as `https://crm.dishnetuganda.com:8443/crm/_plugins/…/public.php`. The override
  `crm_public_url = https://crm.dishnetuganda.com` sits in `config.json` and in the vault, with no stray copy
  in `kyc_config.json` or the settings store. **Both readers — crons/webhook and screens/portal/API — build
  every link on `https://crm.dishnetuganda.com`, without `:8443`**: the 5.18.34 fix holds. The tool's one
  "needs fixing" item is UISP's, not the plugin's: the bare `/crm` answers `301 → https://crm.dishnetuganda.com:8443/crm/`.
  The tool also logged `[ConfigVault] restored after re-install:` naming ten keys. That is `PluginConfig::load()`
  filling, in memory, keys the file copy lacks from the vault and then refreshing the vault file
  (`ConfigVault.php:228–232`) — the routine every cron tick and webhook call performs; no setting changed
  value. Recorded because the audit promised no writes: this one is the plugin's own, on every load.
- **U2** — `?page=customer_manifest` 200; `start_url` is the path
  `/crm/_plugins/dishnet-hybrid-sudan/public.php?page=customer_login`, resolved by the browser against
  whatever origin it is on.
- **U3 — the certificates (MEASURED, `openssl` from the server to its own public name):**

  | port | issuer · subject | valid to | verify |
  |---|---|---|---|
  | 443 | Let's Encrypt (YR2) · CN `crm.dishnetuganda.com`, SAN `crm.dishnetuganda.com` | 2 Dec 2026 | **0 (ok)** |
  | 8443 | self-signed · CN `localhost`, SAN `localhost` | 26 Nov 2028 | **18 (self-signed certificate)** |

  A browser on `:8443` therefore objects twice: an untrusted issuer and a name that is not the site's.
- **U4 — where a browser lands** (one request each, redirects not followed, TLS verified):
  `https://…/crm` → **301 → `https://crm.dishnetuganda.com:8443/crm/`** (UISP's nginx, even through Traefik);
  `https://…/crm/` → 302 → `/nms/login?returnurl=/crm/` (UISP's sign-in, same origin); `http://…/` → 301 →
  `https://…/`; `http://…:8080/` → 301 → `https://…:8443/`. The customer sign-in page answers **200 on 443**;
  on **8443 curl refused the certificate**, as a browser would warn.
- **U5** — the live website's home page links the portal sign-in **3** times and uCRM's own `/crm/login`
  **0** times: **the decision-8 website is deployed.**
- Summary: 5 ok, 1 failed (U3 on 8443 — the finding, not a fault of the run), 1 note.

**Consequence for §J.2.** Question 7 is answered by measurement: the warning is the self-signed `localhost`
certificate on 8443. Two doors still lead there — typing or bookmarking the bare `/crm` (UISP's cached
301), and uCRM's own e-mails and redirects built on `…:8443`. The plugin's links and the website do not.

### I.3 `--compare` — 08:41:30 UTC, 26 September (pasted in full)

Phone sign-in → **CRM #1**, 1 account (08:40:50). E-mail sign-in → **CRM #1**, 1 account (08:41:06).
Fingerprints equal: **C1 SAME customer identity and the same account scope by both routes.** This is
Part 9's answer for the controlled customer: the WhatsApp/phone route and the e-mail route resolve to the
same uCRM client and the same account set. (The operator ran the whole set twice, at 06:25–06:26 on the
previous build of the command and at 08:40–08:41 on the extended walk; the 08:4x logs are the ones to read.)

### I.4 `--identity 1` — 08:40:49 UTC, 26 September (pasted in full)

**Hybrid (store copy).** Index row id 1, Family Shoppers, phone …217, e-mail b***@outlook.com, service
"Residential (up to 400 Mbps)", not a lead, not archived, has a service and an invoice, **updated 08:40:06** —
the 60-second delta keeps it fresh. `ucrm_clients_cache` present with 1 contact; `ucrm_services_cache` 1 service
(id 6, price 329,000, status 1, plan 3); `ucrm_invoices_cache` 1 invoice, unpaid, outstanding 329,000; 0
payments; **`equipment_assignments` 0 and `stock_units` 0 for this customer** (the tables hold 2 units and 2 live assignments for other customers — §I.6); `customer_identities` and `kyc_applications`
tables absent; `customer_sessions` 5 rows, 0 live. Audit rows for this customer: consent 1, DPO initiate 1,
**invoice PDF download 2** (the operator's own two opens — the reported issue), login 5, logout 4, code sent 5,
one "WhatsApp not configured" from before the transport was set.

**uCRM live** (GET through the plugin's own client, nothing written): client 1, Family Shoppers, a company
(`clientType 2`), 1 contact, **`isActive false`**, not a lead, not archived, balance −329,000, outstanding
329,000; no client custom attributes; **1 service: id 6, plan 3, UGX 329,000, status 1, active from 20 Sep 2026,
attributes `starlinkDetails=<value>`**; 1 unpaid invoice; 0 payments.

**Finance:** `sl_kits.json` 3 kits, none for #1; its own uCRM copy has #1; 1 of its 5 cached services is #1's;
no usage file. **Data Report:** registry 0 kits for #1; 19 service lines, none carrying #1; 8 routers, none for
#1; 14 usage rows, none for #1.

**Field by field (I-5):** customer id, name, e-mail, phone, service, plan, price, invoice, payment status and
active state — **every system that has the field agrees.** Kit, kit serial, Starlink account: none anywhere.
Usage: not comparable (unavailable in the API, no rows for this client in Data Report).

**What it means.** One identity, consistently held: uCRM is the source, the hybrid is a faithful copy within a
minute, Finance's own copy agrees. The break is the kit chain: uCRM knows this is a Starlink service
(`starlinkDetails`), the hybrid's intake prefers exactly that key but cannot assign into an **empty**
`stock_units` table (it refuses to invent stock), Finance was never told, and Data Report only knows kits that
Finance knows. Two observations for the operator, not decided here: uCRM's `isActive false` on a client whose
service is active and invoiced (the plugin does not act on the flag — §D.16); and the KYC tables' absence,
which says the onboarding path has never run on Uganda.

### I.5 `--login-phone` (08:40:50) and `--login-email` (08:41:06) — pasted in full

Both routes signed the operator's own record in, walked every screen, and logged out. The scorecard in §E is
their result. Points worth stating on their own:

- **The identity.** Phone → CRM #1, 1 account; e-mail → CRM #1, 1 account; same fingerprint (§I.3).
- **The invoice PDF, live:** 200, `application/pdf`, 5,976 bytes, `Cache-Control: private, no-store`, no
  redirect, on `https://crm.dishnetuganda.com` with no port in the address; the same link **401 after logout**
  in both walks. The link the portal builds is origin-relative and carries no token. This settles §J.2's
  questions 1, 3, 8, 9 and 10 by measurement; question 7 is settled by §I.2.
- **The Support tab, live** (phone walk, pages rendered because consent was on record): `+211` ×11,
  `211921443002` ×10, the South Sudan domain ×2 — **23 South Sudan literals, 0 Uganda contacts**, exactly the
  sandbox count. The home page: `+211` ×6, `Juba` ×1. **The public Terms page:** South Sudan ×3, Juba ×3,
  `+211` ×2, the South Sudan domain ×3 (both walks).
- **The sign-in page, live** (e-mail walk, read because every portal page redirected): DishNet Africa ×2,
  Kampala ×1, Uganda ×2, 256705993348 ×1, no South Sudan wording — the tenant profile works where it is read.
- **Consent per identifier:** the e-mail route was sent to the consent step on all eight pages although the
  same customer had accepted by phone (§D.15). Not accepted by the audit.
- **Data Report hand-off:** token minted; the sibling answered 404 "Report not found" — docs/36 §0 confirmed
  live.
- **Transport and hygiene:** the phone code went by WhatsApp (Evolution), the e-mail code by e-mail; the cookie
  is HttpOnly · SameSite=Lax · Secure; the JSON body carries no token; neither the code nor the cookie appears
  in the container log.
- Phone walk 29 ok / 3 failed / 6 notes; e-mail walk 22 ok / 1 failed / 14 notes. The four failures are the
  tenant findings (L5, L9, L10), pinned as expected by the rehearsal.

### I.6 `--chain 1` (09:44:02) and both walks re-run with the in-PDF scan (09:44:03, 09:44:26) — pasted in full

The kit chain for CRM #1, read only, serials masked (full record: docs/39 §2, §4, §8, §9, §13):

- **uCRM:** service 6 carries `starlinkdetails` = **KIT…KWW** (16 characters, a KIT-pattern serial), and the
  hybrid's intake extracts exactly that serial. Invoice **id 4, number 000003**, status 1, total 329,000, paid
  0; payments 0.
- **The hybrid's register — a correction to §B, §C, §D.4, §E and §I.4 above:** `stock_units` holds **2 units**
  and `equipment_assignments` **2 live rows**, both for other customers; **0 for #1**, and KIT…KWW is in no
  stock row, so the intake's verdict is `not_in_stock` ("assigning it would invent inventory"). The earlier
  "empty" wording read the customer's own rows, not the tables. `kit_sl_map.json` absent; the hybrid's own
  `sl_usage.json` 1 row; its Starlink session store: **one account (ACC…-43), state `expired`, imported
  2026-09-13 06:18:12, last checked 2026-09-26 09:40:04, 0 failures.**
- **Finance:** `sl_kits.json` 3 kits, 0 for #1, KIT…KWW absent; 0 kit rows naming a Starlink account or a
  service line; `crm_starlink_reference.json` 3 rows, 0 for #1; its uCRM PATCH helper `apiPatch` has **0 call
  sites** — Finance never writes to uCRM; kit-writing actions `fix_kit_links`, `add_purchase`, `sell_kit`,
  `deploy_to_customer`, `add_kit`.
- **Data Report:** registry 4 kits (regenerated 08:00:27 by "2.8.73"), KIT…KWW absent, 0 for #1; `sl_svc_cache`
  19 lines (2 with a kit number, 0 with a CRM id), the serial on none; **`wifi_router_map.json` names KIT…KWW on
  2 routers**; usage rows for it 0. `KitRegistryWriter` reads Finance's `sl_kits.json` as "the single source of
  truth for what KITs exist" and marks `_metadata.in_finance_sl_kits`. The "Needs Sync" rule is
  `templates/fleet_home.php:624–630` (KPI "Needs Sync · Active · no data" when `$activeNoData > 0`) and
  1026–1032 ("Sync needed — Active KIT — no usage data yet"); the two kits are KIT…BX6 (CRM #7) and KIT…KFR
  (CRM #47); **client #1 affected: NO**.
- **Chain verdict:** uCRM attribute ✓ · hybrid stock ✗ · hybrid assignment ✗ · Finance ✗ · Data Report registry
  ✗ · account / service line ✗ · usage ✗ — **first break: the hybrid's stock holds the kit.** 1 ok, 0 failed;
  the store copy removed, uCRM read with GET only, nothing assigned or written.
- **Both walks re-run:** identical to 08:40 in every line (phone 29 ok / 3 failed / 7 notes; e-mail 22 ok /
  1 failed / 15 notes; the failures are L5, L9, L10), plus the new scan: **inside the invoice PDF, 2 content
  streams, 2 URLs, hosts `crm.dishnetuganda.com:8443` ×2** (note L11) — in both walks, on the 443 origin. The
  PDF link itself is still origin-relative and token-free, 401 after logout, and neither code nor cookie
  appears in the container log.

## J. Two post-login findings from the operator's own test (added 26 September)

Both were traced in the repository (REPOSITORY) and then exercised against the **real portal code** in the
sandbox (SANDBOX: the uganda profile, a customer with consent on record, a fake uCRM serving one invoice
PDF). The live values come from the extended `--login-*` walks and the new `--urls` mode (PENDING).
Nothing was changed; no fix is built.

### J.1 Issue 1 — "Help" shows South Sudan after login

**What the customer sees.** The portal's bottom navigation has a **Support** tab (`portal.php:7734`,
`7744`; the operator calls it Help). Its screen (`tabs/customer_app/portal.php:1062–1135`) is hard-coded:
a WhatsApp card to `+211 921 443 002`; a "Call us" row that **displays `+211 921 443 005` and dials
`+211 921 443 002`**; an e-mail row to the South Sudan address; two more WhatsApp rows to `+211 921 443 002`
("Internet slow or down", "Help paying invoice").

**Root cause.** The post-login portal never consults the tenant profile. `tabs/customer_app/portal.php`
(7,772 lines) and `portal_data.php` (1,049 lines) contain **zero** references to `TenantProfile` or
`CustomerContact`. The sign-in page does (`login_web.php:44–45`; footer `271–278`; the dial hint through
`PortalLocale`), which is why a Uganda customer sees Uganda before signing in and South Sudan after. One
plugin serves both countries with two profiles (`profiles/south-sudan.json`, `profiles/uganda.json`;
selector `tenant_profile`, else the currency: UGX → uganda; the Uganda install's `currency_code` is UGX).
The Phase 2 work (docs/07, 5.18.38) re-pointed the readers it listed; the portal was not among them.

**Every South Sudan literal in the customer app** (REPOSITORY):

| File | Literal | Where |
|---|---|---|
| `portal.php` | `+211921443002` ×16 (WhatsApp) | support view 1073, 1104, 1119; plans 978; WiFi 1587, 5180; hotspot 3310; service status 5010; the generic helper and error paths 4200, 6805, 6947, 7228, 7282, 7296 |
| `portal.php` | `+211 921 443 005` (displayed) | 1088 — in neither profile and not in `CustomerContact::DEFAULTS` |
| `portal.php` | the South Sudan e-mail ×2 | 1092, 1096 |
| `portal.php` | `Juba` ×4: "Juba, South Sudan · Updated just now", "Juba metro areas", "Juba, Yei, Wau", "Location: Juba" | service-status view 4885, 4966, 4991, 5010 |
| `portal_data.php` | `$portalLocation = 'Juba'`; city default `'Juba'` | 1020, 1024 |
| `portal_data.php` | currency fallback `?? 'USD'` | 1031 (§D.9) |
| `legal_page.php` | footer "DishNet Africa Ltd. · Juba, South Sudan"; WhatsApp `+211 921 443 002`; the South Sudan e-mail | 307, 334, 335 |
| `lib/LegalContent.php` | Terms: "registered in South Sudan… customers in Juba" (37–38); "governed by the laws of the Republic of South Sudan… the courts of Juba" (116–118). Privacy: "South Sudan regulatory authorities" (174–175) | the consent step every customer must accept |

**Measured in the sandbox against the real portal code:** the Support tab renders **23** South Sudan
literals and **0** Uganda contacts; every portal page embeds `+211` **6 times** (the WhatsApp helper's
number in the page's script) and the home page shows `Juba` for a client without a city; the public Terms
page names South Sudan and Juba. The live walk prints the same counts for the operator's record (L5, L9,
L10).

**The six questions.**
1. The link and destination: the Support tab, `?page=customer_portal&view=support`, rendered with the
   contacts above; WhatsApp opens `wa.me/211921443002`; "Call us" dials `+211921443002`.
2. Generated by `tabs/customer_app/portal.php` (the support view and the `openWhatsApp` helper); the legal
   pages by `tabs/customer_app/legal_page.php` and `lib/LegalContent.php`.
3. Hard-coded. Not generated through `TenantProfile` or any configuration key.
4. Yes: every WhatsApp button in the portal (16), the displayed phone, the e-mail, the location default,
   the service-status view, the legal pages' footer, and the Terms/Privacy wording (registration,
   jurisdiction, regulator). Company details on the sign-in page and in every customer e-mail are already
   tenant-aware (`login_web.php`, `EmailTemplate`, `CustomerEmails`, `CustomerContact` for WhatsApp copy).
5. Yes: one plugin, two profiles; Uganda selects `uganda` through UGX. Sudan is unaffected by the literals
   only because they happen to be its values — except the displayed 005 number, which is in no profile.
6. The correct destinations, changing neither:
   - **Uganda** (`profiles/uganda.json`): WhatsApp `256705993348`; phone `+256 705 993 348`; e-mail
     `accounts@dishnetuganda.com`; DishNet Africa Limited, Acacia Mall, Kampala; law: the Republic of
     Uganda; regulator: Uganda Communications Commission. **Courts: not in the repository**
     (`jurisdiction.courts` is null) and **no Uganda legal text exists** (`legal_texts` is null).
   - **South Sudan** (`profiles/south-sudan.json`): WhatsApp `211921443002`; support phone
     `+211 921 443 006` (the portal shows 005); e-mail `info@dishnetafrica.com`; DishNet Africa Ltd.,
     Airport Road, Juba; law: the Republic of South Sudan; courts: Juba.

**Recommendation.** Every customer-facing help and support value resolves from the tenant profile, in
one place: `portal_data.php` loads `TenantProfile::current($config, $dataDir)` once and hands it to every
view; the support view, the plans / WiFi / hotspot / status buttons and the `openWhatsApp` helper take
`contacts.support_wa`, `contacts.support_phone` and `email`; the location default takes `office.city`;
the currency fallback takes `currency.code`; `legal_page.php`'s footer takes the profile's entity, locality
and contacts; `LegalContent` is parameterised by entity, registration country, jurisdiction and regulator.
**The legal wording needs a business decision first:** Uganda's courts and any Uganda-specific clauses are
not in the repository; the Terms must not silently become "the laws of Uganda, the courts of null".
Severity: **High** for the customer experience (a Uganda customer messages a South Sudan number; the
consent binds to the wrong jurisdiction); not a security defect.

**Regression tests, to write with the fix.** (a) Render the support, home and service-status views and
both legal pages under each profile through the real router and assert no cross-tenant literal, as
`tests/test_email_no_sudan.php` does for e-mails; (b) a scan that `tabs/customer_app/*.php` and
`lib/LegalContent.php` carry no `+211`, `Juba`, the South Sudan domain or "South Sudan" outside a
`TenantProfile` fallback argument, every exception named; (c) the sandbox rehearsal's L5 / L9 / L10 flip
from pinned failures to passes — the harness names them one by one, so it fails until the fix lands and
fails again if a literal returns.

**Rollout / rollback.** One plugin release (version, docs/07 entry), deployed with `scripts/deploy-hybrid.sh`
as before; verified by `journey-audit.sh --login-*` (L5 / L9 / L10 pass) and by opening Support on a phone.
Rollback: redeploy the previous plugin commit with the same script; no data migration. Sudan: identical
code path, profile `south-sudan`, values unchanged except that "Call us" shows the profile's support phone.

### J.2 Issue 2 — the invoice PDF URL carries a port and the browser warns

**The flow, from the code** (REPOSITORY):
1. The invoices screen's button (`portal.php:1418`) builds
   `<the page's own path>?page=api&action=app_invoice_pdf_download&inv_id=<id>&account_id=<id>` — the path
   from `$_SERVER['REQUEST_URI']` without its query. **No scheme, host, port or token.**
2. `DishNet.viewPdf()` (`portal.php:6810–6890`) fetches it with `DishNet.apiFetch()` (`6727`: plain
   `fetch(url, {credentials: 'same-origin'})` plus `X-Requested-With`, no base-URL prefix), turns the answer
   into a `blob:` URL shown in an iframe; the "↗" button opens that blob URL; the fallback "Open PDF" link is
   the same relative URL.
3. `app_invoice_pdf_download` (`api_customer_app.php:4200–4247`): `ca_require_auth` — the HttpOnly cookie
   or a Bearer header, **never a URL parameter** (`CustomerSession::fromRequest`); `ca_resolve_active_client_id`
   — `account_id` must be the session's `sub` or in its `accounts` allow-list, else 403;
   `ca_require_invoice_for_account` — the invoice must be in `ucrm_invoices_cache` with this client's id, else
   404; then uCRM's `invoices/{id}/pdf` is fetched **server-side with the plugin's app key**, the bytes are
   streamed `inline` with `Cache-Control: private, no-store`, and an audit row (invoice id and number only)
   is written. No redirect, no `Location`.

**Root cause of the port.** The plugin cannot put a port in this URL: it is origin-relative. The port the
operator saw is the origin their browser was already on. On this host the same plugin answers on two
origins: `https://crm.dishnetuganda.com` — port 443, Traefik, a Let's Encrypt certificate — and
`https://crm.dishnetuganda.com:8443` — UISP's own web server with **a self-signed certificate** (docs/01,
docs/05, docs/09, docs/33 §1). 8443 is a public port, open in the firewall because routers connect on it
(docs/06); it is not an internal one. A browser on `:8443` shows a certificate warning, and every relative
link, the PDF and even the `blob:` URL inherit `:8443` from the page. **How a customer lands there:** uCRM's
own e-mails and redirects (the client-zone invitation, uCRM's invoice e-mails, its post-login redirect)
carry the address uCRM was configured with, `crm.dishnetuganda.com:8443` (docs/33 §2, §6 — "not the
plugin's to change"); the bare `/crm` is answered by UISP with a **301** to `…:8443/crm/`, which browsers
cache (docs/33 §5); an old bookmark. The plugin's own *generated* links have been on the standard port since
5.18.34 (`crm_public_url`, docs/33), and the website's Customer Login opens the portal sign-in on 443
(decision 8; **U5 measured the live site linking it, §I.2**).

**Added 09:44 (both walks re-run, note L11): the invoice document itself carries two links to
`crm.dishnetuganda.com:8443`.** The scan reads only the hosts inside the PDF's content streams: 2 streams,
2 URLs, both on uCRM's own address. uCRM's renderer writes them — the plugin streams the bytes unchanged, and
its shipped `invoice_uganda` template prints no link — so a customer who opens the invoice on 443 and follows a
link inside it lands on `:8443`. That is the likeliest explanation of the operator's own warning, since the
portal page was on 443. **Corrected 26 Sep 21:30 (docs/38 §7.2 item 3):** "its shipped `invoice_uganda`
template prints no link" was wrong. The template prints uCRM's `invoice.onlinePaymentLink` twice in its PAY NOW
box, and those are the two URLs. The fix moved to that template (C-2b), because UISP 3.0.159 has no setting for
uCRM's port. The next sentence is withdrawn with it, since the template changes what uCRM writes into the
document too. Only C-2 in docs/38 (the address uCRM believes it has) changes what uCRM writes into
the document; the canonical-host redirect and the Traefik rule cannot reach a link inside a PDF. After C-2,
download one already-issued invoice to learn whether uCRM re-renders it with the new address.

**The ten questions.**
1. URL format: `https://<the origin the customer is on>/crm/_plugins/dishnet-hybrid-sudan/public.php?page=api&action=app_invoice_pdf_download&inv_id=<n>&account_id=<n>`; the viewer shows `blob:https://<origin>/<uuid>`. Numeric ids only; no token.
2. `portal.php:1418` (button), `6810–6890` (viewer), `6727` (fetch); `api_customer_app.php:4200–4247` (endpoint).
3. HTTPS on both origins. Plain `http://…` → Traefik 301 to https; `http://…:8080` → UISP 301 to `:8443` (docs/01). Live: `--urls` U4.
4. Host `crm.dishnetuganda.com`. Port 443 = Traefik / Let's Encrypt, the intended public address. Port 8443 = UISP's own HTTPS listener, public (open for routers), self-signed. Live: `--urls` U3.
5. Path on 443: browser → Traefik (file-provider route) → UISP's nginx → uCRM's PHP → the plugin's `public.php`. Path on 8443: browser → UISP's nginx directly. The plugin runs identically on both.
6. `CustomerSession::isHttps()` honours `X-Forwarded-Proto`, `X-Forwarded-Ssl`, `HTTPS` and port 443, so the `Secure` cookie flag is set on both paths. **There is no canonical or base-URL rule for the customer pages**: the plugin answers on whatever host and port the request arrived on, and its relative links keep the customer there. `crm_public_url` corrects only the absolute links the plugin generates (e-mails, WhatsApp, DPO, "View in CRM").
7. The warning is **the certificate on 8443** — not HTTP, not mixed content (page and API share the origin), not a redirect. **MEASURED (§I.2): 443 presents a trusted Let's Encrypt certificate for `crm.dishnetuganda.com` (verify 0); 8443 presents a self-signed certificate for `localhost` (verify 18), so the browser objects to the issuer and to the name.** The 443 certificate is sound, so the warning can only have come from `:8443`. Still useful: the operator's address bar at the time, to name the door that led there (the bare `/crm`, a uCRM e-mail, a bookmark).
8. No JWT, session cookie, bearer token or other credential in the invoice URL; the session is the HttpOnly cookie. (The WhatsApp "send me this invoice" path is different by design: `serve_temp_pdf&file=…&token=…`, a random token compared with `hash_equals`, ten minutes, the file deleted after its first serve — sent to the customer's own number only.)
9. Yes. Authentication is checked on every request (`ca_require_auth`); the account must be in the session's allow-list (403 otherwise); the invoice must belong to that account (404 otherwise). The ownership check is cache-based: an invoice absent from `ucrm_invoices_cache` is a 404 even for its owner — a completeness gap, not an exposure.
10. The URL never expires by itself; access ends with the session. Logout revokes the session (`customer_sessions.revoked_at`); **SANDBOX: the same PDF link answers 401 after logout**; sessions also expire by `expires_at`. Live: the walk's L7.

**SANDBOX proof of the endpoint:** with the cookie, `200`, `application/pdf`, 1,069 bytes, `Cache-Control:
private, no-store`, no redirect; after logout, `401`.

**Recommendation — fix the origin, not the endpoint, which is sound.**
1. **Keep customers off `:8443`.** (a) uCRM: Settings → System → Application → server domain and port 443,
   if editable (docs/33 §6: if greyed out as managed by UISP, stop — UISP's own port must not change,
   routers depend on it); or (b) a real certificate on 8443 (docs/05 Option B), so uCRM's own links open
   without a warning; and (c) the website redeploy, so every public door is the 443 sign-in link.
2. **Plugin-side, small and testable: a canonical-host redirect for the customer pages.** When
   `crm_public_url` is set and a `customer_login` / `customer_portal` GET arrives on a different host:port,
   answer 301 to the same path and query on the public address. Sudan: no override, no redirect — exactly
   as `dn_with_override()` behaves today. This closes the cached-301 and the uCRM-e-mail cases for the portal
   without touching UISP.
3. **Do not** move the session into the URL, sign the PDF URL, or change the endpoint's checks: cookie +
   allow-list + ownership + server-side fetch is the design this audit found correct.

Severity: **Medium** — a customer-facing certificate warning that stops some customers; no exposure.
(High only if U3 shows the 443 certificate itself untrusted, which nothing so far suggests.)

**Regression tests.** (a) `portal.php`'s invoice and receipt links carry no scheme, host, port or `token=`
(a rendered-page test under `/crm/_plugins/…`, as `test_customer_pwa.php` already serves the plugin);
(b) the canonical redirect: with `crm_public_url` set and a request on `crm.example:8443`,
`?page=customer_portal` answers 301 to the public address with the same path and query; without the
override, no redirect; POSTs never redirected; (c) `test_links_without_port.php` still passes; (d) the
walk's "Invoice PDF WORKING" and "401 after logout" lines stay green.

**Rollout / rollback.** The uCRM / certificate change is an operator action on UISP (docs/33 §6, docs/05),
independent of any release. The plugin change ships in one release with the Issue-1 fix, deployed with
`scripts/deploy-hybrid.sh`; verified by `--urls` (U1–U5) and by opening an invoice PDF from a phone on
mobile data; rollback: redeploy the previous commit. Nothing changes for Sudan (no override set there,
docs/33 §3).

### J.3 What the live runs add

All received: `--login-phone` / `--login-email` (§I.5), `--urls` (§I.2), `--compare` (§I.3), `--identity 1`
(§I.4), and at 09:44 `--chain 1` with both walks re-run and the in-PDF link scan (§I.6). Nothing in §J is
pending except the fixes, which wait for approval.

Rehearsed against the real portal code in the sandbox: **75 of 75 checks, two consecutive runs** (60 when §J
was first written; the `--client-flags`, `--chain` and in-PDF checks were added since), with
L5, L9 and L10 pinned as the expected failures (they flip when the fix lands), the PDF streamed and refused
after logout, and the `--urls` mode on plain http declaring the TLS checks not measured.

## K. Verdict (Part 12 — the report's parts A–H are §A–§H above; this is the answer to the question asked)

> *Does a real DishNet Uganda customer have one consistent identity, and can they log in by phone/WhatsApp
> or e-mail and use the same complete customer experience with synchronised service, Starlink, usage and
> billing data?*

**Identity — YES, measured.** The customer is the uCRM client id. Both sign-in routes resolve CRM #1 to the
same account set; uCRM, the hybrid and Finance's copy agree on every shared field; the hybrid's copy was a
minute old. Data Report has no record of #1, by construction: it keys on kits.

**The same experience by either route — YES for the account, plan, invoices, PDF and payments; NO beyond
that.**
- **Billing:** consistent and live (uCRM live on account open; caches within a minute; PDF served from uCRM
  through the plugin with the session as the only credential).
- **Starlink / equipment / WiFi:** empty for this customer in every system, although uCRM's service carries a
  `starlinkDetails` attribute — the hybrid's stock holds two other customers' units and not this serial, Finance was never told, Data
  Report follows Finance although its router map names the serial on two routers (§D.4, §I.6). No customer can see a kit the estate has not registered.
- **Usage:** not implemented in the API, and Data Report — the only Starlink client — has had no working
  Starlink session in the whole log window (§D.2, §D.8).
- **Help and legal:** the Support tab and the Terms are South Sudan's (§D.7, §J.1).
- **Consent:** asked once per identifier, so the second route asks again (§D.15).
- **The door:** a customer who arrives on `:8443` (uCRM's own links, the cached `/crm` redirect, an old
  bookmark) meets a certificate warning before any of this (§D.14, §J.2).

**Before the Uganda PWA customer journey is declared complete** (the operator's own condition — Help,
invoices, usage and support on the right country profile and on secure public URLs, not only login):
1. The portal tenant pass and the canonical-host redirect (§J), with the legal wording after the business
   decides Uganda's courts and clauses — one plugin release, awaiting approval.
2. The kit chain: decide the single register, enter the stock the hybrid needs (or make the uCRM attribute
   authoritative), re-authenticate Data Report's Starlink accounts, and wire `app_usage` to a real source
   (§F.2, §F.6).
3. Keep customers off `:8443`: uCRM's configured address or a certificate on 8443, plus the website (already
   live on 443) — operator actions on UISP (§J.2, docs/33 §6).

Nothing was changed by this audit. No fix is built until approved.
