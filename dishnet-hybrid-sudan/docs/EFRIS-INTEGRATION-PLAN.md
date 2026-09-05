# EFRIS Fiscalisation — Integration Architecture (Phase 0 study)

Status: **awaiting operator approval — no plugin code changed yet.**
Scope: connect the existing DishNet uCRM plugin to Uganda Revenue Authority
EFRIS so uCRM invoices become fiscalised e-invoices. Nothing in uCRM's own
billing (customers, services, quotes, invoices, payments) is replaced.

Seller of record: DishNet Africa Limited, TIN 1059140632, Kampala, Uganda.

---

## 1. Current plugin architecture (as inspected)

- **Runtime**: PHP 7.4-compatible, no composer, no namespaces. Classes live
  flat in `lib/` (one class per file), page code in `tabs/<area>/<page>.php`,
  routed through `public.php?page=…` (the ONLY file uCRM serves). CLI tools in
  `tools/`, scheduled jobs in `cron/` + root `cron_*.php`.
- **Scheduling**: uCRM calls `main.php` every 5 min (`manifest.json
  executionPeriod: 5`); `main.php` includes `cron/master.php`, which runs each
  job on its own interval from a schedule map (e.g. `'ai_reply' => 60s`).
  No server crontab.
- **Storage**: one SQLite database (`plugin.sqlite3` in the uCRM-assigned data
  dir) behind `lib/SqliteStore.php` — real tables created via the
  `_migrations` mechanism, plus a JSON-collection API
  (`load/save/findOne/appendWithId`) for lightweight stores
  (e.g. `invoice_notify_log.json`, `web_chat_leads.json`).
- **uCRM API**: `lib/CrmApiClient.php` — local API `/api/v2.1` with
  `X-Auth-App-Key` from `ucrm.json`; `get/post/patch/delete`,
  `getRawContent()` (used for invoice PDFs), **idempotent payment creation**
  (`createPaymentSafe` + `findExistingPayment`), client document upload,
  and full uCRM **webhook management** (`autoSetupWebhook`).
- **Events from uCRM**: `webhook.php` receives uCRM webhooks
  (`{changeType, entity, entityId, extraData}`) and routes
  `client.add`, `invoice.add`, `invoice.edit`, `invoice.draft_approved`,
  `invoice.near_due`, `invoice.overdue`, `payment.add`, … to
  `NotificationService`/cashbook.
- **Internal queue**: `lib/EventBus.php` + `events` table; workers consume
  typed events (pattern: `evo_webhook.php` emits `ai.reply`,
  `workers/AiReplyWorker.php` consumes via `run_worker.php`).
- **Config**: uCRM plugin Configuration (declared in `manifest.json`) merged
  by `lib/PluginConfig.php`; secrets additionally persisted by
  `lib/ConfigVault.php` (`VAULT_KEYS`) so they survive re-install.
- **PDF engine**: `lib/PluginQuotePdf.php` — HTML template
  (`templates/quote_dishnet.html`, `{{PLACEHOLDER}}` substitution) rendered
  by **wkhtmltopdf** already present on the uCRM server.
- **Delivery**: WhatsApp via Evolution API (`EvolutionApiService::sendText/
  sendMedia`), email via `lib/MailService.php`, orchestrated by
  `NotificationService`; PDFs exposed through short-lived HMAC-tokened links
  under the plugin data dir.

## 2. Current invoice workflow

1. uCRM generates invoices natively (manual, recurring, draft→approved).
2. `webhook.php` `invoice.add` fetches the invoice
   (`GET invoices/{id}`, fallback `billing/invoices/{id}`), skips drafts,
   notifies the customer.
3. `cron_invoice_notify.php` (15-min scan) catches invoices uCRM creates
   **without firing webhooks** — fetches recent unpaid invoices, dedupes via
   `invoice_notify_log.json` (shared with webhook.php), downloads the uCRM
   PDF (`getRawContent("invoices/{id}/pdf")`), stores it under
   `data/temp_pdf/` with an HMAC token, sends WhatsApp text + PDF link.
4. Payments flow into uCRM (from MoMo flows/cashbook) through
   `createPaymentSafe`; `payment.add` webhooks post to the cashbook ledger
   (`source='crm_webhook'`) and refresh `ClientInvoiceCacheRefresher`.

**Implication for EFRIS**: webhook-only triggering is proven unreliable in
this install; the EFRIS layer must be **queue + scan**, exactly like invoice
notification already is.

## 3. Current quotation workflow

`lib/QuotationService.php` (items → totals → `nextQuoteRef` → optional
`createCrmQuote` in uCRM → log via JSON store) →
`lib/PluginQuotePdf.php` renders `templates/quote_dishnet.html` with
wkhtmltopdf (org block from config: `{{ORG_NAME}}`, `{{ORG_STREET}}`, …,
`{{CURRENCY_CODE}}` via `dn_code()`) → sent by WhatsApp
(`cron_quote_wa.php`). Quotes are **not** fiscal documents and stay outside
EFRIS scope (URA fiscalises invoices/receipts and credit/debit notes).

## 4. uCRM API endpoints the plugin already uses (reused by EFRIS)

- `GET invoices`, `GET invoices/{id}`, `GET invoices/{id}/pdf`
- `GET clients`, `GET clients/{id}` (incl. `attributes` — custom attributes
  are already written by `KycService::buildAttributes`)
- `GET payments` / `POST payments` (idempotent)
- `GET service-plans`, `GET products` (price feed / AI catalogue)
- `POST clients/{id}/documents` (file attach — can attach the fiscalised PDF
  to the client record)
- Webhook CRUD (`autoSetupWebhook`) — `invoice.add/edit`, `payment.add`
  already subscribed.

**Phase-0 verification task (server, read-only):** dump one real invoice JSON
(`docker exec ucrm php -r '...CrmApiClient... get("invoices/{id}")'`) to pin
the exact tax fields uCRM emits on this install (`taxes[]`, `tax1..3`,
`taxableSupply`, item `tax` shape) before freezing the mapper.

## 5. Existing storage touched/extended

- New SQLite migration (via the existing `_migrations` mechanism):
  **`efris_transactions`** —
  `id, ucrm_invoice_id, invoice_number, request_id, kind
  (invoice|credit_note|debit_note|cancel), status, fdn, verification_code,
  qr_data, efris_reference, submitted_at, fiscalised_at, response_code,
  response_message, request_payload, response_payload, retry_count,
  created_at, updated_at` — UNIQUE(`ucrm_invoice_id`, `kind`) for
  idempotency; payloads stored verbatim for audit.
- New JSON store only if needed for the daily AES session key metadata
  (`efris_session.json`: key issued-at, expiry) — the key itself lives in a
  0600 file in the data dir, never in config or the DB.
- No other table is modified.

## 6. Exact files to modify (no rewrites)

| File | Change |
|---|---|
| `manifest.json` | add EFRIS Configuration keys (see §11) |
| `lib/ConfigVault.php` | add EFRIS secret keys to `VAULT_KEYS` |
| `cron/master.php` | add `'efris' => ['interval' => 120, run_worker or cron/efris_sync.php]` |
| `webhook.php` | `invoice.edit`: if invoice already FISCALISED and totals/items changed → mark transaction `NEEDS_ADJUSTMENT`, alert admin (never auto-resubmit); `invoice.add`/`draft_approved`: enqueue `efris.submit` when auto-submit is enabled |
| `cron_invoice_notify.php` / `NotificationService` | delivery wording: fiscalised → "Your DishNet Uganda e-invoice is ready" + fiscal PDF link; not fiscalised → current wording, never described as a fiscal invoice |
| `public.php` | routes: `?page=efris_pdf&token=…` (HMAC-tokened fiscal PDF download, same pattern as temp_pdf), admin module registration for the EFRIS tab |
| `production-preflight.php` | new `== efris ==` section (enabled, env, creds masked, T101 reachability, queue depth, last fiscalisation) |

## 7. New files (following existing conventions — flat `lib/`, no namespaces)

- `lib/EfrisCrypto.php` — RSA (taxpayer keypair) + AES payload
  encrypt/decrypt + RSA-SHA1 PKCS1 signing, gzip handling. Pure OpenSSL ext.
- `lib/EfrisClient.php` — HTTP envelope
  (`globalInfo{interfaceCode, tin, deviceNo, requestId, …} +
  data{content, signature}`), T101 (server time / reachability),
  T103/T102 (init/auth as the official spec requires), T104 (daily AES key),
  generic `call($interfaceCode, array $payload)`. Test/production base URL
  from config. **No interface code is invented: the exact request/response
  field lists are transcribed from the official URA spec + the official
  `ura-sw` sample client once URA credentials arrive.**
- `lib/EfrisInvoiceMapper.php` — uCRM invoice JSON → EFRIS T109 payload
  (mapping table §9); validation (TIN format, tax category present, currency)
  with human-readable failure reasons. Never sends — pure transform.
- `lib/EfrisService.php` — orchestration: idempotent submit (check
  `efris_transactions` first), status machine
  `NOT_SUBMITTED → PENDING → SUBMITTED → FISCALISED | REJECTED | ERROR`
  (+ `CANCELLED / CREDITED / DEBITED / NEEDS_ADJUSTMENT`), retry with
  backoff, stores **verbatim** EFRIS responses (FDN, verification code, QR
  data, timestamps) — nothing synthesised, ever.
- `lib/EfrisStore.php` — repository over `efris_transactions` (+ migration).
- `workers/EfrisWorker.php` + wiring in `run_worker.php` — consumes
  `efris.submit` events; `cron/efris_sync.php` — the scan safety-net
  (auto-submit eligible invoices missed by webhooks, refresh PENDING).
- `lib/EfrisQr.php` + one vendored single-file pure-PHP QR encoder (license
  header kept) — renders the QR **from the QR payload EFRIS returns** in the
  fiscalisation response. If the spec returns a ready image, we embed it
  as-is and the encoder goes unused.
- `templates/invoice_fiscal.html` — fiscal e-invoice template for the
  existing wkhtmltopdf engine (clone of the quote template layout): seller
  block (DishNet Africa Limited, TIN 1059140632), buyer block, items with
  tax category/rate/amount, totals, payment status from uCRM — plus the
  EFRIS block: before fiscalisation a bold **"EFRIS STATUS: NOT FISCALISED"**;
  after, the actual FDN, verification code, QR and fiscalisation timestamp.
- `tabs/admin/efris.php` — admin module: settings status, **EFRIS
  Transactions** table (Date, Invoice, Customer, Amount, EFRIS Status, FDN,
  Submitted, Response, Actions), per-invoice actions
  ([Submit to EFRIS] [View EFRIS Status] [Retry] [Download Fiscalised
  Invoice] [View Fiscal Information] [View EFRIS Error]), report counters
  (fiscalised/pending/rejected/errors/credit/debit) with date/customer/
  status filters. All actions POST + CSRF (`$_csrf` pattern), admin-only.
- `tools/efris_preflight.php` — CLI doctor: creds present (masked), T101
  round-trip, T104 key age, queue depth, last N transactions.
- `tests/test_efris_mapper.php`, `tests/test_efris_service.php`,
  `tests/test_efris_endpoint_guard.php` — run against a **fake EFRIS server**
  (local `php -S` fixture, same pattern as `test_web_chat_endpoint.php`).

## 8. EFRIS integration architecture

```
uCRM invoice (approved)
   │  webhook invoice.add / draft_approved        cron/efris_sync.php scan
   ▼                                              (webhook-miss safety net)
EventBus 'efris.submit' ──► workers/EfrisWorker ──► EfrisService
                                                      │ 1. idempotency check (efris_transactions)
                                                      │ 2. EfrisInvoiceMapper (validate + map)
                                                      │ 3. EfrisClient (T104 key fresh? → T109 submit)
                                                      │ 4. store verbatim response → status
                                                      ▼
                                     FISCALISED: fiscal PDF (wkhtmltopdf) →
                                     NotificationService (existing WA/email path)
                                     + uCRM: client document attach; FDN +
                                     verification code into invoice custom
                                     attributes/note for visibility in uCRM
```

- **Idempotency**: UNIQUE(ucrm_invoice_id, kind); a second submit of a
  fiscalised invoice returns the stored fiscal record, no API call.
- **uCRM PAID ≠ FISCALISED**: the EFRIS status lives only in
  `efris_transactions`; uCRM's own status field is never touched.
- **Manual vs automatic**: config `efris_auto_submit` (off by default);
  manual [Submit to EFRIS] always available. Nothing submits while
  `efris_enabled` is off.
- **Failure handling**: timeouts/network/auth failures → `ERROR` with the
  real message, bounded retries with backoff via the worker; REJECTED is
  terminal until data is corrected; nothing is ever marked FISCALISED
  without EFRIS's own success response.
- **Credit/debit notes & cancellation**: fiscalised invoices become
  read-only at the plugin level — `invoice.edit` on one flags
  `NEEDS_ADJUSTMENT` and the admin UI offers the EFRIS credit/debit-note
  flow instead; the adjustment transaction stores
  `linked ucrm_invoice_id` + original FDN permanently. The exact interface
  codes and approval flow for credit notes are **transcribed from the
  official spec** (public sources conflict; not guessed — see §13).

## 9. Data mapping (uCRM → EFRIS T109)

| EFRIS field (per official spec) | Source |
|---|---|
| sellerDetails.tin | config `efris_tin` = 1059140632 |
| sellerDetails.legalName / businessName | config (DishNet Africa Limited) |
| sellerDetails.address / mobilePhone / emailAddress | config (Mawanda Road office, info@dishnetafrica.com) |
| basicInformation.deviceNo | config `efris_device_no` (issued by URA) |
| basicInformation.invoiceNo (internal) | uCRM invoice `number` |
| basicInformation.issuedDate | uCRM invoice `createdDate` |
| basicInformation.currency | uCRM invoice `currencyCode` (UGX) |
| basicInformation.invoiceType/industry/deviceType… | per spec enumerations, config-driven |
| buyerDetails.buyerType | derived: business (has TIN) / individual |
| buyerDetails.buyerLegalName | uCRM client `companyName` or first+last |
| buyerDetails.buyerTin | uCRM client custom attribute **TIN** |
| buyerDetails.buyerBrn / buyerNinBrn | custom attributes **BRN** / **NIN** |
| buyerDetails.buyerAddress / MobilePhone / Email | uCRM client street/city, phone, email |
| goodsDetails[].item | uCRM item `label` (DishNet Home, Starlink Mini Kit, …) |
| goodsDetails[].qty / unitPrice / total / discount | uCRM item fields |
| goodsDetails[].goodsCategoryId (URA commodity code) | per-product mapping table in plugin settings (§10) |
| goodsDetails[].taxRate / tax | from uCRM item tax + tax-category mapping (standard 18% / zero-rated / exempt / non-VAT — **rates read from uCRM tax config, not hard-coded**) |
| taxDetails[] / summary | computed from items exactly as the spec's rounding rules require |
| payWay / payment info | uCRM invoice status + payments (paid / partial / unpaid → spec's payment codes) |

Exact field names/enumerations are frozen against the official spec during
implementation — this table fixes the **source of truth for each value**.

## 10. Data missing from uCRM today (to add — inside existing structures)

1. **Client TIN / BRN / NIN / taxpayer type** → uCRM **custom attributes**
   (created once via API; editable natively in uCRM's client form — no
   parallel customer store). Plugin reads them from `client.attributes`
   (mechanism already used by KYC).
2. **URA commodity/goods category code per product & service plan** →
   mapping table in the EFRIS admin tab (uCRM item name → goodsCategoryId),
   with a clear "unmapped item" validation error before submission.
3. **Tax category per uCRM tax** → small mapping (uCRM tax id → EFRIS
   category standard/zero/exempt/…): configured in the admin tab.
4. **Seller constants** → plugin Configuration (TIN, legal name, address,
   device number, environment, keys).

## 11. Security

- Credentials (`efris_tin`, `efris_device_no`, API base URLs, taxpayer
  **private key** path/PEM, any portal client secret) in plugin
  Configuration → persisted via `ConfigVault`; private key file 0600 in the
  data dir; **never** rendered in any tab, log, or frontend JS (existing
  `mask()` convention).
- All EFRIS actions: admin session + CSRF; no public route can trigger a
  submission; `efris_pdf` route is HMAC-tokened and read-only.
- Full audit: every request/response payload verbatim in
  `efris_transactions` (DB already chmod 640).
- Server-side validation before any submission; the browser never composes
  EFRIS payloads.
- Duplicate-fiscalisation impossible by DB constraint, not by UI politeness.

## 12. Testing plan

- **Unit/integration (repo, no URA)**: fake EFRIS server fixture (php -S)
  returning spec-shaped success / rejection / timeout / auth-failure /
  duplicate responses → the 17 scenarios you listed map onto:
  mapper validation (invalid TIN, unmapped item, tax category), idempotent
  double-submit, retry/backoff, PENDING refresh, credit/debit linkage,
  partial-payment payload, PDF renders both states, delivery wording.
  All wired into `tests/run.sh` (suite currently 693/0 — stays green).
- **Sandbox phase**: register on the EFRIS **test** environment
  (efristest.ura.go.ug), run `tools/efris_preflight.php`, fiscalise real
  test invoices end-to-end (create in uCRM → FDN + QR back → PDF → WhatsApp
  delivery), exercise credit note flow. Nothing touches production until
  sandbox passes the full checklist.
- **Production cut-over**: flip environment in config; preflight again;
  first N invoices manually submitted and eyeballed before enabling
  `efris_auto_submit`.

## 13. Facts verified vs. pending (nothing here is invented)

**Verified** (official URA sample client, `github.com/ura-sw`):
envelope `globalInfo{interfaceCode, tin, deviceNo, requestId} +
data{content: base64(AES-encrypted JSON), signature: RSA-SHA1 PKCS1}`;
T101 server time, T102 init/server public key, T103 sign-in, **T104 daily
AES session key (RSA-decrypted with taxpayer private key)**, T108 invoice
query, **T109 invoice upload**, T115 dictionary, T125 excise query,
T129 batch upload, T130 goods upload; responses base64 + AES + optional
gzip. Test portal: efristest.ura.go.ug.

**Pending official confirmation** (marked TBC in code until the spec/URA
onboarding pack is in hand): credit/debit-note and cancellation interface
codes and approval flow (public sources contradict each other), the full
T109 field list & enumerations, QR payload format in the response, offline
buffering rules, and the registration steps that issue the device number
and keys. **Required from URA/DishNet before production**: EFRIS portal
registration for TIN 1059140632, system-to-system integration application,
issued deviceNo, keypair/certificate, official API spec PDF, sandbox
credentials.

## 14. uCRM limitations to work around

1. uCRM's native invoice PDF cannot be modified by a plugin → the
   **fiscalised customer PDF is plugin-rendered** (existing wkhtmltopdf
   engine + `invoice_fiscal.html`); uCRM's own PDF remains for internal
   use; the fiscal PDF is also attached to the client's documents in uCRM.
2. uCRM webhooks demonstrably miss auto-generated invoices on this install
   → queue + 15-min scan (existing proven pattern) rather than webhook-only.
3. uCRM has no TIN/BRN/NIN fields → custom attributes (native UI, no new DB).
4. uCRM invoices stay editable after payment → plugin-level immutability
   watch via `invoice.edit` webhook + `NEEDS_ADJUSTMENT` flag.
5. Plugin has no composer → OpenSSL-ext crypto + one vendored single-file QR
   encoder, consistent with the codebase.
