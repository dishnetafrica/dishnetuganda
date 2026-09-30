# 47 — Distribution management on uCRM and the DishNet plugin (audit and phased plan)

30 September 2026. **Audit only.** No code, migration, setting, uCRM record or server was touched, and nothing was
deployed. **Nothing below is authorised to build.** The plan in §14 waits for your approval, and the decisions in
§17 come first.

**What was audited:**
- **The plugin:** `dishnet-hybrid-sudan` at commit `fe6471e`, manifest version **5.18.54**. 5.18.53 is the version
  live on Uganda (root docs/07, 30 Sep entry).
- **uCRM:** only what the plugin sends to it and receives from it, and what earlier runs recorded on the Uganda
  server. The partners named in your brief are prospective, so nothing here assumes an agreement with any of them.

**Words used here.**
- **Partner:** a distribution company (a fuel-station chain, supermarket, shop, distributor or wholesale buyer).
- **Outlet:** one branch of a partner.
- **Partner user:** a person at a partner who signs in.
- **Staff:** DishNet's own people.
- **Warehouse officer:** your "warehouse operator". The word *operator* is reserved in this repository for the
  MikroTik HotSpot tenant (CLAUDE.md, vocabulary), so it is not used for anything else here.
- **Paths:** they are under `dishnet-hybrid-sudan/` unless they start with `root`. `docs/NN` alone means
  `dishnet-hybrid-sudan/docs/NN-*.md`, and `root docs/NN` means the repository's own `docs/NN-*.md`.

---

## Where the facts come from

| Tag | Meaning | What counts as evidence |
|---|---|---|
| **Code** | Read in the plugin at `fe6471e` | the `file:line` given. The key ones were re-read by hand for this report (§7) |
| **Server** | Measured on the Uganda server by an earlier run | the dated record cited. This session did not reach the server |
| **Doc** | A recorded project decision or design | the document and line cited |
| **PK** | Product knowledge about uCRM | what UISP CRM is generally known to do. Never observed on this install and not confirmed for 4.5.33. uCRM's documentation is refused by this session's network policy (root docs/45, "Three limits") |
| **NV** | Not verified | none of the above. Each NV names its check in §18 |

**Three limits:**
- **This session cannot reach the Uganda server or its uCRM.** Every server fact is an earlier, dated
  measurement.
- **uCRM is closed source and not in this repository.** Nothing here relies on uCRM internals or its database
  tables, only on its REST API as the plugin already uses it.
- **Everything about the plugin is from reading its code.** No test or server was run for this audit.
  - One finding is a possible data exposure: the collections CSV export, PD-1 in §7.2.
  - It is from code reading only, is **not reproduced**, and is reported so it can be checked and fixed on its own.

---

## In one page

**The platform can carry a distribution network, but not as it stands, and not by being bent.** uCRM stays the
master of customers, invoices, payments and credit notes. The plugin already has most of the machinery a
distribution ledger needs:
- **Stock:** serial-level units, bulk quantities, a movement journal, supplier purchases with costing, and the
  authoritative kit binding.
- **Controls:** an append-only financial audit trail, per-currency reporting that never invents a zero, a job
  scheduler with staff alerts, country gating, a hardened customer sign-in, and a deploy process with backups and
  rehearsals.

**What is missing is the distribution layer itself.** There are no partners, no outlets and no ownership of stock
separate from its location. There is no dispatch or receipt, no consignment, no partner prices, no dated commission
rules, no settlements, and no partner sign-in.

**Four findings shape the plan:**

1. **Partner users cannot safely sign in through anything that exists today.** uCRM's logins cannot isolate one
   partner's records from another's. The plugin's staff sign-in has server-side gaps that would expose
   distribution data, among them:
   - the JSON API ignores role permissions;
   - pages without a permission entry are open to any signed-in user;
   - a login answer returns the account's tokens, including any personal uCRM app key;
   - customer lookups take any client id.

   The partner surface must therefore be **a minimal, separate partner portal**, with its own identity and its own
   deny-by-default API. It comes after the staff side is fixed (§10, §14).
2. **uCRM has no place for an outlet and no partner price list.** uCRM should hold:
   - **one company client per partner legal entity**, for invoices, payments, credit notes and the balance;
   - its products and service plans as the catalogue.

   Outlets, locations, price lists, commission rules, consignment, settlements and approvals belong in plugin
   tables (§9). **uCRM organisations must not be used for partners.** They are the issuers of invoices, and
   Uganda has exactly one (§9.1a).
3. **The accounting architecture is a cash journal plus uCRM's sales ledger. There is no general ledger and no
   cost of goods sold anywhere.** Distribution can live inside that architecture without adding a ledger:
   - revenue is recognised only when uCRM invoices, which is never at a consignment dispatch;
   - what a partner owes is its uCRM balance;
   - cost of goods sold becomes computable, because a dispatched unit is linked to the invoice that sold it.

   Tax and fiscal invoices are **not** designable around. uCRM has no VAT configured, and the plugin refuses
   EFRIS production. That is a decision for DishNet's accountant, and a block on fiscal partner invoices (§12.3,
   §12.5).
4. **Several existing defects would be inherited by any distribution feature**, so they are fixed first (Phase 0):
   - duplicate serial numbers are possible (the index is not unique);
   - stock balances are silently clamped at zero;
   - stock rows can be hard-deleted;
   - migrations are marked applied even when a statement failed;
   - the first payment turns a residential client into a company client;
   - creating a partner client in uCRM would send it the retail welcome and invoice WhatsApps.

**Proposed order (§14):**
- **Phase 0** is foundations: security, migration safety, verifications and decisions.
- **Phases 1–4** follow your phases 1–4.
- **A staff-operated pilot** with one partner comes **before** the partner portal.
- **The portal, dashboards and outlet replenishment** come after that pilot.
- **Rollout** is last.

The reason for the reorder: the portal is the riskiest surface, and it should open onto a ledger that a pilot has
already reconciled.

---

## 1. uCRM version and plugin architecture (deliverable 1)

### 1.1 Versions

| What | Value | Tag and source |
|---|---|---|
| UISP / uCRM | **UISP 3.0.159, uCRM 4.5.33** (`ubnt/unms-crm:4.5.33`) | Server, 23 Sep 14:02 UTC — docs/120:234 |
| PHP that runs the plugin | **8.1.34** | Server — root docs/46:1159; SUDAN-EDITION.md:5-6 |
| Plugin | **5.18.54** in the repository; **5.18.53** live | Code — manifest.json; Doc — root docs/07 (30 Sep) |
| uCRM API the plugin uses | **v2.1** at `{ucrmLocalUrl}/api/v2.1`, header `X-Auth-App-Key` | Code — lib/CrmApiClient.php:59-75 |
| Minimum-version key in the manifest | spelt `ucrmVersionCompliability`, min 2.14.0 | Code — manifest.json:9-12. Whether uCRM honours the misspelt key is NV (docs/98 M-1) |

- **Checking the version at run time:** nothing does. `testConnection()` reads `GET version` only to test the
  connection (lib/CrmApiClient.php:339-363).
- **Stale comments:** a few say "PHP 7.4" (public.php:8; SAFETY.md:46). They are wrong. The server runs 8.1.34, and
  releases are linted under 8.1 (root docs/evidence/5.18.54/php81/).

### 1.2 What uCRM lets a plugin be — the contract

Measured from this plugin and its sibling (docs/98 §2), and confirmed by this plugin's code:

| Extension point | How this plugin uses it | Limit that matters here |
|---|---|---|
| `manifest.json` | name, version, 38 settings, **one admin menu item, shown in an iframe** (manifest.json:20-26) | No client-zone menu item exists. **Whether 4.5.33 supports one is NV** (docs/98 §2.10) |
| `main.php` | runs on uCRM's tick, about every 5 minutes (`executionPeriod` 5), and calls `cron/master.php` | No daemon. Background work is scheduled jobs inside a 300-second budget (cron/master.php:101-105) |
| `public.php` | the **only** file uCRM serves. It routes `page=` and `tab=` (122 tabs, public.php:2626-2764) | Every screen, API and webhook enters here |
| `ucrm.json` | written by uCRM: `ucrmLocalUrl`, `ucrmPublicUrl`, `pluginAppKey`, `pluginDataDir` | The app key is uCRM-wide. **It must never reach a browser** (§10) |
| REST API v2.1 | dozens of read and write call sites (§12.1) | No idempotency keys and no rate-limit handling (lib/CrmApiClient.php:438-472). Several payloads are refused on 4.5.33 (§12.4) |
| Webhooks | 25 events registered automatically (lib/WebhookRegistrar.php:50) and handled in webhook.php | Payloads are treated as "a doorbell, not evidence": each entity is read again from uCRM (webhook.php:555-592) |
| Data directory | SQLite `plugin.sqlite3` (WAL) and JSON files (lib/SqliteStore.php:145-161) | **No PostgreSQL, no row-level security.** Isolation inside the plugin can only be enforced in PHP (§10.3) |

**No uCRM core source is in this repository, and none is needed.** Nothing proposed here writes to a uCRM table.
Every uCRM effect goes through the REST API (§12).

### 1.3 How the plugin is built

**It is not a Laravel application, and a new framework application is not needed.** It is plain PHP with zero
dependencies: no Composer and no SDK (lib/MigrationRunner.php:40; tests/run.sh:2-3).

| Layer | Where | Notes |
|---|---|---|
| Entry and routing | `public.php` | Sets up the store, sign-in and RBAC (467-492). The pre-HTML routes include `includes/routes.php` (739). The JSON API is `?page=api` → `includes/api_handlers.php` (885-924). Form posts go to `post_handlers.php` (927-942). Tabs are dispatched at 2916-3010 |
| Services | `lib/*.php` | Among them StockService, PurchaseService, CashbookService, ReportingService, FinAudit, CrmApiClient, KycService, KitAttributeIntake, AlertService, TenantProfile, CustomerSession |
| Scheduler | `cron/master.php` | 50 jobs, a lock, a slot claimed per job, and gating per country (cron/master.php:121-297, 319, 343-381) |
| Webhooks | `webhook.php` via `?page=crm_webhook` | Checked with `crm_webhook_key` (webhook.php:689-697) |
| Storage | `lib/SqliteStore.php` | A JSON "file" is a table `(id, data)` (22-27). Real SQL tables come from migrations (stock, purchases, ledger, audit) |
| Migrations | `migrations/*.sql` | 76 files, the highest `076_job_notify_email.sql`. Recorded in `_migrations` and **run on every `SqliteStore::create()`** (lib/SqliteStore.php:233-248) |
| Settings | `PluginConfig`, `ConfigVault` | Merged from three JSON sources plus a vault file with mode 0600 (lib/PluginConfig.php:85-86; lib/ConfigVault.php:4-31) |
| Country gating | `TenantProfile`, `NotifyGate`, `StaffJobsGate` | Uganda is selected by UGX (lib/TenantProfile.php:73-81). New behaviour is gated to Uganda, and South Sudan keeps its behaviour, proved by a golden test (lib/NotifyGate.php:52-58) |

### 1.4 The sign-in mechanisms that exist

| Who | Mechanism | Evidence | Fit for partner users? |
|---|---|---|---|
| uCRM staff | uCRM's own login | PK | **No.** Nothing in this repository shows a uCRM admin user can be limited to some clients. It would also open uCRM's own screens to a partner. NV-2 checks it in one screen |
| uCRM clients | uCRM client zone | PK | **No** for stock and sales: a client-zone login sees one client's own billing. A plugin page there is NV (docs/98 §2.10) |
| Plugin staff | `retailers.json` + `RetailerAuth` (bcrypt, PHP session, 90-day bearer tokens stored in plain text) + RBAC from migration 032 | lib/RetailerAuth.php:42-124, 237-268; lib/RbacService.php:110-118 | **No.** Its gaps are listed in §7.2 (PD-2 to PD-9) |
| Customers | Customer portal: OTP, then a session row that can be revoked, in an HttpOnly cookie, with a CSRF header rule | lib/CustomerSession.php:1-40; migrations/073_customer_sessions.sql | **Its design, yes. The same accounts, no.** It is the model for the partner portal's sessions (§10.3) |

---

## 2. Repository and deployment structure (deliverable 2)

### 2.1 Repository

| Path | What it is | Relevant here |
|---|---|---|
| `dishnet-hybrid-sudan/` | **The uCRM plugin.** Installed on Uganda as "DishNet Hybrid — Sudan Edition" | Everything proposed lives here |
| `dishnet-hybrid-sudan/dishnet-mikrotik-control-plane/` and `dishnet-hybrid-sudan/docs/` | **Domain B:** the MikroTik HotSpot control plane. A separate PostgreSQL service, **not** a uCRM plugin (docs/98 §15) | **Out of scope.** Partners must not be modelled there (§10.5) |
| `root docs/` | Change control (root docs/07) and audits (root docs/27–46) | This report is root docs/47 |
| `root scripts/` | Pinned `deploy-5.18.xx.sh` scripts and their rehearsal harnesses | The deploy path for every phase (§16) |
| `dishnet-ai/`, `dishnet-mail/`, `dishnet-web-uganda/` and others | The sibling plugin, mail stack and website | Not touched. The website calls the plugin's public pages (prices, shop, sign-in, web chat), and none of them changes |
| Not in the repository | `dishnet-starlink-finance` (keeps its own kit register, `sl_kits.json`) and `dishnet-data-report` | **Risk R-7:** a third kit master. Decision B-1 names the plugin's register as authoritative (root docs/38:216, 445) |

### 2.2 How a release reaches Uganda (Code and Doc)

1. On the server, as root, the DishNet engineer runs `cd /opt/dishnet && git pull origin claude/study-this-jhe2eg`
   (root DEPLOY.md:17).
2. The pinned `root scripts/deploy-5.18.xx.sh` then runs:
   - It refuses unless the plugin commit is the one it was written for (for example, `EXPECTED_PLUGIN_COMMIT` in
     root scripts/deploy-5.18.54.sh:62).
   - It records before-evidence.
   - It **backs up** the plugin's databases with `VACUUM INTO` and an integrity check, and tars the data
     directories, the installed plugin and the vault (root scripts/deploy-5.18.54.sh:443-534).
   - It waits for a typed `DEPLOY`.
3. `root scripts/deploy-hybrid.sh` then:
   - finds uCRM's `/data` mount with `docker inspect ucrm`;
   - copies the plugin with `tar`, **excluding `./data` and `./.git`, and never deleting a file**;
   - writes `.deployed-commit`, restores the owner, and reads the commit back from inside the container.
4. Copied files are given the copy time (`touch -c`), so OPcache recompiles them (root docs/44 §16.23).
5. The script runs its after-checks, then prints the **rollback as a separate command** that needs a typed
   `ROLLBACK`. Rollback and deploy are never handed over together (CLAUDE.md, "Always").
6. **Every script is rehearsed first** against a fake Docker, in a harness that refuses to run on the server
   (root scripts/harness/deploy-5.18.54/rehearse.sh:22-24).

**Four properties that bind the distribution work:**
- **Migrations run by themselves** on the first request or tick after the copy (lib/SqliteStore.php:233-248). A
  deploy cannot stage a migration separately from the code.
- **A rollback restores code, not schema.** The copy never deletes, and migrations only add. So every migration
  here must be additive, and older code must ignore the new tables. Migration 073 is the precedent: "Additive: older
  code ignores the table" (migrations/073_customer_sessions.sql:4-6).
- **The migration runner marks a file applied even if a statement failed** (lib/MigrationRunner.php:242-248). A
  unique index that fails to build would vanish silently. That is the reason for Phase 0 item 0.4.
- **A change to the manifest or the settings schema also needs the ZIP**, uploaded through uCRM (build-zip.sh:5-8;
  root DEPLOY.md:71-76). A distribution feature should avoid manifest changes, and so needs no new menu item (§10.3).

**Found on the way, not changed:** the tar copy also ships `docs/`, `tests/` and `dishnet-mikrotik-control-plane/`
into the served plugin directory (root scripts/deploy-hybrid.sh:120-121). That is harmless today, since uCRM serves only
`public.php`, but it is more than the plugin needs.

---

## 3. Capability matrix

The statuses are yours:
- **Reuse** = Existing — reuse.
- **Extend** = modify an existing component.
- **Missing** = a new plugin capability.
- **Blocked** = needs a uCRM limitation resolved.
- **Decision** = needs business approval.

A row with two statuses lists the one that governs first. The last column answers your question for every
proposed change: **why the existing implementation cannot already do it.**

### A. Partner management

| # | Requirement | Status | Component | Evidence | Why not already satisfied |
|---|---|---|---|---|---|
| A1 | Partner record: code, type (corporate retail / authorised reseller / regional distributor / wholesale customer), category, status | **Missing** | new `dist_partners` (§9.2) | Code — lib/RbacService.php:110-118 | No partner entity exists. The only accounts are staff and "Dealer" users in `retailers.json`, and a Dealer is a *person with a wallet*, not a company with outlets (migrations/032_rbac_system.sql:159) |
| A2 | Legal name, company registration, TIN | **Extend** | uCRM company client (`clientType` 2, `companyName`, `companyTaxId`, `companyRegistrationNumber`) | Code — lib/KycService.php:676; lib/KycCrmSync.php:425; lib/UcrmLeadSync.php:304; lib/FtthCrmService.php:116; lib/EfrisInvoiceMapper.php:94-107 | uCRM has the fields, but the plugin **never creates a company client**: every create sends `clientType` 1. The company fields are only ever *read*, as the EFRIS fallback |
| A3 | Contacts and authorised representatives | **Extend** | uCRM client contacts + an authority flag in the plugin | Code — lib/CustomerIdentityService.php:314 | Contacts travel only inside client payloads (no `client-contacts` endpoint is used). uCRM has no notion of "may approve a settlement" |
| A4 | Agreement, expiry, approved commercial terms | **Missing** | new `dist_agreements`; the signed copy as a uCRM client document | Code — lib/CrmApiClient.php:378-434 (document upload exists) | Nothing stores an agreement, a term or an expiry |
| A5 | Pricing and commission rules | **Missing** | C4, F6 | — | See C4, F6 |
| A6 | Credit limit and payment terms | **Missing** | fields on `dist_agreements`; checked before dispatch | Code — tabs/sales/collect_payment.php:134-146; includes/api/api_lte_admin.php:403-465 | Only a *staff* carry limit and the BlueCard LTE limit exist. The plugin writes no per-client billing terms (only a quote's `invoiceMaturityDays`, lib/QuotationService.php:649) |
| A7 | Assigned DishNet account manager | **Missing** | field on `dist_partners` → staff account | — | No such link exists |
| A8 | Partners distinct from retail customers, in data and UI | **Extend** | plugin flag + a uCRM client attribute `dnPartnerCode`; partner clients excluded from retail flows | Code — webhook.php:808-861 (`client.add`: welcome WhatsApp, duplicate alert, mailbox); webhook.php:934 (`invoice.add`: WhatsApp + PDF + e-mail); lib/KycService.php:481-615 (KYC blocks on a phone match) | **Today a partner's uCRM client would be treated as a retail customer:**<br>• it gets the retail welcome message and every invoice WhatsApp;<br>• its contact's phone would block a real customer's KYC registration.<br>Nothing tells a partner from a customer |
| A9 | Reuse uCRM clients or organisations; no duplicate identities | **Reuse** (clients) / **Decision** (organisations: no) | one company client per partner legal entity | Server — root docs/30:29 (Uganda has one organisation, id 1); Code — lib/FtthCrmService.php:55 | A uCRM organisation is DishNet's own invoicing entity (numbering, bank details), not a customer grouping. The South Sudan precedent (Org 7, "FTTH Project") is hard-coded, and Org 7 does not exist on Uganda |

### B. Corporate hierarchy and branches

| # | Requirement | Status | Component | Evidence | Why not already satisfied |
|---|---|---|---|---|---|
| B1 | One partner, many outlets | **Missing** | new `dist_outlets` (+ optional `dist_regions`) | PK — uCRM clients have no parent link, and no call the plugin makes uses one (§12.1) | uCRM can represent a *billing* entity, not a tree. An outlet as its own uCRM client is right only if it is billed separately (**D-5**) |
| B2 | Unique outlet code, parent, address, contact, manager, users, status | **Missing** | `dist_outlets`, `dist_partner_users` | — | Nothing exists |
| B3 | Separate stock and sales per outlet | **Extend** (stock) / **Missing** (sales) | a stock location per outlet; `outlet_id` on every sale | Code — migrations/036_stock_tables.sql (`stock_quantities` unique per category and location) | Stock is already keyed by location, but no location can name an outlet: the location types are `warehouse`, `field_agent`, `customer`, `transit` (lib/StockService.php:36) |
| B4 | Head office sees all its authorised outlets | **Missing** | reports by partner and outlet (§9.9) | Code — lib/ReportingService.php:92-113; Doc — root docs/28:177-181 | ReportingService has no seller, branch or location dimension |
| B5 | Branch users see only their outlet's permitted records | **Blocked** (uCRM logins, current staff login) → **Missing** (partner portal) | §10 | Code — includes/api_handlers.php:94-98; public.php:2766 | See §10.2. Neither existing login can confine a user to one outlet |

### C. Products and pricing

| # | Requirement | Status | Component | Evidence | Why not already satisfied |
|---|---|---|---|---|---|
| C1 | Starlink kits, routers, accessories as sellable products | **Reuse** + **Extend** | uCRM products; a link from stock categories to uCRM products | Code — prices.php:61-62; includes/api/api_products_admin.php:654-881; migrations/036_stock_tables.sql (`stock_categories`: no product id) | Products exist in uCRM, but a stock category cannot say which uCRM product it is, so a dispatched unit cannot be invoiced as the right product |
| C2 | Data plans and service activation | **Reuse** | uCRM service plans and services | Code — lib/DishNetTools.php:399; lib/KycService.php:1985-1990 | Satisfied. Plans are read from uCRM. Services are created **by staff** in uCRM (the plugin never creates one) |
| C3 | Fibre and installation services | **Reuse** (NV which exist on Uganda) | uCRM products and plans | Code — lib/StockService.php:38 (`fiber` is a stock service type) | Satisfied if uCRM holds them. NV-12 checks the catalogue |
| C4 | Partner wholesale prices, approved retail rules, volume tiers, effective dates | **Missing** | new `dist_price_lists`, `dist_price_list_items` | PK — a uCRM product carries one price; Code — migrations/036 (`stock_categories.sell_price`), tabs/sales/subscription_plans.php:10-12 | Every existing price is a **single current number**: the uCRM product, the stock category, the JSON catalogues. There is no price per partner, no tier and no date |
| C5 | No hard-coded prices or commission rates | **Extend** | the distribution module reads rules from tables only; **no default rate** | Code — public.php:506-509, 523-529 (global `commission_rate` 5 and per-service rates, as defaults) | The existing commission engine falls back to hard-coded 5 % defaults. The new module must never read them |
| C6 | One-off hardware kept apart from recurring subscriptions and recurring commissions | **Reuse** + **Missing** | uCRM products (one-off) vs services (recurring); a commission `basis` field | Code — lib/KycService.php:951-952 (hardware inside KYC; invoice later) | Hardware and service are already separate *objects* in uCRM. No commission record separates them yet |
| C7 | No change to uCRM's own pricing behaviour | **Reuse** (as a rule) + **Decision** | the module writes explicit prices on the invoices it creates; it never writes a uCRM product price | Code — includes/post/post_sync.php:420-476; tools/hardware_diff.php:23-36 | The plugin's plan and device screens **already push prices into uCRM**. That makes a second price writer. The distribution module must not become a third (R-8) |

### D. Inventory and stock distribution

| # | Requirement | Status | Component | Evidence | Why not already satisfied |
|---|---|---|---|---|---|
| D1 | DishNet central warehouse | **Extend** | new `stock_locations` | Code — lib/StockService.php:43-47 | The only warehouses are two hard-coded **South Sudan** names ("DishNet UNMISS", "DishNet Kololo Office") |
| D2 | Partner and outlet locations | **Extend** | `stock_locations` kinds `partner_warehouse`, `outlet` | Code — lib/StockService.php:36 | No location type can name a partner or an outlet |
| D3 | Dispatch and receipt confirmation | **Missing** | new `dist_dispatches` + lines | Code — lib/StockService.php:37 (movement types) | `transfer` moves stock in one step. Nothing sends and then waits for the other side to confirm |
| D4 | Stock in transit | **Extend** | the `transit` location, bound to a dispatch | Code — lib/StockService.php:36 | `transit` is declared and never used |
| D5 | Serial numbers and Starlink kit identifiers | **Extend** | a normalised serial, **unique**; `equipment_assignments` stays the kit binding | Code — migrations/036:48 (non-unique index); migrations/068_equipment_assignments.sql:66-85 (live-unique unit, service, kit, terminal, router, service line); Doc — root docs/38:216 (B-1: this register is authoritative) | Two stock units can carry one serial today. The *binding* to a customer is already unique, and stays the authority |
| D6 | Ownership apart from custody | **Missing** | owner fields on units and bulk balances | Code — migrations/036 (`stock_units` has location and assignee, no owner); lib/SimService.php:99-150 (SIMs carry `owner_org_id`, the only precedent) | Consignment is exactly "DishNet owns it, the outlet holds it". Nothing can say that |
| D7 | Returns and damaged goods | **Extend** | a return document (RMA) over the existing statuses | Code — lib/StockService.php:35 (`returned`, `damaged`, `written_off`) | The states exist. No return document, approval, inspection or credit exists |
| D8 | Adjustments and reconciliation | **Extend** | adjustments only by approved request; stock counts | Code — lib/StockService.php:668-672 (bulk clamped at zero); :621 (unit hard-deleted); includes/api/api_stock.php:141-150 (`stock_install` with no role check) | A negative balance is silently set to 0, and a unit can be deleted. Stock actions check the role weakly, and nothing needs a second person |
| D9 | Replenishment requests and low-stock alerts | **Extend** | per-location minimum and maximum levels; an alert job; outlet requests | Code — lib/StockService.php:1171-1187 (low stock shown on screen only); lib/AlertService.php:52 | The minimum is per category, not per location. No job alerts anyone |
| D10 | The five transaction types | **Missing** | `txn_type` on movements and dispatches | Code — lib/StockService.php:37 | No existing movement type tells a wholesale sale from a consignment transfer from a retail sale |
| D11 | A consignment dispatch never makes a sale or revenue | **Missing** (a rule, with a test) | §11.2 | Code — webhook.php (0 references to stock) | Stock and invoices never meet today. The rule must hold once they do |
| D12 | Every movement auditable; no duplicate serial; no unauthorised adjustment | **Extend** | triggers that make movements append-only; unique serial; server-side permission checks | Code — migrations/036 (comment "Immutable audit trail", but no trigger); a code search found no UPDATE or DELETE of `stock_movements` outside the tests | The journal is append-only by habit only. It can be made so by constraint, and nothing that exists breaks |
| D13 | EFRIS stock (T131) | **Decision** / **Blocked** | EFRIS goods, if ever, driven from the stock journal | Code — lib/EfrisGoodsStore.php:8-16; lib/EfrisClient.php:18 | EFRIS keeps a **separate** stock counter, and production calls are refused |

### E. Sales and uCRM customer integration

| # | Requirement | Status | Component | Evidence | Why not already satisfied |
|---|---|---|---|---|---|
| E1 | Partner purchase orders | **Missing** (+ **Reuse** uCRM quotes as a proforma) | new `dist_orders` | Code — lib/EmailReplyPolicy.php:50-58 (a PO by e-mail is only flagged); lib/QuotationService.php:509 (quotes pushed to uCRM work today) | There is no order object |
| E2 | Outlet retail sales: product, quantity, price, taxes, serial, receipt and payment reference | **Missing** | new `dist_retail_sales` + lines | — | Hardware is sold only *inside* a KYC registration (lib/KycService.php:955-988). There is no record of a sale by someone else |
| E3 | Customer details where needed; link to the uCRM client | **Extend** | an outlet sale carries contact details; **linking** is done by staff through the existing KYC and identity path | Code — lib/KycService.php:481-615; lib/CustomerIdentity.php:127-190 | The dedupe exists (phone, last 9 digits; ambiguous numbers refused) but only for KYC and leads |
| E4 | Link connectivity to client, plan, service, activation | **Extend** | new `dist_activation_requests` → existing KYC → staff create the service → `KitAttributeIntake` binds the kit | Code — lib/KitAttributeIntake.php:6-37; migrations/068 | The binding path exists and refuses unsafe cases, but nothing starts from a partner's sale |
| E5 | No duplicate customer or subscription | **Reuse** | KYC blocks a known phone; `equipment_assignments` keeps one live kit per service and per customer | Code — lib/KycService.php:607-615; migrations/068_equipment_assignments.sql:66-85 | Satisfied, provided partner sales **enter through these paths** and never create uCRM clients themselves |
| E6 | No automatic activation from a partner's hardware sale | **Reuse** (the plugin never creates a service) + **Missing** (the request workflow) | `dist_activation_requests` | Code — lib/KycService.php:1985-1990; webhook.php:1570-1634 (activation only notifies) | Satisfied by today's behaviour. The workflow must keep it so, and a test must pin it |
| E7 | Returns, cancellations, credit notes | **Extend** | a sale can be cancelled until it is settled; after that, a uCRM credit note | Code — includes/api/api_payments_admin.php:520-531 (`billing/credit-notes`, `tax1Id` null) | Credit notes are written today for customers only. Doing it for a partner invoice is NV-4 |

### F. Partner settlement and commissions

| # | Requirement | Status | Component | Evidence | Why not already satisfied |
|---|---|---|---|---|---|
| F1 | Wholesale: invoice and payment through uCRM | **Extend** (NV) | a partner invoice created by the plugin through the API, with explicit line prices | Code — lib/FtthCrmService.php:200-222 | The plugin's **only** invoice write is the South Sudan wallet top-up (`organizationId` 7, `taxable` false). Whether `POST invoices` works on Uganda's 4.5.33 is **NV-3** |
| F2 | Outstanding invoices and credit limits | **Extend** | the uCRM balance + unbilled consignment exposure, against the agreement's limit | Code — includes/post/post_field.php:903; webhook.php:338 (`accountBalance`, `accountOutstanding` read) | The balance is readable. No partner limit exists (A6) |
| F3 | Consignment: DishNet owns until the agreed sale event; units sold and unsold; commission; payable to DishNet | **Missing** + **Decision** | ownership (D6) + retail sales (E2) + settlements (§9.8) | — | Nothing exists. *What* the sale event is, is **D-2** |
| F4 | Reconcile reported sales with stock and customer records | **Missing** | a reconciliation job and report | — | Nothing exists |
| F5 | Partner statements and settlement reports | **Missing** | `dist_settlements` + a statement built from uCRM invoices and payments | Code — tabs/accounts/accounts_settlement.php:27-70; tabs/accounts/ops_settlement.php:14 | The existing "settlements" are a staff daily cash sheet and a JSON snapshot. No money moves and no partner is involved |
| F6 | Commission by product or service; effective-dated rules | **Missing** | `dist_commission_plans`, `dist_commission_rules`, `dist_commission_accruals` | Code — includes/post/post_field.php:774-795 (percent of a *collection*, at *today's* rate); includes/api/api_lte.php:629-658 (reports recompute at current rates) | The existing engine pays staff on collections. It has hard-coded defaults, and `flat` is never implemented: any type but `none` is treated as a percentage. Nothing is dated and nothing has tests. **It is not reused for partners and is left as it is** |
| F7 | Partner receivables and payment allocation | **Reuse** (uCRM) + **Extend** | uCRM balance; payments applied to invoice ids | Code — includes/api/api_retailer.php:143-149 (payments carry `invoiceIds`) | uCRM already keeps the receivable. Partner money must **not** pass through the field-collection screens, which credit a wallet and a staff commission (includes/post/post_field.php:758-795) |
| F8 | Credit notes and returns | **Extend** | as E7 | — | As E7 |
| F9 | Overdue settlement alerts | **Missing** | a scheduled job + `AlertService` | Code — cron/cash_carry_reminder.php (the precedent); lib/AlertService.php:52-86 | No such job exists |
| F10 | Approval of exceptions and adjustments | **Missing** | `dist_approvals`, where the approver can never be the requester | Code — lib/CashbookService.php:2297-2306 (the cashbook's approve and reject write no audit row) | No second-person approval exists anywhere in stock or commissions |
| F11 | Which records belong in uCRM and which in the accounting integration | **Decision** | §12.2 | — | Answered in §12.2, for approval |
| F12 | No separate ledger; no duplicate invoice or payment | **Reuse** + **Extend** | `createPaymentSafe`; a claim-before-write record for every uCRM write | Code — lib/CrmApiClient.php:151-278; cron_sync.php:225-295, 299-387; cron/crm_payment_retry.php:39-84; cron/payment_catchup_sync.php:160 (F-25) | uCRM offers no idempotency key. Payment retries already overlap in four paths (R-10). Every new uCRM write needs its own claim (§9.10) |
| F13 | Stock valuation, cost of goods sold, commissions, taxes, revenue recognition | **Decision** + **Extend** | §12.3 | Code — lib/PurchaseService.php:260-328, 484-500; lib/ReportingService.php:281-301; lib/CashbookService.php:708-713 | Valuation exists. Cost of goods sold exists nowhere. The profit and loss is cash-basis. VAT is not configured |

### G. Permissions and access control

| # | Requirement | Status | Component | Evidence | Why not already satisfied |
|---|---|---|---|---|---|
| G1 | Partner sign-in through uCRM | **Blocked** | — | PK; docs/98 §2.10 | No evidence that uCRM can confine a login to some clients' records. A client-zone plugin page is NV |
| G2 | Partner sign-in through the plugin's staff login | **Blocked** until Phase 0, and **not recommended** even after | — | §7.2 PD-2 to PD-9 | Its server-side gaps would expose other partners' records |
| G3 | The eight roles | **Extend** (four staff roles through RBAC) + **Missing** (four partner roles in the portal) | §10.1 | Code — lib/RbacService.php:110-118, 134-209 | There is no warehouse role and no channel-manager role. No partner role can exist in the staff store safely |
| G4 | Server-side checks at every endpoint | **Extend** | one registry of distribution actions; a test that enumerates it | Code — includes/api_handlers.php:94-98; public.php:2766 | The API's permission check ignores RBAC, and a page with no entry is open to anyone signed in |
| G5 | No privileged uCRM credential in a browser | **Extend** | the portal holds none; staff pages stop echoing tokens | Code — public.php:1683 (a bearer token in a meta tag); includes/api/api_public.php:6-19 + lib/RetailerAuth.php:368-373 (the login answer returns the whole account, minus only the password) | Already violated on the staff side: see PD-3, PD-4 |
| G6 | A minimal, separate partner portal | **Missing** + **Decision** (hosting and sign-in, **D-9a**, **D-9b**) | §10.3 | — | Required by G1 and G2 |

### H. Reports and dashboards

| # | Report | Status | Source | Why not already satisfied |
|---|---|---|---|---|
| H1 | Sales by partner, outlet, product, period | **Missing** | outlet sales + uCRM partner invoices | No such dimension exists |
| H2 | Gross margin and commissions | **Extend** | the unit cost recorded when a unit changes owner + the invoice line | `grossMargin()` reports "not available" because units are not linked to invoice lines (lib/ReportingService.php:281-301). A dispatch links them |
| H3 | Stock by warehouse, partner, outlet | **Extend** | location balances | Balances are keyed by location (lib/StockService.php:112) but no report reads them |
| H4 | Stock in transit and stock ageing | **Missing** | dispatch dates; movement dates | — |
| H5 | Consignment stock and unremitted sales | **Missing** | ownership + unsettled sales | — |
| H6 | Outstanding invoices and overdue settlements | **Extend** | the uCRM invoice cache for partner clients; settlement due dates | The overdue workbench is retail-only (includes/api/api_crm_misc.php:3870-3910) |
| H7 | Partner collections and payment history | **Extend** | uCRM payments of partner clients | Only retail and staff collections are reported today |
| H8 | Outlet performance and dormant outlets | **Missing** | sales per outlet; days since last sale | — |
| H9 | Hardware sold against activations | **Missing** | serial sold → activation request → `equipment_assignments` | — |
| H10 | Returns, discrepancies, adjustments | **Extend** | movements of those types + receipt variances | The movement types exist, the variance documents do not |

**Every report** goes through ReportingService's figure rule: `{value, currency, basis, complete, caveat}`, never
summed across currencies, and "not available" rather than a made-up 0 (lib/ReportingService.php:24-38, 74-84).
**Every CSV export** also neutralises cells that begin with `=`, `+`, `-` or `@`, which no export does today.

### Integration requirements (brief §4)

| # | Requirement | Status | Evidence |
|---|---|---|---|
| I1 | Currency validation and central formatting | **Reuse** | lib/currency.php (`dn_cur`, `dn_money`, `dn_code`, `dn_book_base`, `dn_entry_currency`, `dn_payload_currency`); tests/test_currency_sweep.php, tests/test_cashbook_currency.php |
| I2 | Tenant and country controls | **Reuse** | a `DistributionGate` in the pattern of lib/NotifyGate.php:52-58: true on Uganda only; South Sudan unchanged, proved by golden tests |
| I3 | Audit conventions | **Reuse** | `FinAudit::record()` (lib/FinAudit.php:57), append-only and test-enforced (tests/test_fin_audit.php:124-136) |
| I4 | Scheduled jobs and staff alerts | **Reuse** | cron/master.php (never an interval of exactly 300, never `exit()`: SAFETY.md:111-129); `AlertService::notify()` |
| I5 | Accounting integration | **Reuse** + **Decision** | uCRM (sales ledger) + the plugin cashbook, purchases and bank import (§12.2). **There is no external accounting package**: a search for QuickBooks, Xero, Sage, Odoo, Zoho Books and Tally finds nothing |
| I6 | Tests and deployment | **Reuse** | tests/run.sh; 34 fixtures, among them a fake uCRM; weakened-copy checks; pinned deploy scripts with rehearsal harnesses |
| I7 | Safe migrations | **Extend** | lib/MigrationRunner.php:242-248 (applied even on error) → a strict mode for new files (Phase 0) |

---

## 4. Existing functionality to reuse (deliverable 3)

Each item below is used **as it is**. Where a small change is needed, it is listed in §5 instead.

| Component | What it gives distribution | Evidence |
|---|---|---|
| **uCRM company client** | The partner's billing identity: legal name, TIN, registration, contacts, invoices, payments, credit notes, balance | lib/EfrisInvoiceMapper.php:94-107 (company fields read); includes/post/post_field.php:903 (balance read) |
| **uCRM client documents** | The signed agreement, filed on the partner's client record | lib/CrmApiClient.php:378-434 |
| **uCRM custom attributes** | A visible `dnPartnerCode` on the partner's client. The plugin already created the EFRIS attributes on Uganda | tools/efris_setup_attributes.php:33-50; Server — root docs/30:30 (client fields 1–4 are EFRIS) |
| **uCRM products, service plans, services** | The catalogue for hardware and plans; subscriptions stay services | prices.php:61-62; lib/DishNetTools.php:399 |
| **uCRM quotes** | A proforma or order acknowledgement for a partner order | lib/QuotationService.php:509-685 |
| **`CrmApiClient::createPaymentSafe()`** | Looks for an existing payment before posting one (reference or same-day match) | lib/CrmApiClient.php:151-278 |
| **Webhook "doorbell" rule** | Every uCRM event is re-read from uCRM before it is acted on | webhook.php:556-592 |
| **KYC and identity dedupe** | A retail customer found by phone (last nine digits) is never created twice. Ambiguous numbers are refused | lib/KycService.php:481-615; lib/CustomerIdentity.php:127-190 |
| **Claim-before-create** | A conditional UPDATE claims the work so two runs cannot both create a uCRM client | lib/KycCrmSync.php:503-523 |
| **`equipment_assignments` + `KitAttributeIntake`** | **The authoritative kit binding**: one live customer per kit and per service, released rows immutable, deletes refused. The intake refuses a kit held by someone else or not in stock | migrations/068_equipment_assignments.sql:66-110; lib/KitAttributeIntake.php:6-37; Doc — root docs/38:216 (Decision B-1) |
| **`PurchaseService`** | Supplier receipts in one transaction; unit cost for serial units; weighted average for bulk; supplier payables in instalments | lib/PurchaseService.php:71-166, 260-328, 338-403, 463-476 |
| **`BankImport`** | Bank-statement reconciliation, with a suspense account for unmatched supplier debits | lib/BankImport.php:116-196, 335-375 |
| **`CashbookService`** | Where cash is held and paid out, per currency. One currency per account, enforced | lib/CashbookService.php:122-129, 158-175, 600-661 |
| **`FinAudit`** | Append-only before/after audit, with actor and reason | lib/FinAudit.php:57; migrations/065_financial_audit.sql; tests/test_fin_audit.php:124-136 |
| **`ReportingService` figure rule** | Per currency, never summed across currencies, "not available" rather than 0 | lib/ReportingService.php:24-38, 74-84 |
| **Currency helpers** | Entry, payload and display currency; guard tests | lib/currency.php; tests/test_currency_sweep.php |
| **`TenantProfile` + gates** | Uganda-only switching, with South Sudan proved unchanged | lib/TenantProfile.php:73-81; lib/NotifyGate.php:52-58 |
| **`PhoneNumber`** | One international form for a phone number | lib/PhoneNumber.php:35 |
| **`cron/master.php` + `AlertService`** | Scheduled jobs with a lock and a budget; staff alerts with a per-key cooldown | cron/master.php:66-105, 343-381; lib/AlertService.php:52-86 |
| **`CustomerSession` design** | A session row per sign-in that can be revoked; HttpOnly cookie; a CSRF header rule | lib/CustomerSession.php:1-40; migrations/073_customer_sessions.sql |
| **`MailService`** | E-mailing partner statements (a PDF attachment is supported) | Doc — root docs/07 (5.18.6 attached invoice PDFs) |
| **Test framework** | `tests/run.sh`, a fake uCRM server and 33 other fixtures, weakened-copy checks, South Sudan golden tests | tests/run.sh; tests/fixtures/fake_ucrm_server.php |
| **Deploy scripts and harnesses** | Pinned, backed up, typed `DEPLOY`, a separate typed `ROLLBACK`, rehearsed | root scripts/deploy-5.18.54.sh; root scripts/harness/ |

---

## 5. Existing functionality that needs extending (deliverable 4)

| # | Component | Extension | Why the existing one cannot do it |
|---|---|---|---|
| X1 | `StockService` + stock tables | named **locations** (warehouse, partner warehouse, outlet, transit, quarantine); **owner** apart from custody; **transaction type** and document on every movement; **two-step dispatch and receipt**; **no clamping**; **no deletion** | The four location types cannot name an outlet (lib/StockService.php:36). There is no owner (migrations/036). Transfer is one step. Bulk is clamped at 0 (:668-672). Units are deleted (:621) |
| X2 | `stock_units.serial_number` | a normalised serial with a **unique** index, built only after a duplicates census | The index is not unique (migrations/036:48). The runner would hide a failed build (X12) |
| X3 | `stock_movements` | append-only by **trigger** (no UPDATE, no DELETE); an idempotency key; owner before and after; the cost at the moment of transfer | It is called an "immutable audit trail" but nothing enforces that (migrations/036). Nothing in the code updates or deletes it, so a trigger breaks nothing |
| X4 | `stock_categories` | a link to the **uCRM product** it is sold as | No product id exists, so a unit cannot be invoiced as the right product (migrations/036) |
| X5 | RBAC (`RbacService`, `$ALL_MODULES`, `canLegacy`) | two staff roles, **Warehouse officer** and **Channel manager**, and `dist.*` permissions; **the API uses the same permission check as the pages** | The roles do not exist (lib/RbacService.php:110-118). The API's check ignores RBAC (includes/api_handlers.php:94-98) |
| X6 | uCRM client creation | create **company** clients (`clientType` 2, company fields); **fix the lead test** to use `isLead` | Every create sends `clientType` 1. Every payment flips 1 to 2, reading 1 as "lead" (lib/CrmApiClient.php:97-110; root docs/30 §6) |
| X7 | Webhook handlers | partner clients **excluded** from retail messages (welcome, invoice, reminders), or given partner-specific ones (**D-10**) | `client.add` and `invoice.add` message every client alike (webhook.php:808-861, 934) |
| X8 | KYC dedupe index | a partner contact's phone is flagged as a partner, so it neither blocks nor attaches a retail registration | The index covers all clients (lib/KycService.php:506-519) |
| X9 | uCRM invoice write | a general "invoice this partner" writer: explicit lines, no hard-coded organisation, a claim before the write | The only invoice write is the South Sudan wallet top-up with `organizationId` 7 (lib/FtthCrmService.php:200-222) |
| X10 | Credit notes | for a partner invoice, linked to the return or cancellation that caused it | Written today only from the payments admin screen, for customers (includes/api/api_payments_admin.php:520-531) |
| X11 | `ReportingService` | partner, outlet, location and channel dimensions; distribution cost of goods sold and margin; partner receivables | No dimension exists (lib/ReportingService.php:92-113). Margin is "not available" (:281-301). The inventory label reads a settings key that does not exist (:463-468 vs manifest.json:227) |
| X12 | `MigrationRunner` | a **strict mode** for new files: one transaction, not recorded as applied if any statement fails, an alert | It records a file as applied even after a failed statement (lib/MigrationRunner.php:242-248) |
| X13 | CSV exports | neutralise cells beginning `=`, `+`, `-`, `@`; require sign-in and permission | No export neutralises formulas. One export has no sign-in check (PD-1) |
| X14 | `AlertService` jobs | low stock per location; overdue settlements; unreceived dispatches; stale consignment | Low stock is shown on a screen only (lib/StockService.php:1171-1187) |
| X15 | `QuotationService` | a fifth flow, the **partner** order proforma | Four flows today: KYC, lead, cash, manual (lib/QuotationService.php:14-24) |

---

## 6. Missing functionality (deliverable 5)

Each is new because nothing in the plugin or uCRM holds it (§3 gives the row-by-row reason). Tables are in §9.

| # | Capability | Brief | Phase |
|---|---|---|---|
| M1 | Partner master, agreements, outlets, regions | A, B | 1 |
| M2 | Partner users, sessions, the partner portal API and screens | G | 6 |
| M3 | Price lists with tiers and dates; approved retail rules | C | 3 |
| M4 | Commission plans, dated rules, accruals | F | 4 |
| M5 | Partner orders | E | 3 |
| M6 | Dispatch, in-transit and receipt with variances | D | 2 |
| M7 | Outlet retail sales | E | 3 |
| M8 | Activation requests (a partner sale → KYC → a staff-created service → the kit binding) | E | 3 |
| M9 | Returns (RMA), with inspection and disposition | D, E | 2 (stock), 3 (credit) |
| M10 | Adjustments by approval; stock counts | D | 2 |
| M11 | Stock levels per location; outlet replenishment requests | D | 2 (levels, alerts), 6 (requests) |
| M12 | Settlements and partner statements | F | 4 |
| M13 | Approvals, with a different approver | F | 2 (stock), 4 (money) |
| M14 | A claim record for every uCRM write | F | 1 |
| M15 | Distribution reports | H | 2–6 |
| M16 | `DistributionGate` (Uganda only) and a master switch, off by default | — | 0 |

---

## 7. File-level evidence (deliverable 6)

### 7.1 Findings that shape the plan

The high-weight lines were re-read by hand for this report: F-3, F-5, F-6, F-8, F-9, F-10, F-17, F-18, F-19, F-26,
F-27, PD-1 to PD-6, and the PD-9 script line. The rest come from six read-only code sweeps, and their line numbers
were spot-checked. In the Tag column, **Code** means read in the plugin at `fe6471e`.

| # | Finding | Tag | Evidence |
|---|---|---|---|
| F-1 | uCRM 4.5.33 / UISP 3.0.159; PHP 8.1.34; the plugin calls API v2.1 | Server, Code | docs/120:234; SUDAN-EDITION.md:6; lib/CrmApiClient.php:72-74 |
| F-2 | The plugin contract: one served file, a 5-minute tick, SQLite and JSON, one admin iframe menu, no client-zone entry | Code, Doc | manifest.json:19-26; lib/SqliteStore.php:145-161; docs/98 §2.10 |
| F-3 | No partner entity. The "Dealer" role is a person with a wallet | Code | lib/RbacService.php:114; migrations/032_rbac_system.sql:159 |
| F-4 | Uganda's uCRM has **one** organisation (id 1). The retailer "Org 7" is hard-coded South Sudan logic | Server, Code | root docs/30:29; lib/FtthCrmService.php:55 |
| F-5 | Every client the plugin creates is `clientType` 1 | Code | lib/KycService.php:676; lib/KycCrmSync.php:425; lib/UcrmLeadSync.php:304; lib/FtthCrmService.php:116 |
| F-6 | Posting a payment turns `clientType` 1 into 2, taking 1 to mean "lead". In uCRM, 1 is residential and 2 is company; a lead is `isLead` | Code, Doc | lib/CrmApiClient.php:97-110; lib/EfrisInvoiceMapper.php:103-107; root docs/30 §6 |
| F-7 | A new client gets the retail welcome WhatsApp, a duplicate-phone alert and a mailbox. A new invoice gets WhatsApp, PDF and e-mail | Code | webhook.php:808-861, 934 |
| F-8 | Stock gaps, one per line below | Code | as listed |
| | • the serial index is not unique | | migrations/036_stock_tables.sql:48 |
| | • the location types lack partner and outlet | | lib/StockService.php:36 |
| | • the only warehouses are two South Sudan names | | lib/StockService.php:43-47 |
| | • bulk balances are clamped at 0 | | lib/StockService.php:668-672 |
| | • units are hard-deleted | | lib/StockService.php:621 |
| | • there is no owner field | | migrations/036 |
| F-9 | No code updates or deletes `stock_movements` (tests excluded) | Code | a repository search, 30 Sep |
| F-10 | The kit binding is enforced by the database: partial unique indexes and history triggers | Code, Doc | migrations/068_equipment_assignments.sql:66-110; root docs/38:216, 445 |
| F-11 | On 26 Sep, Uganda's plugin stock held **2 units** and **2 live assignments**, both for other customers | Server | root docs/37 §I.6 |
| F-12 | Receipts, costing and payables exist; a supplier payment writes no cashbook row by itself | Code | lib/PurchaseService.php:71-166, 260-328, 338-403 (link at 375) |
| F-13 | `inventoryValue()` adds values across currencies, and the report's currency label reads a key that does not exist | Code | lib/PurchaseService.php:484-500; lib/ReportingService.php:463-468; manifest.json:227; tests/test_reporting.php:40, 105 |
| F-14 | No cost of goods sold anywhere; `install()` links a unit to a client and service only | Code | lib/ReportingService.php:281-301; lib/StockService.php:892-935 |
| F-15 | The cashbook is a typed, single-entry cash journal: no journal entries, no revenue or cost accounts | Code, Doc | lib/CashbookService.php:122-129, 1001-1036; docs/UGANDA-ACCOUNTING-CURRENCY-FULL-AUDIT.md:115-129 |
| F-16 | The cashbook's unique index on `validation_ref` is dropped on **every** start, on purpose (exchange pairs share a reference) | Code | lib/CashbookService.php:1052-1065 |
| F-17 | Commissions today, one per line below | Code | as listed |
| | • a percentage of a *collection*, at *today's* rate | | includes/post/post_field.php:774-795 |
| | • hard-coded defaults | | public.php:506-509 |
| | • `flat` is treated as a percentage | | includes/post/post_field.php:778-780 |
| | • `commission_on_kyc` is saved and shown but never read | | includes/post/post_admin.php:644; tabs/admin/settings.php:864 |
| | • the reports recompute at current rates | | includes/api/api_lte.php:629-658 |
| F-18 | "Wallet" means a prepaid float in collections, and a **debt** in the Org-7 sync | Code | includes/post/post_field.php:758-768; cron_wallet_sync.php:7-18, 176-207 |
| F-19 | The plugin's only uCRM invoice write is the South Sudan wallet top-up | Code | lib/FtthCrmService.php:200-222 |
| F-20 | The plugin never creates a uCRM service. Staff are told to create one | Code | lib/KycService.php:1985-1990; no `POST clients/{id}/services` in the code |
| F-21 | Credit notes are written to `billing/credit-notes` with `tax1Id` null | Code | includes/api/api_payments_admin.php:520-531 |
| F-22 | uCRM has no tax rates, and no product is taxable. The plugin pushes every product with `taxable` false | Code, Doc | docs/UGANDA-VAT-CONFIGURATION.md:19-37; includes/post/post_sync.php:463, 595 |
| F-23 | EFRIS production is refused in code. The EFRIS stock count is separate from plugin stock | Code | lib/EfrisClient.php:18, 30; lib/EfrisGoodsStore.php:8-16 |
| F-24 | There is no mobile-money payment method in uCRM: mobile money is booked as **Cash** | Code | lib/PaymentUuids.php:20-24 |
| F-25 | uCRM payments are retried by overlapping paths, one of them with no duplicate check | Code | cron_sync.php:225-295; cron/crm_payment_retry.php:39-84; cron/payment_catchup_sync.php:160, 190-215 |
| F-26 | The migration runner records a file as applied even when a statement failed. Prefix `048` is used twice | Code | lib/MigrationRunner.php:242-248; `migrations/048_*` |
| F-27 | Foreign keys are switched on for the store's connections, per connection | Code | lib/SqliteStore.php:161, 201 |
| F-28 | ReportingService is used only by a CLI tool and tests; dashboards compute their own figures | Code | tools/report.php:22, 65 |
| F-29 | The sibling Finance plugin keeps its own kit register; B-1 names the plugin's register as authoritative | Server, Doc | root docs/37:105-116; root docs/38:216 |
| F-30 | There is no external accounting package | Code | a repository search for QuickBooks, Xero, Sage, Odoo, Zoho Books, Tally: none |

### 7.2 Existing defects found on the way — not distribution work, each needs its own approval

These exist today, with or without distribution. **PD-1 should be looked at first, on its own.** PD-2 to PD-9 must
be closed before any partner data exists (Phase 0), because distribution endpoints would inherit them.

| # | Defect | Evidence | Why it matters here |
|---|---|---|---|
| **PD-1** | The **collections CSV export has no sign-in check**. `includes/routes.php` is included (public.php:739) before the dashboard's login gate (public.php:1027). The block runs on `tab=all_collections&col_export=csv` alone. It writes every collection: agent, customer name, uCRM id, amount, method, note, payment id. The other three exports in that file call `requireLogin()` (routes.php:182, 308, 415). **Code reading only; not reproduced.** | includes/routes.php:265-304 | A possible anonymous export of customer payment records. Recommended: a one-line fix on its own, and a read-only look at the web access logs for requests carrying `col_export=csv` |
| PD-2 | The JSON API's permission check is "admin, or a module on the account"; it **ignores role permissions**, which the pages honour | includes/api_handlers.php:94-98; public.php:2317-2341 | A permission granted by role passes on a page and fails at the API, or the reverse. New actions would inherit this |
| PD-3 | The API login answer returns the account record minus only the password: its bearer token, its `pwd_reset_token`, and any personal uCRM app key the account holds | includes/api/api_public.php:6-19; lib/RetailerAuth.php:368-373, 404; includes/post/post_field.php:819-827 | A privileged uCRM credential can reach a client. That is G5 |
| PD-4 | Every staff page embeds the account's 90-day bearer token in a meta tag and an inline script | public.php:1683, 1777; lib/RetailerAuth.php:240-268 | Any script on the page can read it (see PD-9) |
| PD-5 | A tab with no `$_tabPerms` entry is open to any signed-in user | public.php:2766 | New distribution tabs must be denied unless listed |
| PD-6 | `customer_360` loads any uCRM client id from the request, with no ownership or role check | includes/api/api_retailer.php:612-625 | The pattern that would leak one partner's customers to another |
| PD-7 | Some stock API actions check no role (`stock_install`, `staff_list`, `stock_locations`); the privileged stock roles include `sales` | includes/api/api_stock.php:9-11, 141-150, 350-362; includes/routes.php:1123 | Unauthorised stock movement is possible today |
| PD-8 | The JSON API sends `Access-Control-Allow-Origin: *` and checks no CSRF token or Origin. The session cookie is `SameSite=None`. The form CSRF token is one daily value shared by everyone | includes/api_handlers.php:2-8; public.php:57-63, 71-114 | Mutating distribution actions need a real CSRF and Origin rule |
| PD-9 | Reflected script injection: `stock_tab` from the URL is written into a script with only `addslashes` | tabs/admin/stock_dashboard.php:9, 868 | Together with PD-4, it can read a staff token |
| PD-10 | The "export deployed units" action selects columns that do not exist (`mac_address`, `cost_price`) | includes/api/api_stock.php:382-395 | Broken; not a risk |
| PD-11 | The emergency repair page falls back to a fixed built-in key when no key file exists | public.php:236-241 | A known key opens a repair page. Whether the key file exists on Uganda is NV-13 |
| PD-12 | The BlueCard partner portal (`page=bc_portal`, a South Sudan LTE feature) forwards any `table` to its feed with the server's token, and the sweep found no sign-in check before that branch | tabs/lte/bc_portal.php:38-77 | **The one existing partner-facing page is the pattern not to copy** |
| PD-13 | The cashbook's approve and reject, and the voiding of a pair, write no audit row. The reseed button deletes rows without one | lib/CashbookService.php:797-806, 1350-1375, 2297-2306 | Distribution approvals must not follow this |

---

## 8. Current data model (deliverable 7, part 1)

### 8.1 In uCRM, reached only through its API

| Object | Fields the plugin uses | Written by the plugin? |
|---|---|---|
| Client | `id`, `clientType` (1 residential, 2 company), `isLead`, `companyName`, `companyTaxId`, `companyRegistrationNumber`, `contacts[]`, `attributes[]`, `organizationId`, `accountBalance`, `accountOutstanding` | yes: create (always type 1), patch, tag, document |
| Organization | `id` (Uganda: **1 only**) | read only |
| Product / service plan | `id`, `name`, `price`, `taxable` | products yes (price pushes); plans name only |
| Service | `id`, `clientId`, `servicePlanId`, `status`, attribute `starlinkDetails` (kit serial) | patch status, attributes, note. **Never created** |
| Invoice | `id`, `clientId`, items, status, due date, totals, taxes | only the South Sudan wallet top-up |
| Payment | `id`, `clientId`, amount, `currencyCode`, `methodId` (UUID), `invoiceIds`, note | yes, through `createPaymentSafe` and some raw posts |
| Credit note / refund | `id`, `clientId`, items | yes |
| Quote | `id`, `clientId`, items | yes |

### 8.2 In the plugin (SQLite `plugin.sqlite3`)

```
IDENTITY    retailers.json (staff and dealer accounts) ── roles / permissions / role_permissions (032)
            customer_sessions (073)          client_search_index, KYC applications, duplicate_confirmations

STOCK       stock_categories ──< stock_units >── equipment_assignments (068) ── uCRM client, uCRM service
                   │                 │
                   ├──< stock_quantities   (category, location_type, location_ref)   UNIQUE
                   └──< stock_movements    (append-only by habit, not by constraint)
            stock_purchases ──< purchase items / payments (066)
            efris_goods (a separate EFRIS stock count)

MONEY       cb_accounts ──< cb_ledger (typed single-entry cash journal, per currency)
            staff_ledger (045)   payment_collections.json   passbook.json / wallet_events (009)
            fin_audit (065, append-only)   efris_transactions

AUDIT       fin_audit (financial)   activity log (JSON)   notification_audit_log
```

**What is missing from this model is the whole distribution layer:**
- no partner, outlet or named location;
- no owner apart from location;
- no price by partner or by date;
- no commission rule, accrual or settlement;
- no link between a unit and the invoice that sold it.

---

## 9. Proposed plugin-level data model, tables and relationships (deliverables 7 and 8)

**No migration is written or proposed as a file here.** Each table would be created by an additive migration from
`077` onward, in the phase that needs it (§14), under the strict migration mode of Phase 0.

### 9.1 Rules the model follows

1. **uCRM stays the master of:**
   - who a customer is, and who a partner is *as a billing party*;
   - invoices, payments, credit notes, products, plans and services.

   The plugin stores uCRM ids and never copies those records into a second ledger.
2. **The plugin is the master of distribution operations:**
   - partner terms, outlets and locations;
   - who owns a unit and where it is;
   - every movement;
   - price lists, commission rules and accruals, and settlements;
   - approvals and partner users.
3. **One stock master.** `stock_units` + `stock_movements` + `equipment_assignments`, as Decision B-1 already
   says (root docs/38:216). No second kit register. Balances are projections that can be rebuilt from the
   movements.
4. **Journals are append-only by trigger:** movements, accruals, approvals and uCRM write claims. A correction is
   a new row that points at the one it corrects.
5. **Scope is data, set by the server.** Every partner-scoped row carries `partner_id` (and `outlet_id` where it
   applies), taken from the signed-in session or the parent row, **never from the request**.
   - Composite keys `(id, partner_id)` make "an outlet of partner A pointing at a location of partner B"
     impossible to write.
   - That is the lesson of W-2 and O-1 in Domain B (CLAUDE.md), carried over to SQLite foreign keys. They are on
     per connection (lib/SqliteStore.php:161), so every distribution service asserts the pragma before it writes.
6. **Money is exact.** New amount columns hold integers in the currency's minor unit (UGX has none, USD has
   cents), with an ISO code on the row. Arithmetic never mixes currencies.
   - Existing tables keep their `REAL` columns.
   - This departs from the existing style on purpose: settlement sums must add up to the shilling.
7. **Every uCRM write is claimed first.** A row in `dist_ucrm_writes` with a unique `(purpose, local_id)` exists
   before the API call. So a retry finds the claim instead of creating a second invoice. This is the KYC
   claim-before-create pattern (lib/KycCrmSync.php:503-523).
8. **No tenant layer.** Partners are *customers of DishNet with distribution terms*, not tenants of the platform.
   docs/81 Decision 9, a Domain-B decision applied here by analogy, records that a reseller must never become a
   tenant by accident, through a role matrix.
   Partner isolation here is a **read and write scope inside one DishNet installation**, decided explicitly by this
   document and by **D-9a** and **D-9b**.

### 9.1a Partner identity in uCRM

| Question | Answer | Reason |
|---|---|---|
| A uCRM **organisation** per partner? | **No** | An organisation is DishNet's own issuing entity: invoice numbering, bank details, sender. Uganda has one (root docs/30:29). Using it as a group is the Org-7 pattern, which breaks on Uganda (lib/FtthCrmService.php:55) |
| A uCRM **client** per partner? | **Yes: one company client per legal entity** (`clientType` 2, `companyName`, `companyTaxId`, `companyRegistrationNumber`) | The partner is who DishNet invoices and who pays. uCRM then holds its invoices, payments, credit notes and balance, with no second ledger |
| A uCRM client per **outlet**? | **Only if that outlet is billed separately** (**D-5**) | Otherwise an outlet is an address and a stock location, not a debtor |
| Marking | client attribute `dnPartnerCode`, plus the plugin link | Staff see it in uCRM, and the plugin's retail paths can exclude it (X7, X8) |
| Dedupe | by `ucrm_client_id` (unique) and normalised TIN (unique where present). **Never by phone** | Phone matching is for retail customers. A partner's contact phone says nothing about the legal entity |

### 9.2 Partners and agreements

**`dist_partners`**

| Column | Rule |
|---|---|
| `id` | primary key |
| `partner_code` | unique, never reused |
| `partner_type` | `corporate_retail` \| `authorised_reseller` \| `regional_distributor` \| `wholesale_customer` |
| `category` | from a setting list (fuel station, supermarket, electronics, distributor, other) |
| `status` | `prospect` \| `onboarding` \| `active` \| `suspended` \| `terminated`. Stock can move only while `active` |
| `ucrm_client_id` | unique; may be null before the partner is `active`, required from `active` on; `ucrm_linked_by`, `ucrm_linked_at` record who linked it and when |
| `legal_name`, `tin`, `registration_no` | kept locally **only until the uCRM client is linked**. After that, uCRM's company fields are the master and these are a cached, read-only copy |
| `tin_norm` | unique where not empty |
| `account_manager_staff_id` | → a staff account |
| `trading_currency` | an ISO code |
| `created_at`, `created_by`, `updated_at` | — |

**`dist_agreements`**

| Column | Rule |
|---|---|
| `id`, `partner_id` | — |
| `agreement_ref` | unique |
| `model` | `wholesale` \| `consignment` \| `both` |
| `status` | `draft` \| `approved` \| `active` \| `expired` \| `terminated` |
| `effective_from`, `effective_to` (expiry) | — |
| `credit_limit_minor`, `currency` | — |
| `payment_terms_days` | — |
| `settlement_cycle` | `weekly` \| `monthly` |
| `sale_event` | **D-2** |
| `title_passes_at` | `dispatch` \| `receipt` (**D-3**) |
| `price_list_id`, `commission_plan_id` | — |
| `ucrm_document_id` | the signed copy, filed on the client |
| `approved_by`, `approved_at` | approver ≠ creator |

- **Constraint:** at most one `active` agreement per partner and model. This is a partial unique index.

**`dist_partner_contacts`** — optional, only if DishNet wants to record who may sign, approve or order for a
partner: `partner_id`, uCRM contact id,
`authority` (`sign_agreement` \| `approve_settlement` \| `order`).

### 9.3 Outlets, regions, locations

**`dist_regions`** (optional): `partner_id`, `code`, `name`. Unique `(partner_id, code)`.

**`dist_outlets`**

| Column | Rule |
|---|---|
| `id`, `partner_id` | — |
| `outlet_code` | **unique across all partners** |
| `name`, `region_id`, `address`, `district`, `phone`, `email` | — |
| `status` | `active` \| `suspended` \| `closed` |
| `branch_manager_user_id` | — |
| `stock_location_id` | unique; composite `(stock_location_id, partner_id)` → `stock_locations (id, partner_id)` |
| `opened_on`, `closed_on` | — |

- Unique `(id, partner_id)` lets children reference both.

**`stock_locations`** (new, and used by all stock, not only distribution)

| Column | Rule |
|---|---|
| `id` | — |
| `code` | unique; it becomes the existing `location_ref` |
| `kind` | `dishnet_warehouse` \| `partner_warehouse` \| `outlet` \| `transit` \| `quarantine` |
| `partner_id` | required for the partner kinds, null otherwise |
| `name`, `status` | — |

- Unique `(id, partner_id)`.
- **Compatibility:** the existing `location_type` / `location_ref` pairs stay. The new kinds are added to
  `LOCATION_TYPES`, and `location_ref` = `stock_locations.code`, so `stock_quantities`' unique key keeps
  working.
- **The two South Sudan warehouse constants are not used on Uganda.** Uganda's warehouses are created as rows
  (**D-13** names them).

### 9.4 Stock: extensions to existing tables

| Table | Added | Enforced by |
|---|---|---|
| `stock_units` | `owner_kind` (`dishnet` \| `partner`), `owner_partner_id`, `stock_location_id`, `serial_norm`; statuses `in_transit`, `sold` | **unique `serial_norm` where not empty**, created only after the Phase 0 duplicates census; a trigger refusing DELETE once a unit has movements; transitions checked in `StockService` and pinned by tests |
| `stock_quantities` | `owner_key` (`dishnet` or `partner:<id>`) | the unique key rebuilt as `(category_id, location_type, location_ref, owner_key)`; existing rows default to `dishnet`, so none collide; a trigger refusing `qty_on_hand < 0` |
| `stock_movements` | `txn_type`, `document_type`, `document_id`, `owner_from`, `owner_to`, `stock_location_from_id`, `stock_location_to_id`, `unit_cost_minor`, `cost_currency`, `idempotency_key` | unique `idempotency_key`; triggers refusing UPDATE and DELETE (safe: F-9) |
| `stock_categories` | `ucrm_product_id`, `track_serial_required` | unique `ucrm_product_id` where not null |

**`txn_type` values:**
- `wholesale_sale`, `consignment_transfer`, `branch_transfer`, `retail_sale`;
- `return_to_dishnet`, `return_to_supplier`;
- `receipt` (supplier), `dispatch_out`, `dispatch_in`;
- `adjustment`, `count_variance`, `write_off`, `cancellation`.

The first six are your five transaction types, with return split by destination. The rest are the mechanics that
carry them.

### 9.5 Stock documents

**`dist_dispatches`** + **`dist_dispatch_lines`** — one transfer document for wholesale, consignment, branch
transfer and returns.
- **Header:** `dispatch_no` (unique), `txn_type`, `partner_id`, from and to location, `order_id`, `status` (`draft`
  → `approved` → `dispatched` → `received` \| `received_with_variance` \| `cancelled`), `dispatched_by/at`,
  `received_by/at`, `variance_approval_id`, `ucrm_invoice_id` (wholesale only).
- **Lines:** `category_id`, `unit_id` or `qty`, `unit_price_minor` (a price snapshot), `price_list_item_id`,
  `qty_received`, `condition_on_receipt`.
- **Constraint:** a serial unit on at most one open dispatch. This is a partial unique index on the line while the
  line is open.

**`dist_returns`** + lines (RMA): `rma_no`, `partner_id`, `outlet_id`, reason, `status` (`requested` → `approved` →
`in_transit` → `received` → `inspected` → `restocked` \| `written_off` \| `to_supplier` \| `rejected`),
`dispatch_id`, `ucrm_credit_note_id`, and per line the condition and what was done with it.

**`dist_adjustments`**: `location_id`, `category_id`, `unit_id` or `qty_delta`, `reason_code`, `requested_by`,
`approval_id`, `movement_id`.
- No stock changes without an approved adjustment.
- The approver is never the requester, enforced by trigger on `dist_approvals`.

**`dist_stock_counts`** + lines: the expected quantity (from the journal), the counted quantity and the variance.
Each variance becomes an adjustment request.

**`dist_stock_levels`**: `(location_id, category_id)` unique, with `min_qty`, `max_qty`, `reorder_qty`.

**`dist_replenishment_requests`** + lines: from an outlet, `status`, → an order or a dispatch.

### 9.6 Prices and commissions

**`dist_price_lists`**: `code` (unique), `name`, `currency`, `kind` (`wholesale` \| `consignment` \| `retail_rule`),
`status`, `approved_by/at`.

**`dist_price_list_items`**

| Column | Rule |
|---|---|
| `price_list_id` | — |
| `category_id` or `ucrm_product_id` | the product |
| `min_qty` | volume tier |
| `unit_price_minor` | — |
| `rrp_minor`, `retail_min_minor`, `retail_max_minor` | the approved retail rule |
| `effective_from`, `effective_to` | — |
| `approved_by/at` | — |

- **Constraint:** unique `(price_list_id, product, min_qty, effective_from)`.
- No two open-ended rows for one key.
- Overlaps are refused by the service and pinned by tests (SQLite has no exclusion constraint).

**`dist_commission_plans`**, **`dist_commission_rules`**

| Column | Rule |
|---|---|
| `plan_id` | — |
| `basis` | `hardware_sale` \| `activation` \| `recurring_service` (**D-7**) |
| product scope | stock category, uCRM product or uCRM service plan |
| `calc` | `percent` \| `fixed` |
| `rate_bp` | basis points, **no default** |
| `amount_minor`, `currency` | for `fixed` |
| `tier_min_qty` | — |
| `effective_from`, `effective_to` | — |
| `approved_by/at` | — |
| `clawback_days` | — |

**`dist_commission_accruals`** — append-only.

| Column | Rule |
|---|---|
| `partner_id`, `outlet_id` | — |
| `source_type` | `retail_sale_line` \| `activation` \| `service_invoice` |
| `source_id` | — |
| `rule_id`, `rule_snapshot` | the rule as it was on the sale date |
| `amount_minor`, `currency` | — |
| `reverses_id` | set on a reversal row |
| `settlement_id` | — |

- **Unique `(source_type, source_id, rule_id)` where `reverses_id` is null.** A sale earns a commission once.
- **A missing rule creates no commission and raises an exception for approval.** It never falls back to a default
  rate.

### 9.7 Orders, retail sales, activations

**`dist_orders`** + lines: `partner_id`, `outlet_id` (deliver to), `partner_po_ref`, `model`, `status` (`draft` →
`submitted` → `approved` \| `rejected` → `fulfilled` \| `cancelled`), `ucrm_quote_id`, `requested_by`,
`approved_by`. Unique `(partner_id, partner_po_ref)`.

**`dist_retail_sales`** + **`dist_retail_sale_lines`**

| Column | Rule |
|---|---|
| `sale_no` | unique |
| `outlet_id`, `partner_id` | composite, so they must agree |
| `client_key` | idempotency: unique `(outlet_id, client_key)` |
| `sold_at`, `recorded_by` | — |
| `customer_name`, `customer_phone_e164` | optional; **D-10** decides what an outlet may capture |
| `ucrm_client_id` | set **only by staff** matching through the existing identity path, never by the outlet |
| `receipt_ref`, `payment_method` | — |
| `status` | `recorded` → `confirmed` → `settled` \| `cancelled` |

- **Lines:** `category_id`, `unit_id` (required when the category is serial-tracked), `qty`, `unit_price_minor`,
  `rrp_snapshot_minor`, `price_exception_id`.
- **Constraint:** a serial unit appears on at most one sale line that is not cancelled. This is a partial unique
  index.

**`dist_activation_requests`**

| Column | Rule |
|---|---|
| `retail_sale_line_id` | unique |
| `unit_id` | — |
| `requested_service_plan_id` | a uCRM service plan |
| customer contact | — |
| `kyc_application_id`, `ucrm_client_id`, `ucrm_service_id`, `equipment_assignment_id` | filled in as the existing path completes each step |
| `status` | `requested` → `kyc` → `service_created` → `kit_bound` → `active` \| `rejected` |
| `verified_by` | — |

- **Nothing here creates a uCRM client or a service.** It *points* at what staff created through the existing path.

### 9.8 Settlements and statements

**`dist_settlements`** + lines

| Column | Rule |
|---|---|
| `settlement_no` | unique |
| `partner_id`, `period_from`, `period_to`, `model` | — |
| `status` | `draft` → `prepared` → `approved` → `invoiced` → `paid` \| `disputed` \| `cancelled` |
| `gross_minor`, `commission_minor`, `net_payable_minor`, `currency` | — |
| `prepared_by`, `approved_by` | different people |
| `ucrm_invoice_id` | unique |
| `ucrm_credit_note_id` | — |
| `due_date` | — |

- **Lines:** a `retail_sale_line_id` that is **unique across all settlements**, so a sale settles once. Each line
  also carries its accrual ids.

**A partner statement is a report, not a table.** It is built from the partner's uCRM invoices, payments and credit
notes, plus the plugin's consignment position and accruals.

### 9.9 Reports

Queries over the tables above, exposed through `ReportingService` in the figure shape, then as staff tabs and
portal projections. No summary table is proposed. At the expected scale (hundreds of outlets, some thousands of
movements a month), indexed SQLite queries answer directly. Summary tables would be a later optimisation, and only if
measured slow.

### 9.10 Approvals, uCRM write claims, partner users

**`dist_approvals`** — append-only.

| Column | Rule |
|---|---|
| `subject_type`, `subject_id`, `action` | — |
| `requested_by`, `requested_at`, `reason` | — |
| `decided_by`, `decided_at`, `decision` | a trigger refuses `decided_by = requested_by` |

**`dist_ucrm_writes`**

| Column | Rule |
|---|---|
| `purpose` | `partner_client` \| `invoice` \| `credit_note` \| `payment` \| `attribute` \| `document` |
| `local_type`, `local_id` | — |
| `request_digest` | — |
| `status` | `claimed` → `sent` → `confirmed` \| `failed_definite` \| `unknown` |
| `ucrm_id` | — |
| `created_at`, `updated_at` | — |

- **Unique `(purpose, local_type, local_id)`.**
- An `unknown` result, such as a timeout, is **never retried blindly**. It is looked up in uCRM by the reference
  written into the object's `adminNotes`, which is how `createPaymentSafe` already works.

**`dist_partner_users`**

| Column | Rule |
|---|---|
| `id`, `partner_id` | — |
| `role` | `head_office` \| `branch_manager` \| `branch_cashier` \| `distributor_admin` |
| `name` | — |
| `phone_e164` | unique |
| `email` | — |
| `status` | `invited` \| `active` \| `disabled` |
| `credential` | per **D-9b**: a password hash plus a sealed TOTP secret, or none if phone OTP |
| `created_by` | a staff account |
| `last_login_at` | — |

**`dist_partner_user_outlets`**: `(user_id, outlet_id)`, composite with `partner_id`, so a user can be given only
their own partner's outlets.

**`dist_partner_sessions`**
- `token_hash`: an HMAC of a 256-bit random token. Its key is derived, under its own label, from a secret generated
  into the plugin's vault (lib/ConfigVault.php) at first use. It is never in source control or a migration.
- `user_id`, and a `partner_id` snapshot.
- `issued_at`, `expires_at`, `revoked_at`, `ip`, `ua`.
- This is the `customer_sessions` pattern (migrations/073), with an opaque token instead of a JWT, so there is no
  signing key to rotate.

**The partner portal never reads `retailers.json`, and the staff API never accepts a partner token.** They are
separate tables, separate cookies and separate dispatchers.

### 9.11 Relationships

```
uCRM client (company) 1───1 dist_partners 1───< dist_agreements >─── dist_price_lists ──< dist_price_list_items
                                 │   │                  └──────── dist_commission_plans ──< dist_commission_rules
                                 │   ├───< dist_regions
                                 │   ├───< dist_outlets 1───1 stock_locations (kind outlet)
                                 │   ├───< dist_partner_users >───< dist_partner_user_outlets >─── dist_outlets
                                 │   ├───< dist_orders ──< lines
                                 │   ├───< dist_dispatches ──< lines >─── stock_units / stock_categories
                                 │   ├───< dist_retail_sales ──< lines >─── stock_units
                                 │   │                               └──1 dist_activation_requests ─── (KYC, uCRM
                                 │   │                                     client, uCRM service, equipment_assignments)
                                 │   ├───< dist_returns ──< lines
                                 │   ├───< dist_commission_accruals ─── (sale line | activation | service invoice)
                                 │   └───< dist_settlements ──< lines ─── uCRM invoice (1) / credit note
stock_locations 1───< stock_units, stock_quantities;  stock_movements (from, to) ─── any document above
dist_approvals ─── any subject;   dist_ucrm_writes ─── any uCRM write;   fin_audit ─── every change of terms
```

### 9.12 What the database itself will refuse

| Rule | Mechanism |
|---|---|
| Two units with one serial | unique `serial_norm` |
| A unit on two open dispatches, or sold twice | partial unique indexes on open lines and on sale lines that are not cancelled |
| One kit bound to two customers or two services | already refused (migrations/068) |
| A movement edited or deleted | triggers |
| A negative bulk balance | trigger |
| An outlet, location or user of partner A attached to partner B | composite keys `(id, partner_id)` |
| Two active agreements of one model | partial unique index |
| A sale line settled twice; a commission accrued twice | unique indexes |
| A second invoice or credit note for one settlement or return | unique `dist_ucrm_writes` key + unique `ucrm_invoice_id` |
| An approval by its own requester | trigger |

---

## 10. Permissions and the partner portal (deliverable 9)

### 10.1 Partner, branch and user permission matrix

**Roles (columns):**
- **Staff roles** (the plugin's staff sign-in, after Phase 0):
  - **DA** DishNet administrator = the existing `admin`.
  - **WH** Warehouse officer = a new `warehouse` role.
  - **FO** Finance officer = the existing `accountant`.
  - **CM** Partner/channel manager = a new `channel_manager` role.
- **Partner roles** (the partner portal only):
  - **HO** Corporate partner head office.
  - **BM** Branch manager.
  - **BC** Branch cashier.
  - **RD** Regional distributor.

**Symbols:**
- **✔** may do it.
- **V** may view.
- **R** may request (needs approval).
- **A** may approve, **never their own request**.
- **S** means own scope only: own partner for HO and RD, own outlet for BM and BC.
- **—** means no access.

| # | Capability | DA | WH | FO | CM | HO | BM | BC | RD |
|---|---|---|---|---|---|---|---|---|---|
| | **Partners and terms** | | | | | | | | |
| 1 | See partners and outlets | ✔ | V | V | ✔ | V S | V S | — | V S |
| 2 | Create or edit partners, outlets, regions | ✔ | — | — | ✔ | R S | — | — | R S |
| 3 | Agreements | A | — | V | R | V S | — | — | V S |
| 4 | Credit limit and payment terms | A | — | A | R | V S | — | — | V S |
| 5 | Price lists and retail rules | A | — | V | R | V S (own lists) | V S (retail) | V S (retail) | V S |
| 6 | Commission plans and rules | A | — | V | R | V S | — | — | V S |
| 7 | Partner user accounts | ✔ | — | — | ✔ (first HO user) | ✔ S (outlet users only) | — | — | ✔ S |
| | **Stock** | | | | | | | | |
| 8 | DishNet warehouse stock | ✔ | ✔ | V (with value) | V | — | — | — | — |
| 9 | Partner and outlet stock | ✔ | V | V | V | V S | V S | V S (saleable units only) | V S |
| 10 | Receive supplier stock | ✔ | ✔ | V | — | — | — | — | — |
| 11 | Prepare and send a dispatch | ✔ | ✔ | — | — | — | — | — | — |
| 12 | Confirm receipt at an outlet | ✔ (on behalf, with a reason) | ✔ (on behalf, pilot) | — | — | ✔ S | ✔ S | — | ✔ S |
| 13 | Transfer between own outlets | ✔ | V | — | V | A S | R S | — | A S |
| 14 | Return (RMA) | ✔ | ✔ (receive, inspect) | ✔ (credit) | A | R S | R S | — | R S |
| 15 | Stock adjustment | A | R | A | — | — | R S | — | R S |
| 16 | Stock count | ✔ | ✔ | V | — | V S | ✔ S | — | ✔ S |
| 17 | Stock levels (min, max) | ✔ | V | — | ✔ | R S | V S | — | R S |
| 18 | Replenishment request | ✔ | ✔ (fulfil) | — | A | A S / R S | R S | — | R S |
| | **Sales** | | | | | | | | |
| 19 | Partner order | ✔ | V | A (credit) | A | ✔ S | R S | — | ✔ S |
| 20 | Record a retail sale | ✔ (on behalf) | — | — | ✔ (on behalf, pilot) | V S | ✔ S | ✔ S | ✔ S |
| 21 | Cancel a retail sale before settlement | ✔ | — | ✔ | — | A S | ✔ S (same day) | R S | ✔ S |
| 22 | Submit an activation request | ✔ | — | — | ✔ | — | ✔ S | ✔ S | ✔ S |
| 23 | Verify activation (KYC, create the service, bind the kit) | ✔ + the existing KYC roles, unchanged | — | — | — | — | — | — | — |
| 24 | See end-customer details | ✔ | — | V | V | V S (minimal, **D-10**) | V S (sales it recorded) | V S (sales they recorded) | V S |
| | **Money** | | | | | | | | |
| 25 | Wholesale invoice (created in uCRM) | ✔ | — | ✔ | V | V S | — | — | V S |
| 26 | Record and allocate partner payments | ✔ | — | ✔ | V | V S | — | — | V S |
| 27 | Commission accruals | V | — | ✔ | V | V S | — | — | V S |
| 28 | Prepare a settlement | ✔ | — | ✔ | V | V S | — | — | V S |
| 29 | Approve a settlement or an exception | A | — | A | — | — | — | — | — |
| 30 | Credit note | A | — | ✔ (R above a threshold) | — | V S | — | — | V S |
| | **Reports and audit** | | | | | | | | |
| 31 | Reports | all | stock | financial | commercial | own partner | own outlet | own outlet, today | own network |
| 32 | Audit trail | ✔ | — | V (financial) | — | V S (own documents) | V S | — | V S |
| 33 | Distribution settings: gate, switch, reason codes | ✔ | — | — | — | — | — | — | — |

**Notes on the staff roles:**
- **The existing roles keep what they do today** for DishNet's own retail work. They get **no** `dist.*` permission
  unless it is granted to them explicitly. The permission check denies by default.
- **The eight privileged stock roles of routes.php:1123 do not inherit distribution rights.**

**Two rules that bind every cell:**
- **Scope comes from the session, never from the request.** A partner user's partner and outlet set are read from
  their session row. An id in the request that is outside that scope answers **404**, exactly as if it did not
  exist, so a probe learns nothing.
- **An approval is a second person.** The service checks it and a trigger refuses it (`dist_approvals`). DA can
  approve, but never their own request.

### 10.2 Why partner users cannot use uCRM or the plugin's staff login

**uCRM (Blocked):**
- Nothing in this repository shows a uCRM user can be limited to some clients, and the plugin never relies on it.
  A uCRM staff login would also open uCRM's own screens: all clients, all invoices, settings. NV-2 settles it in one
  screen.
- The client zone is one client's own billing view, and a plugin page there is NV (docs/98 §2.10).
- Even at best, uCRM could show a partner its *own invoices*. It cannot show stock, outlets or sales.

**The plugin's staff login (Blocked):**
- Partners would share an account store with DishNet staff (`retailers.json`).
- They would inherit PD-2 to PD-9 (§7.2): an API permission check that ignores roles; pages open by default; a
  login answer that returns the account's tokens; tokens embedded in every page; customer lookups by any id; an
  open CORS policy with no CSRF on the API; script injection on a stock page.
- **Fixing those is Phase 0** because staff will manage partners. Even then, putting outside companies into the
  staff store is the "reseller by accident, through a role matrix" that docs/81 Decision 9 warns against (a Domain-B
  decision, applied here by analogy).

### 10.3 The minimal, separate partner portal (design; Phase 6)

| Property | Design | Reuses |
|---|---|---|
| Accounts | `dist_partner_users`. Created or invited by staff (CM) and, for their own outlets, by HO and RD | — |
| Sign-in | **D-9b.** Recommended: a one-time code to the user's registered phone, and additionally a TOTP for HO and RD, who see money | the customer portal's code flow; `PhoneNumber` |
| Session | `dist_partner_sessions`: an opaque 256-bit token, stored only as an HMAC; 8-hour life; revoked on logout, on disable and on a role or outlet change. Every request re-reads status and scope | `CustomerSession` (lib/CustomerSession.php; migrations/073) |
| Cookie | its own name, HttpOnly, Secure, `SameSite=Strict`. Any request other than GET needs the custom header and a matching `Origin` | the customer-portal rule (lib/CustomerSession.php:15-19) |
| Entry | `public.php?page=partner` (the screen shell) and `public.php?page=partner_api&action=…` | the one-public-file contract |
| Dispatcher | **one registry**: action → method, capability, scope kind, handler. An undeclared action answers 404. **It never falls through to `includes/api_handlers.php`**, and a staff token is refused, as a partner token is on the staff API | — |
| Data access | every repository method takes the `PartnerContext` and adds `partner_id = ?` and, for outlet roles, `outlet_id IN (…)` | — |
| Answers | allow-listed fields only. **Never a raw uCRM object, never a uCRM id of another client, never the app key** | the "not available" rule for data that cannot be read |
| uCRM | only server-side through `CrmApiClient`, and only for the partner's own client id, which comes from `dist_partners` and never from the request | — |
| Pages | one static bundle, `script-src 'self'`, no inline script, security headers | Domain B's operator app (docs/127 §H) as the pattern |
| Limits | sign-in, code and write limits per phone and per address, with decaying lockout | the customer login's limits |
| Audit | every partner write: its document row, with the partner user as actor, plus `fin_audit` for money | `FinAudit` |
| Weak connections | outlet screens are light pages. Every write carries a client key, so a cashier's resend on a poor connection cannot double a sale | — |

**Where it runs (D-9a):**

| Option | Pro | Con |
|---|---|---|
| **P-A: inside the plugin, on its own hostname** (a Traefik route to the plugin's `public.php`, as `crm.dishnetuganda.com` already is) — **recommended** | One system of record, one deploy path, no new service. Its own hostname keeps the partner cookie and origin apart from the staff pages | Same failure domain as billing, and the SQLite single writer (R-12). The Traefik route is a production change that needs its own approval |
| P-A': inside the plugin, on the uCRM public address | No infrastructure change | Shares an origin with the staff pages, so any staff-page script bug reaches partner sessions |
| P-B: a separate service (own container, host, database) | Separate failure domain; could use PostgreSQL row-level security | **It does not strengthen isolation for stock**, which stays in the plugin (B-1): the service would call a new plugin API that itself enforces scope in PHP. It adds a deployable, a credential boundary and a second copy of partner users |

**Isolation is enforced in PHP, not by the database.** SQLite has no row-level security. That is a real limit, and
the plan answers it as follows:
- one registry;
- one scoped repository layer;
- composite keys, so a row cannot point across partners;
- a test that enumerates every action, role and foreign id;
- weakened copies proving each scope predicate is load-bearing (§15).

If DishNet later wants a database-enforced floor, that is the day to move the distribution ledger to PostgreSQL
(root docs/15, "Postgres is the exit"). It is not a precondition.

### 10.4 Server-side enforcement on the staff side

- **One `DistributionPermissions` registry** lists every distribution tab and API action with its permission.
- A test enumerates the registry and the code: an action or tab that is not listed **fails the suite**.
- The API and the pages use **one** permission function (PD-2 fixed for these actions).
- Mutating JSON actions require the CSRF token and a same-origin `Origin` (PD-8), and distribution pages carry no
  token (PD-4).
- Approvals are checked in the service and refused by a trigger.

### 10.5 Not Domain B

**Distribution partners must not be put into the MikroTik control plane.** Its tenant, `mt_customers`, is the
HotSpot **Operator**:
- CLAUDE.md reserves the word *Operator* for that tenant and forbids a tenant above it.
- It holds no Starlink, stock or uCRM billing data.
- "Reseller deployment" is listed there as not yet allowed (docs/00 §12).

A fuel-station chain that sells Starlink kits is a DishNet commercial partner, not a HotSpot operator. If a partner
someday also runs a DishNet HotSpot, it gets a Domain-B operator record for *that*. The two are linked, if ever, at
an audited link point like the one docs/110 defines for uCRM.

---

## 11. Wholesale and consignment workflows (deliverable 10)

Each step says: the document, the stock effect, the uCRM effect, the accounting effect, and the control.
**Consignment dispatch never creates an invoice or revenue.** A test pins that (§15, Phase 3).

### 11.1 Wholesale: the partner buys the stock

| Step | Who | Plugin record | Stock | uCRM | Accounting | Control |
|---|---|---|---|---|---|---|
| 1 Order | HO/RD (portal) or CM (for them) | `dist_orders` `submitted`; optionally a uCRM quote as the proforma | — | quote (optional) | — | the agreement is `active` and allows `wholesale`; prices come from the list in force today |
| 2 Approve | CM + FO | `approved` | units reserved at the warehouse | — | — | **credit check:** uCRM outstanding + this order ≤ the limit, else FO approval |
| 3 Dispatch | WH | `dist_dispatches` `dispatched` (`wholesale_sale`) | warehouse → transit (by serial or quantity) | — | — | each serial on one open dispatch only |
| 4 Invoice | FO, or automatically at step 3 or 5 (**D-3**) | `dist_ucrm_writes` claim → `ucrm_invoice_id` | owner → partner at the step `title_passes_at` names | **invoice to the partner's client**, lines at the snapshot prices, due by the payment terms | **revenue** = the invoice; **cost of goods sold** = the unit costs on the movement | one invoice per dispatch (unique) |
| 5 Receive | BM/HO (portal) or WH on their behalf | `received` or `received_with_variance` | transit → outlet | — | — | a variance needs an approved adjustment or a claim against the carrier |
| 6 Pay | the partner, by bank or mobile money | — | — | **payment** on the partner's client, applied to the invoice | bank import reconciles it; cash only through the cashbook | no payment through the field-collection screens (F7) |
| 7 Sell on | the outlet | a retail sale may be recorded for traceability and activations; **no money to DishNet** | owner is the partner: status `sold`, no movement of DishNet stock | — | none for DishNet | a price rule applies only if the agreement sets one |

**Commission in wholesale:** normally none on hardware, because the partner's margin is the difference.
**Activation commissions** (step 7 → §11.4) are paid only if **D-7** says so.

### 11.2 Consignment: DishNet owns the stock until the agreed sale event

| Step | Who | Plugin record | Stock | uCRM | Accounting | Control |
|---|---|---|---|---|---|---|
| 1 Request | BM/HO (replenishment) or CM | `dist_orders` (`consignment`) | — | — | — | the agreement allows `consignment` |
| 2 Approve | CM + FO | `approved` | reserve | — | — | **exposure check:** consigned stock at cost + unsettled sales ≤ the limit |
| 3 Dispatch | WH | `dist_dispatches` (`consignment_transfer`) | warehouse → transit; **owner stays DishNet** | **nothing** | **nothing.** The stock is still DishNet's inventory, at cost, at a new location | a test: a consignment dispatch creates no uCRM write and no revenue row |
| 4 Receive | BM (portal) or WH | `received` | transit → outlet; owner DishNet | — | — | variance → approval |
| 5 Sell | BC/BM | `dist_retail_sales` `recorded`; the serial must be at this outlet, owned by DishNet, not sold | outlet → `sold` (`retail_sale` movement); owner DishNet until settlement | — | **none yet: an "unsettled sale"**, reported in H5 | the price is inside the approved retail rule, or a price exception needs approval |
| 6 Accrue | automatic | `dist_commission_accruals` (the rule in force **on the sale date**) | — | — | commission owed to the partner, recorded in the plugin | no rule → no accrual, and an exception |
| 7 Settle | FO prepares, DA or FO approves | `dist_settlements` over the period's confirmed sales | — | **one invoice to the partner**, per **D-2** and **D-7** (net, or gross with a commission credit note) | **revenue** at this invoice; **cost of goods sold** = the sold units' cost | each sale settles once; the approver is not the preparer |
| 8 Pay | the partner | — | — | payment on the partner's client | bank import | as §11.1 step 6 |
| 9 Return unsold | as §11.5 | RMA | outlet → transit → warehouse | — | none (DishNet always owned it) | — |

**What "the agreed sale event" can be (D-2), and what each option does:**
- **(a) the outlet's recorded sale, invoiced in batches.** A daily or weekly batch invoice to the partner.
  - Revenue sits close to the real sale.
  - uCRM holds more invoices.
- **(b) a periodic sell-through settlement**, weekly or monthly.
  - Fewer invoices.
  - Revenue lags the sale by up to one period.
  - The accountant must accept that lag.
- **(c) the end customer's activation.**
  - Not recommended as the only event: a kit bought for a customer's own Starlink account is never activated with
    DishNet, so the sale would never settle.

**Agency sale (DishNet invoices the end customer directly, and the partner collects for DishNet)** is possible in
this model, but not recommended to start with:
- It creates cash that DishNet owns in the hands of a third party. Only staff cash has a custody model today
  (`staff_ledger`, handovers), and it is weak (F-18).
- It creates a uCRM client for every walk-in buyer, even one who never takes a subscription.

### 11.3 Transfer between a partner's own outlets

1. BM requests.
2. HO or RD approves.
3. A `dist_dispatches` document (`branch_transfer`) moves the stock outlet A → transit → outlet B, and B's BM
   confirms receipt.
4. **The owner does not change:** DishNet for consignment stock, the partner for wholesale stock.
5. **No uCRM effect and no accounting effect.**
6. Transfers **between two different partners** are refused by the composite keys. A move like that is a return to
   DishNet followed by a new dispatch.

### 11.4 Retail sale with connectivity: no automatic activation

1. The outlet records the sale, with the serial and, when the customer wants service, a phone number and consent
   (**D-10**).
2. An activation request is created: `requested`.
3. **DishNet staff** run the **existing** KYC path. The phone dedupe decides between an existing uCRM client and a
   new registration (lib/KycService.php:481-615). The duplicate block and its logged override still apply.
4. **Staff create the uCRM service**, as today (lib/KycService.php:1985-1990).
5. `KitAttributeIntake` binds the kit (`equipment_assignments`). It refuses a kit held by another customer, a service
   that already has one, or a kit not in stock (lib/KitAttributeIntake.php:6-37).
6. The request becomes `active` only when all three ids exist: client, service, assignment.
7. An activation commission, if **D-7** approves one, accrues **at this point**, never at the hardware sale.

**The partner's sale is evidence of a sale, not an instruction to activate.** Nothing in the distribution module
calls a uCRM service write.

### 11.5 Returns

- **From an outlet, faulty or unsold:**
  1. BM/HO requests an RMA.
  2. CM approves.
  3. A return dispatch goes outlet → transit → **quarantine** at the warehouse.
  4. WH inspects: back to stock (`restocked`), write-off, or return to the supplier.
- **Wholesale units:** the owner goes back to DishNet, and FO issues a **uCRM credit note** against the original
  invoice, through a `dist_ucrm_writes` claim. Cost of goods sold is reversed at the recorded unit cost.
- **Consignment units:**
  - not yet sold → no credit note (DishNet always owned them);
  - sold but not settled → the sale is cancelled and its accrual reversed;
  - settled → a credit note on the settlement invoice, and the accrual reversed.
- **Returns to a supplier** reuse the existing `write_off` and return states, with a `return_to_supplier` movement.

### 11.6 Adjustments and counts

A count produces variances. Each variance is an adjustment **request**, and FO or DA approves it. Only then does a
`count_variance` or `adjustment` movement post. **No path changes a balance without a movement.** No movement is
edited, and a correction is a new movement.

### 11.7 Replenishment

1. Nightly, the stock levels are compared with what each outlet holds.
2. Staff get an alert through `AlertService`: staff-class text, no money (root docs/28 §I).
3. The outlet sees a suggestion in the portal (Phase 6).
4. A replenishment request becomes an order, then a dispatch, as above.

### 11.8 The settlement cycle and overdue alerts

- **Each period:**
  1. The job builds a draft settlement for each consignment partner.
  2. FO reviews it; DA or another FO approves.
  3. The plugin creates the uCRM invoice.
  4. The statement goes to the partner's authorised e-mail.
- **Daily:**
  - Partner invoices past due, from uCRM's invoice data for partner clients, raise a staff alert.
  - So do sales left unsettled beyond the cycle, and dispatches left unreceived beyond N days.
- **Each alert has a per-key cooldown** (`AlertService::notify`, lib/AlertService.php:52-86).

---

## 12. uCRM API and accounting integration map (deliverable 11)

### 12.1 Every distribution feature, mapped to a uCRM call the plugin already makes, or marked NV

| Feature | uCRM call | Used today by | Status on Uganda 4.5.33 | Protection against a duplicate |
|---|---|---|---|---|
| Find a partner's client | `GET clients?search=` / `?query=` / `clients/{id}` | lib/KycCrmSync.php:483; lib/EmailCustomerMatcher.php:122 | working (KYC) | — |
| Create a partner's company client | `POST clients` (`clientType` 2, company fields) | `POST clients` with type 1 (lib/KycService.php:721) | **company fields on create: NV-6** | `dist_ucrm_writes` claim + TIN check + a search first |
| Mark it `dnPartnerCode` | `POST custom-attributes` once; the value through `PATCH clients/{id}` | tools/efris_setup_attributes.php:33-50; lib/UcrmClientTarget.php:66-75 | the pattern works (EFRIS attributes exist on Uganda) | an idempotent PATCH |
| File the signed agreement | `POST clients/{id}/documents` | lib/CrmApiClient.php:378-434; lib/KycService.php:906 | working (KYC documents) | claim |
| Read the partner's balance | `GET clients/{id}` → `accountBalance`, `accountOutstanding` | includes/post/post_field.php:903; webhook.php:338 | working | — |
| Proforma for an order | `POST billing/quotes` | lib/QuotationService.php:656 | working (quotes are sent) | claim |
| Wholesale or settlement invoice | `POST invoices` with `clientId` and explicit `invoiceItems` | lib/FtthCrmService.php:200-222 (South Sudan only) | **NV-3** | claim + a unique `ucrm_invoice_id` on the document |
| Read the partner's invoices | `GET invoices?clientId=` | lib/ClientInvoiceCacheRefresher.php:90 | working | — |
| A payment on a partner invoice | `POST payments` with `invoiceIds`, through `createPaymentSafe` | includes/api/api_retailer.php:143-156 | working; the method UUIDs are uCRM's (lib/PaymentUuids.php) | `createPaymentSafe` + claim. **Never** through field collections |
| Credit note | `POST billing/credit-notes` | includes/api/api_payments_admin.php:530-531 | used; **against a partner invoice: NV-4** | claim |
| Catalogue | `GET products`, `GET service-plans` | prices.php:61-62; lib/DishNetTools.php:399 | working | read only: **the module writes no price** |
| The end customer's service | created **by staff in uCRM**; read by `GET clients/services?clientId=` | lib/KycService.php:1985-1990; lib/UcrmCustomerDataGateway.php:240 | working | `equipment_assignments` unique indexes |
| Events | `client.add/edit`, `invoice.add`, `payment.add`, `credit_note.add` | webhook.php | working, re-read from uCRM | partner clients get the partner branch (X7) |
| **Not used and not needed** | organisation writes; service creation; the client-contacts endpoints; tag creation | — | — | — |

### 12.2 Which records belong where

| Record | Master | The plugin keeps | The existing accounting integration |
|---|---|---|---|
| The partner as a billing party | **uCRM client** | the link, its terms, and who linked it | the uCRM balance is the partner receivable |
| Terms, price lists, commission rules | **plugin** | — | every change goes to `fin_audit` |
| Stock, locations, owner, movements | **plugin** (B-1) | — | valued at existing costs (`PurchaseService`) |
| Supplier purchases and payables | **plugin** (`PurchaseService`) | — | bank import matches supplier debits |
| Wholesale invoice; consignment settlement invoice | **uCRM** | `ucrm_invoice_id` + the claim | sales on invoice basis (ReportingService); money by bank import, or the cashbook if cash |
| Partner payments | **uCRM** | reference | bank import; the cashbook only for cash, as today (webhook.php:1156-1159) |
| Credit notes | **uCRM** | id, and the return or cancellation that caused it | reports |
| Commission accruals | **plugin** (a sub-ledger, not a general ledger) | — | settled by **D-7**: netted on the uCRM invoice, a uCRM credit note, or a cashbook payout |
| Outlet retail sales | **plugin** | — | never DishNet revenue by themselves (§12.3) |
| The end customer and their subscription | **uCRM** | activation links | recurring invoices, unchanged |
| Fiscal receipts (EFRIS) | plugin `efris_transactions` | — | **Blocked**: production is refused (F-23) |
| Approvals and audit | **plugin** (`dist_approvals`, `fin_audit`) | — | — |

**Nothing here creates a general ledger, a second invoice or a second payment.** The plugin's distribution tables
are an operational sub-ledger (stock, terms, commissions). Money documents stay in uCRM. Cash stays in the cashbook.

### 12.3 How value, cost, commission, tax and revenue are handled — inside the existing architecture

| Topic | Treatment | Why it fits what exists |
|---|---|---|
| **Stock valuation** | Unchanged: serial units at their own cost, bulk at the category's weighted average (lib/PurchaseService.php:260-328). Added: value by **location and owner**. Consigned stock is still DishNet inventory, at cost. Wholesale-sold stock leaves it at cost. **Always per currency** | Reuses the costing. Fixes F-13 for distribution reports (no cross-currency sum) |
| **Cost of goods sold** | Recorded as **data** on the movement that transfers ownership (`unit_cost_minor`), linked to the invoice that earned the revenue. ReportingService computes **distribution margin per currency** = invoice lines − the cost of the linked units | Today `grossMargin()` is "not available" only because units are not linked to invoice lines (lib/ReportingService.php:281-301). Distribution makes the link. **It is a figure, not a ledger entry.** Retail margin stays "not available" |
| **Revenue** | **Recognised at the uCRM invoice.** That is the basis ReportingService already uses for sales (lib/ReportingService.php:40-45, 118-151). Wholesale: at dispatch or at receipt (**D-3**). Consignment: at the sale-event invoice (**D-2**). **Never at a consignment dispatch** | The plugin's own profit and loss is cash basis (lib/CashbookService.php:708-713) and stays so |
| **Commissions** | Accrued in the plugin on the sale or activation date, at **that date's rule**. Settled per **D-7**: (i) netted on the settlement invoice; (ii) a gross invoice plus a credit note; (iii) the partner invoices DishNet (a supplier bill), if the partner must charge VAT on its commission | No existing engine is reused (F-17). The cashbook's "Customer Commission" category posts a uCRM **refund** (includes/post/post_cashbook.php:538-631), which is the wrong document for a partner |
| **Taxes** | **uCRM computes invoice tax** once tax rates exist. The plugin never computes VAT; it sends `taxable` per product as **D-6** decides | uCRM has no tax rates, and the plugin pushes every product non-taxable (F-22). Until D-6, partner invoices would carry VAT-inclusive, non-taxable lines, as retail does |
| **Cash custody** | Partner money never enters a staff wallet, a collection or the staff ledger. It arrives in uCRM (bank, mobile money, cash) and is matched by bank import or the cashbook | The field-collection path credits a wallet and a staff commission (F-18). Mobile money is booked as Cash today (F-24), so NV-11 asks for a uCRM mobile-money method |

### 12.4 API limits and compatibility on 4.5.33

| Limit | Evidence | Consequence |
|---|---|---|
| No idempotency keys | lib/CrmApiClient.php (none sent) | every write is claimed in `dist_ucrm_writes` first. An `unknown` result is looked up by the reference in `adminNotes`, never blindly retried |
| No rate-limit handling | lib/CrmApiClient.php:438-472 | distribution writes are few (one invoice per dispatch or settlement). Batch jobs pace themselves inside the 300-second budget |
| Payloads refused on 4.5.33: `maturityDays` on quotes, a non-UUID payment method, a payment to a lead, a product `description`, a PDF of a draft quote | lib/KycService.php:958, 1639; lib/CrmApiClient.php:93-95; lib/QuotePdfSource.php:71; root docs/20:28-33 | the partner writers use the forms proven today, and the fake uCRM in the tests enforces the same refusals |
| Service status numbers read differently in different files | includes/api/api_scheduling.php:258; includes/api/api_whatsapp.php:76, 303; includes/post/post_kyc.php:362-364 | activation reads one mapping, verified once (NV-9) |
| Large single-page reads | cron_invoice_notify.php:84 | partner reads always filter by `clientId` |
| The recorded API surface is out of date | root docs/27 §24 lists a suspend call that does not exist; docs/98 §2.6 says API v1.0 | this report's §12.1 is from the code at `fe6471e` |

### 12.5 Tax and EFRIS — the one real block

- **Wholesale buyers such as fuel-station chains and supermarkets are likely VAT-registered** and may require
  fiscal (EFRIS) invoices to reclaim input tax.
- The repository shows two things:
  - **uCRM has no VAT configured** (docs/UGANDA-VAT-CONFIGURATION.md:19-37).
  - **The plugin refuses to call EFRIS production** (lib/EfrisClient.php:18). Its EFRIS work is in the test
    environment, with credit notes sent by hand (tabs/admin/efris.php:100-116).
- So **invoicing a VAT-registered partner correctly is Blocked**, by a tax configuration and an EFRIS go-live that
  are outside this plan.
- **What the plan does without it:**
  - The stock, consignment and commission layers are built.
  - Partner invoices go only to partners that **D-6** clears.
  - Nothing computes VAT in the plugin.
- EFRIS goods stock (T131) stays as it is. If it is ever needed, it is driven from the stock journal, not a third
  count (F-23).

---

## 13. Risks, compatibility issues and limitations (deliverable 12)

| # | Risk | Weight | Mitigation | When |
|---|---|---|---|---|
| R-1 | **Staff-side security gaps** (PD-1 to PD-9) expose partner and financial data once it exists | High | Phase 0 fixes them, each with a test and a weakened copy. PD-1 is recommended as a separate fix now | 0 |
| R-2 | **Partner isolation is enforced in PHP**; SQLite has no row-level security | High | One registry, one scoped data layer, composite keys, an action × role × foreign-id test matrix, weakened copies (§10.3) | 6 |
| R-3 | **uCRM API capabilities unproven on 4.5.33**: company fields on create, invoice creation, partner credit notes | High | NV-3, NV-4, NV-6 before Phase 3, on a test client with approval, or a disposable uCRM (docs/98 Q2) | 0 |
| R-4 | **Tax and EFRIS** block fiscal partner invoices | High | **D-6**; §12.5 | 0 → 4 |
| R-5 | **No general ledger and no cost of goods sold**: accounting judgement is needed | Medium | the §12.3 treatment is approved by the accountant (**D-2, D-3, D-7**); margin as a computed figure only | 0 |
| R-6 | **Duplicate serials may already exist**; a unique index may fail and, under today's runner, vanish | High | a read-only census first (NV-8); the strict migration mode (X12); duplicates resolved one by one by a person, never automatically | 0 → 2 |
| R-7 | **A third kit register** (the sibling Finance plugin's `sl_kits.json`) | Medium | B-1 stands: the plugin's register is the master. Distribution adds no register; the siblings read the published one (root docs/38:445) | 2 |
| R-8 | **Several price writers**: uCRM products, plugin screens pushing prices, stock categories, JSON catalogues | Medium | the distribution module writes no uCRM price. Partner prices live only in `dist_price_*`. The retail price is uCRM's | 3 |
| R-9 | **The residential-to-company flip** corrupts customer type and EFRIS buyer type, and would mis-describe partners' customers | Medium | X6 fixes the lead test with `isLead`. Existing flipped clients are **listed, not rewritten** (**D-14**) | 0 |
| R-10 | **Overlapping payment retries** could double a partner payment | Medium | partner payments never enter those queues; they use `createPaymentSafe` + a claim | 4 |
| R-11 | **Retail messages to partner clients** (welcome, invoice WhatsApps, reminders, suspension logic) | Medium | X7: excluded or partner-specific, tested through the real webhook handler | 1 |
| R-12 | **SQLite, one writer, and the same failure domain as billing**: a partner-portal spike or bug affects staff | Medium | real SQL tables with indexes (never `SqliteStore::load()` of a whole table); rate limits; paging; measured in the pilot; the move to PostgreSQL recorded as the exit (root docs/15) | 6–7 |
| R-13 | **A rollback restores code, not schema** | Low | additive migrations only; older code ignores new tables; **no rollback deletes business records** (§16) | all |
| R-14 | **The same code may run in South Sudan** (whether Installation A runs it is unaudited, docs/00:88-105) | Medium | a `DistributionGate` true only on Uganda; South Sudan golden tests unchanged | 0 |
| R-15 | **The partners are prospective**: terms may change after the build | Medium | every term is data (agreements, lists, rules). A pilot with one partner before any portal. Nothing is named after a partner | all |
| R-16 | **End-customer personal data at outlets** | Medium | capture the minimum (**D-10**); outlets see only the sales they recorded; phone numbers kept in international form and never used for matching by outlets | 3, 6 |
| R-17 | **Mobile money booked as Cash** inflates cash custody | Medium | NV-11: a uCRM payment method for mobile money before partners pay that way | 4 |
| R-18 | **Starlink self-activation**: a kit sold may never be activated with DishNet | Low | the sale event is not activation (D-2); H9 reports hardware against activations | 3 |
| R-19 | **The deploy copy never deletes and ships extra folders** | Low | unchanged; noted for a separate clean-up | — |
| R-20 | **The manifest version key is misspelt**; there is no version check at run time | Low | the Phase 0 doctor reads `GET version` and reports it; no manifest change | 0 |
| R-21 | **A misused organisation** (the Org-7 pattern) could return | Low | a design rule (§9.1a) and a test that no distribution code sends `organizationId` | 1 |

**Limitations that remain after the plan:**
- Isolation is application-enforced (R-2).
- VAT and EFRIS depend on decisions outside the plugin (R-4).
- uCRM cannot show outlets, since there is no client hierarchy.
- The partner portal lives inside the uCRM plugin's failure domain unless **D-9a** chooses otherwise.

---

## 14. Proposed implementation phases (deliverable 13)

**Your six phases, reordered where the dependencies require it:**

| Here | Your phase | Why it moved |
|---|---|---|
| **0 Foundations** | — (new) | Distribution endpoints would inherit PD-1 to PD-9. A unique serial index needs the census and the strict runner first. uCRM's invoice and credit-note behaviour must be verified before anything depends on them |
| **1 Partners and outlets** | 1 | — |
| **2 Locations, stock journal, serials** | 2 | — |
| **3 Prices, orders, sales, returns, customer and service links** | 3 | Price lists are pulled in here from your section C, because an order cannot be priced without them |
| **4 Commissions, settlements, statements, accounting** | 4 | — |
| **5 Staff-operated pilot** | part of 6 | **Before any partner signs in.** A pilot run by DishNet staff, working from partner reports, proves the journal, the settlement and the uCRM documents with no external logins |
| **6 Partner portal, dashboards, outlet replenishment** | 5 | It opens only onto a ledger the pilot has reconciled. Staff low-stock alerts moved to Phase 2, because they need no portal |
| **7 Portal pilot, reconciliation, rollout, operating documentation** | 6 | — |

**Each phase is one plugin release** (5.19.x) with its own pinned deploy script. It is switched off by default and
enabled by a separate, recorded action (§16). **Each phase needs your approval to start**, and Phase 0 also needs
the decisions of §17.

### Phase 0 — Foundations (no partner feature is visible)

| # | Work | Needs |
|---|---|---|
| 0.1 | Close PD-2 to PD-9 (§7.2), each with its test and weakened copy. **PD-1 is recommended as a fix of its own, now** | approval of each change: each changes existing behaviour |
| 0.2 | `DistributionGate` (Uganda only) + a master switch `distribution_enabled` (off) + the `DistributionPermissions` registry + two new staff roles (`warehouse`, `channel_manager`) with no grants | — |
| 0.3 | X6: the lead test becomes `isLead`; a **read-only list** of clients already flipped to company | **D-14** |
| 0.4 | X12: strict migration mode for files 077 and later, and a schema-doctor check of every distribution table, index and trigger | — |
| 0.5 | A **read-only, masked census command** for the server, rehearsed like the earlier ones. You run it (§18) | you run it |
| 0.6 | Verifications NV-2 to NV-7 and NV-9 to NV-13 | some need a test client, with approval |
| 0.7 | The exact-money helper (integer minor units by ISO currency) | — |

- **Exit:** suite green twice under PHP 8.1 and the development PHP; the South Sudan golden test unchanged; the
  census returned; the decisions recorded.

### Phase 1 — Partners and outlets

- **Tables:** `dist_partners`, `dist_agreements`, `dist_regions`, `dist_outlets`, `stock_locations` (outlet kind
  only, for now), `dist_approvals`, `dist_ucrm_writes`.
- **uCRM:**
  - find, link or create the partner's **company** client, with a claim first;
  - the `dnPartnerCode` attribute;
  - upload the signed agreement.
- **The retail paths leave partners alone:**
  - X7: no retail WhatsApp or e-mail to a partner client, and partner-specific messages only if **D-10** asks for
    them;
  - X8: the KYC dedupe knows partner contacts.
- **Staff screens:** Partners, a partner page, Outlets, Agreements (draft → approve), and a staff alert for an
  agreement nearing expiry.
- **No stock and no money yet.**

### Phase 2 — Locations, stock journal, serial tracking

- **Locations:**
  - Uganda's warehouses become `stock_locations` rows.
  - Existing location references are mapped by a **reviewed** file (§16.4).
- **The stock tables (X1–X4):**
  - owner and custody;
  - transaction types;
  - append-only movements by trigger;
  - no clamping;
  - no deletion of units that have movements;
  - the product link.
- **The unique normalised serial**, only after the census is clean.
- **Dispatch, transit and receipt**, run by staff for now, with variances needing approval.
- **Returns (the stock side):** quarantine, then inspection and what is done with each unit.
- **Adjustments only by approved request.** Stock counts. Stock levels by location, with staff low-stock alerts.
- **Reports:** stock by warehouse, partner, outlet and owner; stock in transit and its age; adjustments and
  variances.

### Phase 3 — Prices, orders, sales, returns, customer and service links

- **Price lists:** tiers, retail rules, effective dates, approval.
- **Partner orders**, with a uCRM quote as the proforma.
- **Wholesale invoicing** through a claim. It needs NV-3 done and **D-3** and **D-6** decided.
- **Consignment dispatch**: tested to create **no** uCRM document and **no** revenue.
- **Retail sales:** recorded by staff for the outlet, or imported from a partner's sales report, with a dry run.
- **Activations:** a request, then the existing KYC path, a staff-created service, and the kit binding.
- **Cancellations, and credit notes for returns** (needs NV-4).
- **Reports:** sales by partner, outlet, product and period; hardware sold against activations.

### Phase 4 — Commissions, collections, settlements, accounting

- **Commissions:** plans and dated rules (no default); accruals once per source; reversals as new rows.
- **Consignment settlements**, where the preparer and approver differ, producing one uCRM invoice per settlement
  (**D-2**, **D-7**). Partner statements as PDF and e-mail.
- **Credit and money:**
  - the credit-limit check before a dispatch;
  - partner payments through uCRM (`createPaymentSafe` and a claim), never through collections;
  - overdue-settlement and unremitted-sale alerts.
- **Approvals** of price and credit exceptions and write-offs.
- **ReportingService distribution figures:**
  - sales, cost of goods sold and margin per currency;
  - partner receivables;
  - consignment exposure;
  - unremitted sales.
- **A reconciliation job:** stock against sales against activations against uCRM invoices.

### Phase 5 — Staff-operated pilot (one partner, one to three outlets)

- **How it runs:**
  - DishNet staff record dispatches, receipts and sales from the partner's daily report.
  - Reconciliation runs daily and settlement weekly.
- **Exit:**
  - four consecutive weeks of **zero unexplained variances** between the journal, a physical count and the partner's
    report;
  - each settlement matching its uCRM invoice and payments to the shilling;
  - no duplicate uCRM document.

### Phase 6 — Partner portal, dashboards, outlet replenishment

- **The portal of §10.3:** partner users, sessions, the registry dispatcher and scoped data access.
- **Screens** for HO, BM, BC and RD: stock, receipt confirmation, retail sale, return request, transfer request,
  replenishment request, statements for HO and RD, and dashboards.
- **It needs D-9a** (where it runs) **and D-9b** (the sign-in method). The Traefik route, if chosen, is its own
  approved production change.

### Phase 7 — Portal pilot, reconciliation, production rollout, operating documentation

- **Pilot and rollout:**
  - The pilot partner moves onto the portal.
  - Two further partners follow, then per-partner enablement (a partner's status set to `active`).
- **Runbooks:** daily reconciliation, month-end settlement, stock count, returns, partner user management, incident
  handling (a disabled user, a lost device, a disputed settlement).
- **Training material for staff and for partners.**

---

## 15. Automated tests and acceptance criteria for each phase (deliverable 14)

**Standards for every phase**, as the project already works:
- **How it is tested:**
  - one PHP test file per concern under `tests/`, run by `tests/run.sh`;
  - **the full suite twice**;
  - a lint under **PHP 8.1** (the server's version);
  - **the South Sudan golden tests unchanged**;
  - a fake uCRM, extended for company clients, invoice creation with lines, credit notes, and the 4.5.33 refusals
    (§12.4);
  - no network access.
- **Every control is proved load-bearing** by a **weakened copy** of the code: the control removed, the test shown
  to fail.
- **Every negative result carries a positive control in the same test.** A refusal counts only next to an allowed
  case.

| Phase | New test files (planned names) | Acceptance criteria (your section 7 criteria in bold) |
|---|---|---|
| **0** | `test_dist_gate` (true only on Uganda; South Sudan byte-for-byte) · `test_dist_permission_registry` (every distribution action and tab listed; API and page share one check; unlisted means refused) · `test_migration_strict` (a failing statement is rolled back and not recorded; the weakened runner fails the test) · `test_client_type_lead` (a payment no longer flips a residential client) · one test per PD fix, e.g. `test_collections_export_auth`, `test_login_answer_fields`, `test_no_token_in_pages`, `test_customer_360_scope`, `test_stock_api_roles`, `test_json_csrf_origin`, `test_stock_tab_escaped` · `test_money_minor` | every PD fix proved with a control; **no bypass of existing accounting and currency controls** (the currency sweep and cashbook suites unchanged); the census obtained |
| **1** | `test_dist_partners` (TIN and client-id uniqueness; prospect → active rules) · `test_dist_ucrm_claims` (a retry or timeout never makes a second client; an `unknown` result is looked up) · `test_partner_webhook_exclusion` (through the real `client.add` and `invoice.add` handlers) · `test_kyc_partner_phone` · `test_dist_outlet_scope` (an outlet cannot point at another partner's location) · `test_dist_agreement_rules` (one active per model; approver ≠ requester) · `test_no_organization_id` | **no duplicate customer records**; a partner client gets no retail message; every change of terms has a `fin_audit` row |
| **2** | `test_stock_journal_immutable` (UPDATE and DELETE refused; the copy without the trigger fails) · `test_serial_unique` (normalisation; duplicates refused) · `test_stock_no_negative` · `test_dispatch_receipt` (two steps; transit; variance → approval) · `test_owner_custody` (consignment keeps the owner; a transfer across partners is refused) · `test_stock_rebuild` (balances equal a replay of the journal) · `test_adjust_approval` · `test_low_stock_alert` (per location; cooldown) · pins on the existing install and checkout paths | **no stock movement without an auditable transaction; no duplicate serial-number allocation**; no unauthorised adjustment; the replayed journal equals the balances |
| **3** | `test_price_lists` (dates, tiers, no overlap, no default) · `test_orders` · `test_wholesale_invoice` (one invoice per dispatch under retries; snapshot prices) · **`test_consignment_no_revenue`** (a dispatch makes no uCRM write and no revenue figure; the copy that invoices at dispatch fails) · `test_retail_sale` (the serial is at this outlet, owned by DishNet, never sold twice; the client key makes a resend safe) · **`test_activation_not_automatic`** (a sale makes no uCRM client or service write; activation completes only with a client, a service and an assignment) · `test_returns_credit_note` (one credit note; cost of goods sold reversed) | **no duplicate customer, service, invoice or payment records; no incorrect revenue recognition on consignment dispatch**; no activation without the workflow |
| **4** | `test_commission_rules` (the rule on the sale date; no default; tiers; clawback) · `test_accrual_once` · `test_settlement` (a sale settles once; approver ≠ preparer; one invoice per settlement) · `test_credit_limit` · `test_partner_payment_path` (no wallet, no staff commission, no collection record) · `test_distribution_reporting` (per currency; "not available" not 0; margin; unremitted sales) · `test_reconciliation` · `test_overdue_alerts` | statements tie to uCRM's invoices and payments; **no bypass of accounting and currency controls**; no double commission |
| **5** | scripted daily reconciliation checks (read-only), with their own tests | the Phase 5 exit (four weeks of zero unexplained variance; settlements to the shilling) |
| **6** | **`test_partner_isolation`**: every registry action × every role × a foreign partner's id and a foreign outlet's id → 404, with the partner's own id → 200 as the control; weakened copies each drop one scope predicate and must fail · `test_partner_session` (hashed token; revocation; expiry; status re-read live) · `test_partner_csrf_origin` · `test_partner_no_credentials` (neither the bundle nor any answer carries a token, key or secret) · `test_partner_rate_limits` · `test_token_planes` (a partner token is refused by the staff API and a staff token by the partner API) · a headless-browser run with no CSP violation | **no unauthorised cross-partner data access, proved by execution**; no privileged uCRM credential in a browser |
| **7** | the Phase 5 checks, repeated on the portal | the pilot exit met again; runbooks reviewed; a rollback rehearsed |

**And in every phase: compatible with uCRM 4.5.33 and with the actual deploy process.** Every release goes through
its pinned deploy script, rehearsed twice in the harness before it is handed over.

---

## 16. Deployment, rollback and data migration (deliverable 15)

### 16.1 Deployment

1. **One release per phase**, built from one commit. Its deploy script is pinned to that commit, as with 5.18.54:
   - the backup (`VACUUM INTO` plus the data tar);
   - a typed `DEPLOY`;
   - after-checks.
2. The **after-checks add a schema check**. Every table, unique index and trigger the release expects must exist.
   This is needed because migrations run themselves on the first tick.
3. **No manifest change**, so no ZIP. Distribution settings live in the plugin's store, not in `manifest.json`.
4. **Switched off at deploy.** `distribution_enabled` stays off until an administrator turns it on. That is a
   separate, recorded act, never part of the deploy command.
5. **The rollback is printed separately and needs a typed `ROLLBACK`**, never in the same copyable block
   (CLAUDE.md, "Always").

### 16.2 Migrations

- **Additive only.** New tables, new columns with defaults, new indexes and triggers.
- **No migration rewrites or deletes history.** The only updates are defaults for new columns, such as
  `owner_kind = 'dishnet'` on existing units.
- **Strict mode** (0.4): a file that fails is rolled back and not recorded, and the release will not switch on.
- **The unique-serial migration ships only when the census shows no duplicates**, or after a person has resolved
  each one (§16.4).
- **The `stock_quantities` key rebuild is a migration of its own.** Its before and after counts are checked by the
  deploy script.

### 16.3 Rollback

| What | On rollback |
|---|---|
| Code | the previous tree comes back (the script's `ROLLBACK`) |
| New tables and columns | **stay**, unused. Older code ignores them (the precedent of migration 073) |
| Distribution records written since | **kept**. Journals are append-only, and a rollback never deletes business records |
| uCRM documents created (clients, invoices, credit notes) | **stay in uCRM.** Nothing voids them automatically. The runbook tells staff how to settle any document that was in flight |
| Order of events | **switch off first**, then roll back. The gate also refuses to enable while the schema check fails |

### 16.4 Data migration

| Data | Plan |
|---|---|
| **Stock units** (**2** on 26 Sep; F-11) | `owner_kind = 'dishnet'`. The census lists every distinct `location_type` / `location_ref` / `location_name`. **A person maps each to a new location code** in a reviewed file. No guessing, and no South Sudan warehouse constant is used on Uganda |
| **Duplicate serials** | Listed by the census, normalised. **Each is resolved by a person** (a typing error corrected, or a phantom unit written off with `fin_audit`) **before** the unique index. Nothing is merged automatically |
| **Partners** | **None exist on Uganda.** The Org-7 retailers are South Sudan (F-4). Partners are entered, not migrated. An optional prospect import runs a dry-run report first |
| **uCRM** | **Nothing is migrated or rewritten.** Clients already flipped to company (R-9) are listed only (**D-14**) |
| **Kits in the sibling Finance plugin** | **Not imported.** B-1's intake path stays the way a kit enters the plugin's register |
| **Past sales** | No cost of goods sold is back-filled. Margin before distribution stays "not available" |

### 16.5 Environments

- **Development and tests** run in this repository.
- **Every deploy script is rehearsed** in a harness that refuses to run on the server.
- **There is no staging uCRM.** So NV-3, NV-4 and NV-6 need either a **test client on the live uCRM**, with your
  approval as for the earlier job walk-through, or a **disposable uCRM** (docs/98 Q2, never answered).

---

## 17. Decisions required (business approval)

**The recommendations are proposals, not decisions.** Nothing is built on any of them until you say so.

| # | Decision | Options | Recommendation |
|---|---|---|---|
| D-1 | The commercial model for each partner type | wholesale · consignment · both | decide per agreement. Start the pilot with **consignment**, which exercises every control |
| D-2 | What "the agreed sale event" is in consignment, and how often DishNet invoices | outlet sale in a daily batch · periodic sell-through · activation | **the outlet's recorded sale, invoiced in a weekly batch** (§11.2) |
| D-3 | When title passes in wholesale | dispatch · receipt | receipt, if the partner carries no transit risk; otherwise dispatch |
| D-4 | Whether regional distributors' own sub-resellers are visible to DishNet | no · yes (a parent partner) | **no** until a real distributor asks. The schema does not reserve it |
| D-5 | Who is billed | one uCRM client per legal entity · one per outlet | **one per legal entity**; per outlet only where an outlet pays separately |
| D-6 | VAT on wholesale, settlements and commissions; uCRM tax set-up; EFRIS production | the accountant's decision | **required before any partner invoice** (§12.5) |
| D-7 | How commission is paid; activation or recurring commissions; clawback | net on the invoice · a credit note · the partner invoices DishNet | **net on the settlement invoice**, if D-6 allows it |
| D-8 | Credit-limit policy | block the dispatch · FO approval above the limit | **FO approval above the limit**, recorded as an exception |
| D-9a | Where the partner portal runs | P-A on its own hostname · P-A' on the uCRM address · P-B a separate service | **P-A on its own hostname** (§10.3) |
| D-9b | How partner users sign in | a code to the phone · a password + TOTP · both by role | **a code to the phone, plus TOTP for HO and RD** |
| D-10 | What an outlet may capture and see of an end customer; partner-facing messages | — | **the minimum**: a name and phone only when connectivity is wanted, with consent; outlets see only their own recorded sales |
| D-11 | Retail price rule | a fixed retail price · a band · free | **a band** (minimum and maximum), with exceptions approved by CM |
| D-12 | Returns policy | windows, damage liability, restocking | the business decides; the system enforces it |
| D-13 | Uganda's warehouse locations and their codes | — | named by operations |
| D-14 | The lead test fix, and what to do about clients already flipped to company | fix and list · fix and correct | **fix and list.** Any correction is a separate, approved act |
| D-15 | A channel manager's scope | all partners · only those assigned | all read, and write only to those assigned |

---

## 18. Verification items (NV) — read-only unless stated

| # | Question | How | Who |
|---|---|---|---|
| NV-1 | Is uCRM still 4.5.33? | `GET version`, read-only | the census |
| NV-2 | Can a uCRM user be limited to some clients? | one screen in uCRM: users, groups and permissions | you |
| NV-3 | Does `POST invoices` with explicit lines work on 4.5.33, with Uganda's one organisation? | a **draft** invoice on a test client | **needs approval** (it writes to uCRM) |
| NV-4 | A credit note against a partner invoice | as NV-3 | **needs approval** |
| NV-5 | Can the plugin create a client attribute and set it? | already proven by the EFRIS attributes; reconfirm read-only | the census |
| NV-6 | Are company fields accepted on client create? | as NV-3 | **needs approval** |
| NV-7 | Is a client-zone plugin page supported? | uCRM plugin settings, one screen | you (only needed if partners should see invoices in uCRM) |
| NV-8 | Stock: unit count, duplicate normalised serials, locations in use, live assignments | the read-only census, masked | you run it |
| NV-9 | The mapping of uCRM service status numbers | `GET` of a known active and a known suspended service | the census |
| NV-10 | Does Installation A (South Sudan) run this codebase? | docs/00:88-105 says unaudited | you |
| NV-11 | Which payment methods uCRM has; is there a mobile-money method? | `GET payment-methods`, read-only | the census |
| NV-12 | Which products and plans exist for hardware, accessories, fibre and installation? | `GET products`, `GET service-plans` | the census |
| NV-13 | Does the emergency-repair key file exist, and is `crm_webhook_key` set? | exists: yes or no, **never the value** | the census |

**The census is one read-only command, rehearsed first.** It prints counts and yes/no answers only, and no personal
data or secret. It asks for its **log file**, never a copy of the terminal.

---

## 19. What this audit did not do, and what happens next

- **Not done:**
  - No code, migration, setting, uCRM record or server was changed or contacted.
  - No test was run for this audit.
  - PD-1 was found by reading and was **not reproduced**.
  - No uCRM documentation was read. This session's network policy refuses it, as root docs/45 records.
- **Next, in order, and only on your approval:**
  1. **PD-1**, as its own small fix, recommended now.
  2. **The decisions of §17.** D-6 needs DishNet's accountant.
  3. **The census and verifications of §18.** The census command is written and rehearsed first.
  4. **Phase 0**, then each phase in turn, each stopping for review.

