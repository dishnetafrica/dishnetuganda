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
| Usage | Starlink API via Data Report cron → `sl_usage.json` (**empty on Uganda**, docs/39) or hybrid's own hourly collector (`cron/starlink_usage.php`) | Data Report / hybrid | kit number, service line | portal `KitUsage` joins on `equipment_assignments`; the API `app_usage` is **hard-coded unavailable** | no usage shown; "0" never shown by design |
| Customer login | hybrid (`client_search_index`, OTP, `customer_sessions`) | hybrid | phone (last 9 digits) / e-mail → uCRM client id (`sub`) + `accounts` | uCRM contacts are the only source of the identifiers | a contact edit in uCRM reaches the index by webhook/delta |
| Suspension / block | uCRM service status → webhook → `StarlinkBlockService` / `StarlinkBlockBridge` → Data Report `dr_wifi_test_block` | uCRM (state), Data Report (execution) | client id → kits → router id | HTTP with `X-DishNet-Internal-Auth` | silent (caught) — SAFETY.md |
| Support | none in the plugin — static contacts (WhatsApp, phone, e-mail from the tenant profile) | — | — | — | tickets are uCRM-only and not shown |

## B. Identity map — Family Shoppers, CRM #1

| System | Presence | Evidence |
|---|---|---|
| uCRM | client #1, "Residential (up to 400 Mbps)", UGX 329,000/month, e-mail and phone on the record | operator's screenshot (REPOSITORY-external) |
| Hybrid | in `client_search_index`: the 05:40 e-mail sign-in matched exactly one account, eligible, and resolved to it | docs/35 §2 |
| Starlink Finance | **not among the three kits/customers it shows** (CRM #7, #47, #69) | operator's screenshot; **MEASURED** by `--siblings`: `sl_kits.json` holds exactly #7, #47, #69 (§I.1.1) |
| Data Report | **not among its clients** (the same three, plus one kit with no client at all) | operator's screenshot; **MEASURED** by `--siblings`: `dr_kit_registry.json` = #7, #47, #69 and one unassigned kit (§I.1.1) |
| Kit / Starlink account / usage | **none in either sibling register (MEASURED)**; the hybrid's own `equipment_assignments` for #1: PENDING (`--identity 1`) | §I.1.1 |

So the controlled customer exists in exactly two of the four systems, by design of the data rather than by
a defect: no kit, no Starlink account, no usage. The field-by-field comparison (name, e-mail, phone, service,
plan, price, invoices, payment status, state) between uCRM live and the hybrid's caches is what
`--identity 1` prints, with a **Same?** column computed on structured values and the source of truth per field.

## C. Synchronisation matrix (REPOSITORY; PENDING marks what the server run adds)

| Data | uCRM | Hybrid | Data Report | Finance | Truth | Movement |
|---|---|---|---|---|---|---|
| Customer id | native | copy (index, clients cache) | derived from kit/line records only | own cache | uCRM | webhook + 60 s delta → hybrid; **Finance refreshes its own 91-client copy from `public.php` at render time (MEASURED, §I.1.2)** |
| Name / e-mail / phone | native | copy — **phone and e-mail are the sign-in keys** | name typed into router map (`customer`) | typed into `sl_kits.json` | uCRM | as above; Finance/Data Report: manual |
| Service / plan / price | native | cache | live GET with its own app key | own cache | uCRM | staff sync actions, cron, webhook |
| Invoice / payment status | native | cache + on-demand refresh; `app_account` live | — | invoice export (its own) | uCRM | webhook → surgical refresh |
| Kit / serial | attribute "Kit Number" on the service (typed) | `equipment_assignments` (validated) | `dr_kit_registry` (generated from Finance) | `sl_kits.json` (typed) | **two registers, none authoritative for both readers** | `KitAttributeIntake` reads the attribute → assignment; Data Report regenerates its registry from Finance. **MEASURED: no kit in either register carries a service line or a Starlink account (§I.1.2)** |
| Starlink account | — | assignment column | `dr_accounts.json` | `sl_accounts.json` | Data Report (session) | its crons — **MEASURED: 4 of 5 accounts dead, 1 without a cookie, nothing fetched in the log window (§I.1.1)** |
| Usage | — | own collector (hourly) | `sl_usage.json` (empty on Uganda) | `sl_usage.json` (Finance copy, docs/38) | Starlink API | crons; the API endpoint never answers |
| Active / suspended | native (service status) | webhook-driven block | pause state (`wifi_test_block_state`, absent on Uganda per docs/39) | — | uCRM (state) / Data Report (execution) | webhook → bridge → `dr_wifi_test_block` |

## D. Current failures (confirmed unless marked PENDING)

**Critical** — none that exposes another customer's data on this host (docs/36 §0: the hand-off forgery is
not possible here).

**High**
1. **The installed Data Report is the South Sudan build** (docs/36 §I): hand-off dead (404 for every
   customer), "Back to Portal" link dead, auto-block admin alerts posted to a non-existent path.
2. **Data Report's Starlink synchronisation has nothing to log in with (MEASURED, §I.1.1).** Of its five
   Starlink accounts four are marked dead and the fifth has no session cookie; every fetch in the log window
   was skipped (`0 fetched` on twelve consecutive order runs; `skipped_dead_acct` 21 → 24 on the daily run).
   This — not a lock — is why two Active kits show "no data". Re-authenticating the accounts is an operator
   act inside Data Report; not done by this audit.
3. **The "main.lock is stuck (previous dispatch crashed). Auto-sync is blocked." banner is a FALSE ALARM
   (MEASURED, §I.1.1).** The dispatcher ran at every tick in the log window (5 starts, 5 finishes, 0 skips,
   0 errors). The banner and the log's "hard-killed" line come from a 30-minute staleness rule on a lock
   file whose timestamp advances only once an hour, on a dispatcher that ticks every 30 minutes; the banner
   is therefore on for about half of every hour while sync runs normally. Not unstuck by this audit — the
   buttons would not help, and one of them forces a sync (§I.1.1).
4. **Two kit registers, and neither knows the service line.** Finance's typed `sl_kits.json` (three kits) and
   the hybrid's validated `equipment_assignments` are both read as authority by different code; nothing
   reconciles them. **MEASURED (§I.1.2): no kit in `sl_kits.json` or in `dr_kit_registry.json` carries a
   service line, a router or a Starlink account**, while Data Report's `sl_svc_cache.json` has 19 service lines
   with 17 empty kit numbers and 0 CRM ids and its router map has 8 routers with 0 customers — the chain
   customer → kit → service line → router is broken at kit → service line in both registers.
5. **A third customer master.** Finance keeps `crm_clients_cache.json` — **MEASURED: 91 clients, its own copy
   of every uCRM client, refreshed by `public.php` at render time** — plus `crm_services_cache.json` (5) and
   `crm_starlink_reference.json` (3) (§I.1.2).
6. **Both siblings keep their live data in the directory uCRM deletes on upgrade** (docs/40 Q2). **MEASURED:
   `.dishnet-data-report-data` and `.dishnet-starlink-finance-data` are both absent**; the kit register, the
   router map, the Starlink session material and every cache live in `data/` (§I.1.2–3).

**Medium**
7. `app_usage` is hard-coded `unavailable: true` (a TODO); the portal's usage view has a source only through
   the hybrid's own collector, because Data Report's `sl_usage.json` holds 14 historical rows for two kits and
   cannot grow while every account is dead (§I.1.1; docs/39).
8. `app_plan` and `app_invoices` fall back to **`USD`** when a uCRM record carries no `currencyCode`
   (`api_customer_app.php`: `?? 'USD'`) — a South Sudan-era default; the tenant's currency should be the
   fallback. Whether any live record hits the fallback: PENDING (the walk prints the currency shown).
9. Sibling access control, pre-existing, separate track (docs/36 §H.2–H.3): "View as Client" trusts the URL
   for any session holder; `dr_wifi_*` handlers appear reachable without a session; the Sync Status page
   offers one-click destructive recovery (`dr_cron_nuclear_reset` forces a sync, §I.1.1).
10. Data Report's `dr_accounts.json` holds Starlink session material in the upgrade-deleted directory
    (docs/38; MEASURED present, 5 accounts, never opened beyond the count).
11. `sl_account_cycles.json` / `accounts.json` read by the hybrid do not exist on Uganda (docs/39).
12. Finance's `config.json` is empty (2 bytes) and `crm_invoice_export.json` is 141 days old (MEASURED,
    §I.1.2); Finance has **one PATCH helper towards uCRM** (`public.php:101–106`) whose callers were not
    captured — to be read before Finance is called read-only towards uCRM.

**Cosmetic**
13. The Data Report heads every page *"DISHNET AFRICA · JUBA, SOUTH SUDAN"* on the Uganda install; its
    generated registry names generator "2.8.73" on the 2.8.80 build.
14. Existing customers meet the consent step once on the web before the portal renders (by design; the
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

## G. Phases

- **Now (read-only):** run the four modes of `scripts/journey-audit.sh` (§H); record the results here.
- **Phase 1:** data-report redesign (docs/36 §J) including discovery, the hand-off key, Back-to-Portal, alert
  URL, branding; move sibling data to persistent directories; the false stale-lock alert replaced by a real
  one for dead Starlink sessions.
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

**Still PENDING:** `--identity 1`, `--login-phone`, `--login-email`, `--compare` (§H). §B's hybrid-side
row, §C's hybrid columns, §E's live scorecard and the final identity verdict wait for them.
