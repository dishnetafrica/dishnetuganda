# 39 — Customer Journey & Three-System Synchronization Audit (26 September 2026)

**Controlled customer:** Family Shoppers, uCRM client #1 (the operator's own record). **Systems:** uCRM,
`dishnet-hybrid-sudan` 5.18.40 (the customer portal / PWA, deployed commit `4a2f41c`), `dishnet-data-report`
2.8.80, `dishnet-starlink-finance` 7.3.9. **Method:** repository reading plus read-only runs of
`scripts/journey-audit.sh` on the live server on 26 September (siblings 06:25; identity, both logins, compare,
urls 08:40–08:41; client-flags 09:15). **Nothing was changed:** no migration, sync, unstick, kit assignment,
customer edit, payment, configuration change or deployment. Every personal identifier is masked; every kit
serial is masked; no token, code or secret appears.

The question this document answers:

> When the customer logs in by phone/WhatsApp or e-mail, does the customer get the same account and the same
> complete, synchronised service experience across uCRM, the hybrid, Data Report and Starlink Finance?

**Answer in one paragraph.** Identity: **yes** — both routes resolve CRM #1 with one account and an identical
fingerprint, and every field the systems share agrees, obtained through traced synchronisation jobs, not by
name. Billing: **yes and consistent**. Everything Starlink: **no** — uCRM's service carries a Starlink kit
attribute, but no register holds the kit (the hybrid's stock table is empty on Uganda, Finance was never told,
Data Report follows Finance), Data Report's five Starlink sessions are dead, the hybrid's own collector has no
session, and usage is not implemented in the API. Help and the legal texts are South Sudan's. Consent is asked
once per sign-in route. The invoice PDF is correct and safe; a browser warning appears only for customers who
reached the portal on UISP's `:8443`, where the certificate is self-signed for `localhost`.

Labels: **MEASURED** (from a server run, with the run named), **CODE** (read in the repository at `4a2f41c`),
**PENDING** (a run added for this document, not yet received: `--chain 1`, and the two logins re-run with the
in-PDF link scan).

---

## 1. The customer journey as it runs today

```
 uCRM ── system of record: client, contacts, service (+ attribute starlinkDetails), invoices, payments
   │  webhooks client.add/edit, service.*, invoice.add, payment.add           │  60-second delta cron (clients, DESC, 100)
   ▼                                                                            ▼
 dishnet-hybrid-sudan ── client_search_index (id, name, phone, e-mail, flags) = THE SIGN-IN KEY
   │   ucrm_clients / services / plans / invoices caches · payments cache · app_account asks uCRM LIVE
   │   stock_units + equipment_assignments = the kit register the block workflow and the portal read (EMPTY on Uganda)
   │   OTP by WhatsApp (Evolution) or e-mail → HttpOnly cookie session (customer key set, kid/iss/aud)
   ▼
 Customer portal / PWA ── home · account · services · invoices (+PDF) · payments · sites · Starlink · WiFi · usage · support
   ├─ reads FILES of dishnet-starlink-finance: sl_kits.json (3 typed kits, none for #1)
   ├─ reads FILES of dishnet-data-report: wifi_router_map · sl_svc_cache · dr_kit_registry · sl_usage · block state
   ├─ calls HTTP dishnet-data-report ?action=dr_wifi_* (pause / config / status) with the internal-auth header
   └─ hands the browser to dishnet-data-report/public.php?clientId=…&token=… → 404 on Uganda (South Sudan build)

 dishnet-data-report ── the Starlink client (api.starlink.com); 5 accounts, all dead or cookie-less; WiFi control
                        plane; registry generated from Finance's kits; its own uCRM reads (services, clients)
 dishnet-starlink-finance ── purchases, kit deployments, Starlink invoices, plan pricing; its OWN copy of every
                        uCRM client (91) and of services (5); typed kit register with CRM ids; file-backed
```

Both sign-in routes end on the same portal; the portal's data comes from uCRM through the hybrid's caches and
live calls, and from the two siblings' files. The Starlink half of the diagram is where the chain stops for
Family Shoppers (§4, §9).

## 2. Family Shoppers — the identity map (MEASURED 08:40–08:41, `--identity 1`, both logins, `--client-flags`)

| Identifier | Value | Where it lives | How the others obtain it |
|---|---|---|---|
| uCRM client id | **1** — a company (`clientType 2`), 1 contact, not a lead, not archived, `isActive false` | uCRM | hybrid: webhook + 60 s delta into `client_search_index` (row updated 08:40:06) and `ucrm_clients_cache`; Finance: its own `crm_clients_cache.json` (id 1 present), refreshed at render; Data Report: **none** — it knows customers only through the CRM id on a kit, and #1 has no kit |
| uCRM service id | **6** — plan **3** "Residential (up to 400 Mbps)", UGX 329,000, status 1 (active) since 20 Sep 2026, period 13 | uCRM | hybrid `ucrm_services_cache` (webhook `service.*`, staff sync, cron); Finance `crm_services_cache.json` (1 of 5 is #1's); Data Report reads `clients/services?limit=5000` in its sync cron and every 2 h in the auto-block cron (§I.1.3 of docs/37) |
| Starlink service attribute | **`starlinkDetails` set** on service 6 (value withheld; whether it matches the KIT pattern: PENDING `--chain 1`) | uCRM, typed by staff | hybrid: `KitAttributeIntake` reads it on the review screen (accepted keys `starlinkdetails`, `kitnumber`, `starlinkkit`, `kitno`, `kit` — "verbatim from dishnet-data-report"); Data Report: the same extraction in its sync |
| Invoice | **1**, unpaid (status 1), total 329,000; uCRM invoice id and number: PENDING `--chain 1` (the walks opened it by id and streamed its PDF) | uCRM | hybrid `ucrm_invoices_cache` (webhook `invoice.add`/`payment.add` → surgical refresh; on demand when stale); `app_account` live |
| Payment | **0**; balance −329,000, outstanding 329,000 | uCRM | hybrid live on account open (+ payments cache) |
| Kit id / serial | **none registered anywhere** — hybrid `equipment_assignments` 0, `stock_units` 0; Finance `sl_kits.json` 0 for #1 (3 kits: CRM #7, #47, #69); Data Report registry 0 for #1 | — | the only candidate is the `starlinkDetails` value (PENDING) |
| Starlink account id | **none** for #1 in any system (Data Report `dr_accounts.json` 5 accounts; Finance `sl_accounts.json` 5; hybrid assignment column empty) | Data Report sessions / Finance billing days | — |
| Data Report client identifier | **none** (registry carries `crm_client_id` copied from Finance's kits; `sl_svc_cache` 19 lines, 0 with a CRM id) | — | — |
| Finance customer identifier | `crm_client_id` **1** only inside Finance's own uCRM copy; no kit row, no `crm_starlink_reference` row for #1 | Finance | render-time GET of uCRM clients |
| Phone identifier | …217 (12 digits), canonical international form; the OTP identifier; the index key (last-9-digit match) | uCRM contact → hybrid index | webhook + delta |
| E-mail identifier | b***@outlook.com, lower-cased; the OTP identifier | uCRM contact → hybrid index | webhook + delta |
| Sessions / consent | 5 `customer_sessions` (0 live after the walks); consent row for the **phone identifier only** — the e-mail route was asked again | hybrid | written only by a verified session (`app_record_consent`) |
| Audit trail for this customer | consent 1 · DPO initiate 1 · invoice PDF download 2 (the operator's own opens) · login 5 · logout 4 · code sent 5 · one "WhatsApp not configured" from before the transport was set | hybrid `app_audit_log` | — |

**Proof that both routes are one identity:** phone → CRM #1, 1 account (08:40:50); e-mail → CRM #1, 1 account
(08:41:06); `sha256(id | sorted account ids)` equal (`--compare`, C1 SAME). Neither route created a row in the
index or a second client: the index still holds one row for #1 and the account screen shows 1 account on both.

## 3. Cross-system synchronisation matrix (MEASURED values; methods and frequencies from CODE)

| Field | SOURCE OF TRUTH | SYSTEMS READING IT | SYNC METHOD | SYNC FREQUENCY | CURRENT VALUE (#1) | MATCH |
|---|---|---|---|---|---|---|
| Customer id | uCRM client | hybrid index + clients cache; Finance own copy; Data Report via kits only | webhook `client.add/edit` + `cron_sync.php` delta; Finance `public.php` GET at render + hourly `main.php`; Data Report `cron_auto_block.php` GET clients | 60 s (hybrid); on page view / hourly (Finance); 2 h (Data Report) | 1 / 1 / 1 / — | MATCH |
| Customer name | uCRM | hybrid index + cache; Finance copy; Finance kit register (typed name); Data Report registry (name from Finance) | as above; Finance kit name typed by staff | as above / manual | Family Shoppers everywhere present | MATCH |
| Phone | uCRM contact | hybrid index (sign-in key) | webhook + delta | 60 s | …217 / …217 | MATCH |
| E-mail | uCRM contact | hybrid index (sign-in key) | webhook + delta | 60 s | b***@outlook.com / same | MATCH |
| Service, plan, price | uCRM service | hybrid `ucrm_services_cache` + `ucrm_plans_cache`; Finance `crm_services_cache`; Data Report | webhook `service.*`, staff sync, cron; Finance render; Data Report sync + 2 h | 60 s–2 h | id 6 · plan 3 · 329,000 UGX / same / same | MATCH |
| Invoice | uCRM | hybrid cache; `app_account` live | webhook `invoice.add` → `ClientInvoiceCacheRefresher`; on demand when stale | event + on open | 1 unpaid, 329,000 / same | MATCH |
| Payment state | uCRM | hybrid live + payments cache | live GET on account open; webhook `payment.add` | on open | 0 payments, outstanding 329,000 / same | MATCH |
| Starlink attribute | uCRM service attribute (typed) | hybrid `KitAttributeIntake` (review screen, on demand); Data Report sync | manual entry; on-demand read | — | set on service 6 / not reflected anywhere | **only uCRM has it** |
| Kit | **DISPUTED** — hybrid `equipment_assignments` (authority by code), Finance `sl_kits.json` (typed), Data Report registry (generated from Finance) | portal + block workflow (hybrid); Finance screens; Data Report fleet | Finance: staff forms; Data Report: `KitRegistryWriter` regenerates from Finance; hybrid: review-screen binding | manual / registry hourly-ish (generated 05:30:27) | none / none / none | MATCH (all none) — **but the source is not reflected: broken link** |
| Starlink account | Data Report sessions (`dr_accounts.json`) / Finance `sl_accounts.json` / hybrid assignment | Data Report crons; Finance invoices; hybrid | Data Report cron (dead sessions); Finance manual | — | none for #1 | n/a |
| Usage | Starlink API through Data Report's cron → `sl_usage.json`, or the hybrid's own collector → its `sl_usage.json` | hybrid `KitUsage` (own file first, Data Report's as fallback); `app_usage` returns `unavailable` | Data Report cron (60-min slot, 120-min floor); hybrid `cron/starlink_usage.php` hourly | when a session is alive; none is | none for #1; 14 historical rows for 2 other kits | n/a |
| Active / suspended | uCRM service status | hybrid cache; Data Report block state (`wifi_test_block_state.json`, absent) | webhook → `StarlinkBlockBridge` → Data Report `dr_wifi_test_block` | event | status 1 active / active | MATCH |
| Client `isActive` | uCRM computed flag | hybrid index `is_active` (stored, never read at sign-in) | webhook + delta | 60 s | false / 0 | MATCH — and meaning: §4 note |

Reading the matrix: uCRM → hybrid is a real, traced synchronisation (webhooks plus a delta cron, with the
row's `updated` timestamp 43 s before the run). uCRM → Finance is a copy Finance takes for itself at render
time. uCRM → Data Report exists only for services and clients and only for its own purposes. **Nothing
synchronises kits between the three registers**, and nothing carries a Starlink account or service line for
any kit in either register (docs/37 §I.1.2).

## 4. Where the data chain is broken today

1. **uCRM `starlinkDetails` → hybrid stock.** The intake extracts the serial but refuses to assign it because
   no stock unit carries it ("assigning it would invent inventory"); `stock_units` has **0 rows** on Uganda.
2. **→ hybrid assignment.** 0 live assignments; therefore the portal's Equipment, Starlink and WiFi screens are
   empty and a suspension cannot find a router for any Uganda customer (the block workflow reads only this
   register — CODE, `StarlinkBlockService` header).
3. **→ Finance's register.** No kit for #1; Finance is never told what uCRM's attribute says.
4. **→ Data Report's registry.** Generated from Finance's file → nothing for #1.
5. **Kit → Starlink account / service line.** Empty on every kit in both registers; Data Report's 19 service
   lines have 17 empty kit numbers and 0 CRM ids; its 8 routers have 0 customers.
6. **→ usage.** Data Report's 5 Starlink sessions are dead or cookie-less (`0 fetched` for the whole log
   window); the hybrid's own collector has no session imported; `app_usage` is a TODO.
7. **→ the customer app.** `app_usage` unavailable; Data Report hand-off 404 (South Sudan build).
8. **Consent** keyed per identifier: the e-mail route of the same customer is asked again.
9. **The door:** a customer on `:8443` meets UISP's self-signed certificate before any of this.

*Note on `isActive` (MEASURED 09:15, `--client-flags 1`):* 92 of 93 clients are inactive, including all 79
leads and all 88 clients without a service; **four clients hold an active service yet are inactive (#1, #7,
#47, #71)**; the only active client has an active service and nothing outstanding; both clients with an unpaid
balance are inactive; no tested rule reproduces the flag fully (best: "active service and nothing
outstanding", 91 of 93). It behaves as a billing-side state, not an access state; the plugin never reads it at
sign-in (`ca_login_eligibility` consults archived, lead and has-service). **Decision taken: it does not gate
the portal, and Family Shoppers is not deactivated.**

## 5. PWA customer experience — MEASURED, both routes (08:40–08:41)

| Screen | Result | Evidence |
|---|---|---|
| Login by phone/WhatsApp | **WORKING** | 1 account matched, eligible, "Code sent via WhatsApp", verified, HttpOnly · SameSite=Lax · Secure cookie, no token in the body |
| Login by e-mail | **WORKING** | same, "Code sent via Email" |
| Account | **WORKING** | id 1, service type `starlink`, 1 service, 1 account, not paused, unpaid 329,000; uCRM live and caches agree |
| Services | **WORKING** | "Residential (up to 400 Mbps)", UGX 329,000, active (UGX shown; the `USD` fallback not hit) |
| Sites | **N/A** (no kit) — the screen exists and asks for a kit | `app_site_diagnostics` 400 "kit or router_id required" |
| Starlink | **N/A** (no kit) | same |
| Equipment | **PARTIAL** — 0 items | the hybrid's register is empty (§4.1–2) |
| Usage | **NOT IMPLEMENTED** | `unavailable: true` by design |
| Invoices | **WORKING** | 1 pending, detail with 1 item |
| Payments / receipts | **WORKING** | 0 payments, live from uCRM |
| Documents / PDF | **WORKING** on 443 | 200, `application/pdf`, 5,976 bytes, `private, no-store`, no redirect; 401 after logout; the link inside the document: PENDING (§7) |
| Support / Help | **WRONG TENANT** | 23 South Sudan literals, 0 Uganda contacts on the rendered tab |
| WiFi controls | **PARTIAL** — no router resolved | 200, empty SSID, diagnostic payload explains |
| Terms / consent | **WRONG TENANT**, asked per identifier | Terms page: South Sudan ×3, Juba ×3, `+211` ×2; e-mail route sent to consent although the phone route had accepted |
| Data Report hand-off | **BROKEN** | 404 "Report not found" |
| Logout | **WORKING** | 200; cookie and PDF link 401 afterwards |

## 6. South Sudan content still shown on the Uganda install (CODE + MEASURED live counts)

| Where | What | Live |
|---|---|---|
| Support tab (`portal.php` 1062–1135) | WhatsApp `+211 921 443 002` ×3 rows; "Call us" displays `+211 921 443 005`, dials `002`; the South Sudan e-mail | `+211` ×11, `211921443002` ×10, domain ×2; Uganda 0 |
| Every portal page's script (`portal.php` 4200, 6805, 6947, 7228, 7282, 7296) | the WhatsApp helper's number | `+211` ×6 on every page |
| Plans (978), WiFi (1587, 5180–5182), hotspot (3310), status (5010) | WhatsApp `+211 921 443 002` | in the 16 |
| Status view (`portal.php` 4885, 4966, 4991) | "Juba, South Sudan · Updated just now", "Juba metro areas", "Juba, Yei, Wau" | — |
| `portal_data.php` 1020, 1024 | location default `Juba` | `Juba` ×1 on the home page |
| `portal_data.php` 1031; `api_customer_app.php` | currency fallback `USD` | not hit for #1 (UGX shown) |
| Terms and Privacy (`lib/LegalContent.php` 37–38, 116–118, 174–175) | registered in South Sudan; laws of South Sudan; courts of Juba; South Sudan regulators | South Sudan ×3, Juba ×3 |
| Legal page footer (`legal_page.php` 307, 334, 335) | DishNet Africa Ltd. · Juba, South Sudan; WhatsApp `+211 921 443 002`; South Sudan e-mail | `+211` ×2, domain ×3 |
| Company address | the sign-in page and every e-mail are Uganda (`login_web.php`, `EmailTemplate`); the portal shows no address of its own | sign-in page: Kampala ×1, Uganda ×2, 256705993348 ×1 |
| Data Report | heading "DISHNET AFRICA · JUBA, SOUTH SUDAN"; Back-to-Portal to the telecom path | docs/36 §I |

Root cause (CODE): `portal.php` and `portal_data.php` contain **zero** references to `TenantProfile` or
`CustomerContact`; the sign-in page and every e-mail do read the profile. Correct Uganda values exist in
`profiles/uganda.json` (WhatsApp 256705993348, phone +256 705 993 348, accounts@dishnetuganda.com, DishNet
Africa Limited, Kampala, law: the Republic of Uganda, regulator: UCC); **courts and legal texts are null** —
a business input, not a code fact.

## 7. PDF / HTTPS / `:8443` — root cause

Five hypotheses, answered:

| Hypothesis | Answer | Evidence |
|---|---|---|
| The PDF URL is generated incorrectly | **No.** `portal.php:1418` builds `<the page's own path>?page=api&action=app_invoice_pdf_download&inv_id=…&account_id=…` — no scheme, host, port or token | CODE; MEASURED: 200, PDF, no redirect, origin `https://crm.dishnetuganda.com` with no port |
| uCRM generates the customer-facing URL | **No.** The plugin fetches `invoices/{id}/pdf` from uCRM server-side with its app key and streams the bytes; the browser never sees a uCRM URL | CODE `api_customer_app.php:4200–4247` |
| The plugin rewrites it | **No.** Bytes are streamed; the viewer shows a `blob:` URL on the page's own origin | CODE `portal.php:6810–6890` |
| The reverse proxy redirects it | **Not the PDF.** UISP's own web server answers the bare `/crm` with 301 → `…:8443/crm/` (even through Traefik) and `:8080` → `:8443`; uCRM's own e-mails and post-login redirect carry `:8443` because uCRM believes its address is `crm.dishnetuganda.com:8443` | MEASURED `--urls` U1, U4 |
| The PDF itself contains the old URL | **PENDING** — the re-run logins scan the document for links (hosts only). The plugin's own shipped invoice template (`ucrm_pdf_templates/invoice_uganda`) prints organization fields and no link; the template installed in uCRM may differ | CODE; scan pending |

**What the browser objects to (MEASURED U3):** port 443 presents Let's Encrypt for `crm.dishnetuganda.com`
(verify 0); port 8443 presents a **self-signed certificate for `localhost`** (verify 18). The warning can only
have come from a page on `:8443`; the PDF link inherits the page's origin. The plugin's generated links are on
443 since 5.18.34 (`crm_public_url` in `config.json` and the vault; both readers build without `:8443`); the
website links the 443 sign-in (3 links, 0 to uCRM's own login). Doors that still lead to `:8443`: the bare
`/crm` (cached 301), uCRM's own e-mails and redirects, old bookmarks. Fix location: docs/38 change set C, and
the plugin's canonical-host redirect in change set A. **Do not change the PDF endpoint**: cookie session,
account allow-list, ownership check, server-side fetch, `private, no-store`, 401 after logout.

## 8. Data Report — `main.lock` and "Needs Sync" (MEASURED 06:25, `--siblings`)

- **Records.** Customers: none of its own — customers exist only as `crm_client_id` on kits copied from
  Finance's register. Kits: `dr_kit_registry.json`, 4 (CRM #7, #47, #69 and one with no client), generated
  05:30:27 by "2.8.73" (the build is 2.8.80). Share links: the hand-off `public.php?clientId=…&token=…` (404 on
  Uganda — the verifier looks for the telecom plugin's database) and the admin "View as Client" (docs/36 §H).
  Starlink accounts: `dr_accounts.json`, 5, session material encrypted, never opened beyond the count.
- **Usage collector and cron.** `main.php` is a dispatcher behind `main.lock`, ticked every 30 minutes; it
  includes `cron.php` (Starlink sync, 60-min slot, 120-min floor), `cron_session.php`, `cron_backup.php`,
  `cron_orders.php`, `cron_invoice_details.php`, the auto-block sweep. Sync state: **every Starlink fetch in the
  log window was skipped — `5 total · 0 fetched · 1 no-cookie · 4 dead` on twelve consecutive runs**;
  `sl_usage.json` holds 14 historical rows for 2 kits.
- **`main.lock`.** The banner *"main.lock is stuck (previous dispatch crashed). Auto-sync is blocked."* is a
  **false alarm**: the dispatcher ran at every tick (04:00, 04:30, 05:00, 05:30, 06:00; 5 starts, 5 finishes,
  0 skips, 0 errors). The "hard-killed" line is printed by a 30-minute staleness rule on a lock whose
  timestamp advances only when the :00 tick re-creates it (the end-of-run refresh does not land: the 06:00:06
  stale line came 29 min 32 s after the previous run finished), on a dispatcher that ticks every 30 minutes — so
  the banner is on for roughly half of every hour while sync runs. "Clear cron.lock only" deletes a different
  file; "Nuclear reset" deletes both locks **and forces a sync** against dead sessions. **Press nothing.**
- **"2 Needs Sync · Active · no data".** The two Active registry kits with **no usage rows**: the kits of
  **CRM #7 and #47** (`--siblings` J2; the exact template rule text is captured by `--chain 1`, PENDING). They
  do **not** concern Family Shoppers, who has no kit in Data Report; they concern #7 and #47 — two of the four
  clients uCRM also marks inactive despite an active service. Their kits cannot gain usage rows while every
  Starlink session is dead. The remedy is re-authenticating the five accounts in Data Report's Sessions tab (an
  operator act), not a lock.

## 9. Starlink kit and account registration gap — why #1 has no kit anywhere

**Trace `uCRM starlinkDetails → kit register → Starlink account → Data Report → customer portal`:**

1. uCRM service 6 carries `starlinkDetails` (MEASURED). Whether its value is a KIT-pattern serial, and its
   masked form: PENDING `--chain 1` (C-1).
2. The hybrid's `KitAttributeIntake` reads it — but assigns only through `EquipmentAssignment::assign()`, which
   requires the serial to exist in `stock_units`; **that table is empty on Uganda** (0 rows, MEASURED), so the
   review screen can only report "not in stock". No one has received a Starlink kit into the hybrid's stock.
3. Finance's `sl_kits.json` holds 3 kits entered through Finance's own forms (`deploy_to_customer`, `sell_kit`,
   `add_purchase`, `fix_kit_links` — CODE, docs/37 §I.1.2). Nothing tells Finance what uCRM's attribute says;
   #1's kit was never entered there.
4. Data Report's `KitRegistryWriter` generates its registry from Finance's file (and reads `sl_kits.json`
   directly in its WiFi tab) → nothing for #1.
5. No kit in either register carries a Starlink account or service line; Data Report's service-line cache has
   17 of 19 lines without a kit number and none with a CRM id → the kit ↔ account link is absent for everyone.
6. Usage: Data Report's sessions dead; the hybrid's session store not imported → no rows for anyone new.
7. Portal: Equipment 0, Starlink and Sites N/A, WiFi no router, usage not implemented — measured on both routes.

**The break is at step 2 (stock), and it is structural, not a bug:** the hybrid's register refuses to invent
inventory, and the workflow that receives kits (Starlink order import or the Stock screen) has never been run
on Uganda. Everything downstream is empty by consequence. Which register should own the kit is §13.

## 10. Top architecture improvements

1. **One customer identity = the uCRM client id, everywhere** — hold; it already is for uCRM, the hybrid and
   Finance's copy. Retire Finance's copy as a *master* (keep it as a cache with a refresh time).
2. **One kit register** (§13) — the hybrid's `equipment_assignments` + `stock_units` as the store; the uCRM
   attribute as the human entry point; Finance and Data Report consume a published register.
3. **Move both siblings' live data out of the upgrade-deleted `data/`** (persistent `.<plugin>-data`).
4. **Data Report discovers the hybrid** (docs/36 §J): no hard-coded plugin names; the hand-off key beside it.
5. **Repair the alert, not the tick** in Data Report: staleness threshold ≥ 2 × tick, a refresh that lands,
   wording that says what was measured; alert on dead Starlink sessions, which today is only a cron counter.
6. **One usage path**: the hybrid's collector on the authoritative register, `app_usage` wired to it; Data
   Report's as fallback.
7. **Tenant currency as the only fallback** (`USD` defaults removed).
8. **Real-time vs cached vs eventual, stated in code and in the API** (`collected_at` on usage).
9. **Sibling access control** (docs/36 §H): gate "View as Client" and `dr_wifi_*`.
10. **Uganda branding in Data Report** from the same tenant record the hybrid uses.
11. **The tenant profile through the whole portal and the legal pages** (docs/38 A1.1, A2).
12. **One public origin for customers**: keep them off `:8443` (docs/38 C) and a canonical-host redirect for
    the customer pages (docs/38 A1.3).

## 11. Recommended implementation order (docs/38 §5)

| Order | Set | Needs from the operator | Unlocks |
|---|---|---|---|
| 1 | **A1** contacts, consent, canonical host | approval; the Sudan support number (A-1) | Help correct on Uganda; one consent per customer; bookmarks land on 443 |
| 2 | **C-1** Traefik absorbs the bare `/crm`; **C-2** uCRM's own address | `uisp.yaml` read; uCRM settings screenshot; approval | no `:8443` door from `/crm` or uCRM's e-mails |
| 3 | **A2** legal wording | six wording decisions (A-2) | Uganda Terms and Privacy |
| 4 | **B decisions** (§13 first) | B-1…B-6; staff receive and bind kits; session import; Data Report re-auth | Equipment, Starlink, WiFi, suspension and usage for Uganda customers |
| 5 | **B build** (hybrid, then siblings) | approval per part | `app_usage` real; one register published |

## 12. Items that require the operator's business decision

1. **A-1** South Sudan's "Call us" number (the portal shows 005, the profile says 006).
2. **A-2** the six Uganda legal wording points (identity sentence, governing law, **courts**, regulator
   sentence, any Uganda-specific clause, re-acceptance by existing customers).
3. **B-1** the authoritative kit register (§13) — the significant one.
4. **B-2** how kits enter inventory per source (Starlink order import vs Stock screen).
5. **B-3** Finance and Data Report consume the published register (their owners' approvals).
6. **B-4** the hybrid's collector as the portal's usage path, and who imports the Starlink session.
7. **B-5** the three kits Finance holds for #7, #47, #69: confirm ownership per kit before they are received
   and bound anywhere.
8. **B-6** re-authenticate Data Report's five Starlink accounts (operational).
9. **C-2** whether uCRM's server domain/port may be set to 443 (after the two confirmations in docs/38 C.2).
10. **`isActive`**: no rule; decided — unless a business rule "inactive clients may not sign in" is wanted.

## 13. Should the hybrid own the kit register while Finance and Data Report consume it?

**What the code already decided (CODE).** The hybrid's `equipment_assignments` is the authority for the two
things that act on a kit: the customer portal (`CustomerAccountService`) and suspension
(`StarlinkBlockService`: "sl_kits.json is no longer consulted for ownership at all"). It is DB-enforced (a
serial must be in stock; one live assignment per unit; one kit per uCRM service), audited, with
`release()`/`replaceUnit()` history. `KitAttributeIntake` treats the uCRM attribute as **input** and the
register as **store**, and reproduces Data Report's extraction exactly so the two never bind different kits from
the same text.

**What the practice is (MEASURED).** Staff have entered kits in **Finance**: 3 kits with CRM ids, kit type,
billing package and revenue, through Finance's own forms. **Nobody has entered a kit in the hybrid** (0 stock
units). Data Report's registry copies Finance. Finance's kits carry no service line, router or Starlink
account. Finance also keeps its own uCRM client copy and one PATCH helper towards uCRM (callers: PENDING
`--chain 1`).

**The three options, against the real workflows:**

| | O1 — hybrid owns; Finance and Data Report consume (docs/38 B-1) | O2 — Finance owns; the hybrid imports Finance's file | O3 — uCRM attribute as the human entry; hybrid as the store; siblings consume (O1 with uCRM as the door) |
|---|---|---|---|
| Fits the block workflow and the portal | yes — they already read the hybrid | no — the hybrid would have to create stock units from a JSON file, which its own rule forbids ("invent inventory"), or the workflows move to Finance's file | yes |
| Fits how staff work today | changes it: kits are received and bound in the hybrid | keeps Finance's forms | keeps the South Sudan habit (type the kit on the uCRM service) and Finance's finance fields; adds one review click in the hybrid |
| Integrity | DB constraints, history, actor | JSON in an upgrade-deleted directory, `.bak` twins, typed names; `fix_kit_links` exists because links went wrong | as O1 |
| Starlink account / service line | recorded on the assignment or in `KitSlMap` | absent on every Finance kit today | as O1 |
| Sibling impact | Finance's kit ownership becomes read-only; Data Report's writer reads the published file | none for Finance; hybrid importer to build; block workflow loses its enforced source | as O1 |
| Risk | staff must adopt the receive-and-bind step, or the register stays empty (as it is now) | ownership disagreements between three files, nothing enforced | same as O1, smaller |

**Recommendation.** **O3**, which is O1 with the entry point kept where staff already are: the kit is typed
once on the uCRM service (as in South Sudan), received into the hybrid's stock by the Starlink order import or
the Stock screen, bound on the review screen with one click, and published for Finance and Data Report. It
keeps the enforcement the block workflow depends on, does not create a second inventory, and does not ask
staff to abandon Finance — Finance keeps purchases, prices, billing packages and revenue and stops being asked
who owns a kit. **Validate before committing (read-only, one conversation and one run):** (a) ask the person
who deploys kits where they record a deployment today — Finance's "Deploy to customer", the uCRM attribute, or
both — and whether the three Finance kits also carry the attribute in uCRM; (b) `--chain 1` shows the
attribute's serial and whether Finance and Data Report hold it, and lists Finance's kit-writing actions and
its uCRM write helper's callers (if Finance already writes the attribute into uCRM, O3 is what the estate does
half-way); (c) a dry run for the three Finance kits: would the hybrid accept them — they need a stock receipt
first (`StarlinkOrderImport` if they came on a Starlink order, else the Stock screen), then a bind. If (a)
shows staff live in Finance and will not move, O2 is the honest alternative and the block workflow must then
be re-pointed at Finance's file with a validation layer — a larger and weaker change, stated so you can weigh
it.

---

*Pending for this document: the `--chain 1` run (C-1 attribute and invoice ids, C-3 Finance's link fields and
uCRM write callers, C-4 the "Needs Sync" rule and registry generator, C-5 the first break) and the two logins
re-run with the in-PDF link scan (§7 row five). The commands are in the message that delivered this document.*
