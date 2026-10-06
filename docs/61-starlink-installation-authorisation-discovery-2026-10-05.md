# 61 — Starlink installation: customer authorisation, terms acceptance and technician notification — DISCOVERY and proposed design (2026-10-05)

**Status: DISCOVERY — APPROVED 2026-10-05 (D1–D11 as recommended; the terminology "Customer Installation Authorisation";
uCRM-side status changes out of scope) and then BUILT in development as plugin 5.18.82 — the build record and the final
report are `docs/63`, the terms draft is `docs/62`; hardened after its pre-release review as plugin 5.18.83 — `docs/64`
(D3 is now an explicit activation snapshot, and uCRM-side status changes, still not prevented, are recorded and alerted).
At the time this document was written: no code changed, no migration,
no commit, no push, no deploy, no configuration touched, no message sent.** This is STEP 2 of the emergency brief: what exists today around the Starlink Installation Job, read from
the code at the current working tree (plugin 5.18.81 locally; 5.18.74 live), and the additive design proposed on top of
it. STEP 3 — the operator's approval — comes before any code. Everything in §1 is VERIFIED from the files named;
§2–§6 are proposals and questions.

## 0. In one paragraph

The "Starlink Installation Job" is **a uCRM scheduling job** — the plugin holds no job table of its own. It is created
from My Jobs (`create_job`) or inside uCRM, carries a title from a template list ("Starlink Installation" plus the
client's name), a client, a date, an assignee and tasks, and moves through uCRM's three statuses: 0 (the plugin calls it
Pending), 1 (Open, which is **in progress** — the technician's **Accept Job** button, or a GPS check-in, puts it there)
and 2 (Closed, by **Mark as Completed**). The plugin keeps side records keyed by the uCRM job id: who was told what
(`job_notify_state`, `job_notify_events`), photos and completion position (084), completions, check-ins and an invoice
queue (JSON). On Uganda every job message goes through one component, `JobNotifier`, to the assignee uCRM names, by
WhatsApp (`NotificationService` → Evolution) and e-mail (`MailService`). **Nothing today asks the customer anything, no
charge sits on the job, there is no customer-facing job page, and the two server paths that start an installation check
only who the caller is.** The feature therefore adds, around the existing job: one acceptance record and its token, a
public acceptance page under `public.php`, a server-side guard on both start paths and on completion, versioned terms
with a hash, three messages through the existing send layers, a CRM panel, an append-only event table, and a flag that
ships OFF. Nothing existing is redesigned.

## 1. Discovery — the eighteen items, as found

| # | Item | Found (file) |
|---|---|---|
| 1 | **Job table / schema** | None in the plugin. The job is uCRM's `scheduling/jobs/{id}`: `id, title, description, clientId, assignedUserId, date, duration, status (0/1/2), address, gpsLat, gpsLon`; tasks `scheduling/jobs/{id}/job-tasks`; comments `job-comments`. Plugin side records keyed by job id: `job_notify_state`, `job_notify_events` (075/076), `job_photos`, `job_completion_gps` (084), JSON `job_completions.json`, `job_checkins.json`, `job_signatures.json`, `site_surveys.json`, `job_invoice_queue.json`, `scheduling_jobs_cache.json`. The **job number is the uCRM id** ("Job #950"; the invoice queue writes `JOB-<id>`). |
| 2 | **Creation flow** | `includes/api/api_scheduling.php` `create_job` (line 688): title typed or picked from `job_titles` (uCRM `scheduling/job-titles`, else a fixed list headed "Starlink Installation"), the customer by `crm_search_customer` → `crm_client_id`, date and time (Kampala → uCRM offset, J5), duration, description, one or more engineers (one uCRM job each), tasks. Uganda creates the job at status 0 so Accept shows, then `JobNotifier::observe()` sends message 1. Also `bulk_create_jobs`, and jobs made inside uCRM reach the plugin through `webhook.php` `job.add` (notifier, and the customer's `install_scheduled` e-mail when that switch is on). |
| 3 | **Statuses / state machine** | uCRM's three integers only. UI labels `0 Pending · 1 Open · 2 Closed`. **Start** = status 1, reached by (a) `scheduling_job_update` with `status: 'open'` and `notify_accept` — the technician's Accept button, which also sends message 2 (the completion link) — and (b) `install_checkin` (`includes/api/api_field_ops.php:210`), which patches status 1 on a GPS check-in. **Complete** = `scheduling_complete` (line 464): all tasks closed, Uganda photos and position, completion record, uCRM status 2, comment, WhatsApps, invoice queue, stock deduction. No plugin state machine; `job_notify_state` records who was told what. |
| 4 | **Customer relationship** | `clientId` on the job → uCRM `clients/{id}`: name, `contacts[0].phone / email`, street, city, `note` ("contains kit/package info"), `isLead`, gps. The job detail returns a safe subset (no balance). No plugin customer table; `CustomerIdentityService` mints the customer's DishNet e-mail identity; the customer portal recognises a customer by `CustomerSession` (073, HttpOnly cookie or Bearer). |
| 5 | **Technician assignment** | `assignedUserId` (a uCRM user) ↔ one active staff row in `retailers.json` with a VERIFIED `ucrm_link` (`StaffDirectory::byUcrmUser`, `linkedUcrmUser`, `phoneOf`, `email`; roles `support_engineer · support · support_leader · admin`). Reassignment is detected by `JobNotifier::observe()` from any path, the uCRM webhook included: `reassigned` → message 1 to the new assignee, "no longer assigned" to the old one. **The authoritative technician is always uCRM's current `assignedUserId`, read live** (R2, R3 of docs/44). |
| 6 | **Equipment / service** | Not on the job. Sources: the uCRM client `note`, `clients/{id}/services` (name, status), and the KYC application (`kyc_applications.json`: `device_title`, `device_price`, `hw_cart_json`, `kitQty`, `kitName`, `kitNumber`, `offer_name`, `offer_price`), which `scheduling_complete` already reads for the stock deduction. |
| 7 | **Charges / quotation / invoice** | **No installation charge exists on the job.** Fiber has `fiber_install_fee` (`QuotationService`, `KycService`); Starlink has `amount_charged` on the KYC application (what the retailer took at KYC), hardware prices in `hw_cart_json`, and plugin quotations (`QuotePdfService`, `PluginQuotePdf`). Invoices are made **by the accountant in uCRM** after completion, prompted by the Invoice Queue; the plugin creates no uCRM invoice for a job. |
| 8 | **E-mail** | `lib/MailService.php` (uCRM's SMTP, one service), `lib/CustomerEmails.php` templates — `installScheduled()` exists — on `lib/EmailTemplate.php` (`button()`, `facts()`, `wrap()`), dispatched by `lib/CustomerEmailDispatcher.php` behind `customer_emails_enabled` AND `customer_email_<key>` (both OFF by default; dedupe key `claimOnce`; never throws). `JobNotifier` e-mails staff the same text as each WhatsApp. Operator tool `tools/set_customer_emails.php`. |
| 9 | **WhatsApp** | `lib/NotificationService.php` `sendVia(sender, phone, text, event, vars, class)` → Evolution first (`sendViaEvolution`), WASender fallback; dry-run mode; opt-out classes (`ContactOptOut::CLASS_TRANSACTIONAL` default, `CLASS_STAFF` for colleagues); Message Log `notification_log.json`; the Failed Queue tab; `dedupMark()`. Direct sends use `EvolutionApiService::sendText(channel, phone, text, class)`. Existing customer templates `installationScheduled`, `installationConfirmed`, `technicianDispatched`. Credentials come from configuration (`evo_api_url`, `evo_api_key`, instances), never code. |
| 10 | **Public secure links** | `public.php?page=…` routes without login: `customer_login`, `customer_portal`, `terms`/`privacy` (`legal_page.php` over `LegalContent`), `efris_pdf`, `shop`, payment returns. Signed links: `PdfLinkToken` (HMAC daily token, 24–48 h, own secret `pdf_link_secret`) and `QuotePdfToken`. The strongest precedent for a single-purpose token is the distributor portal's OTP (082/083): **hashed codes, attempt counters and a hashed rate ledger written in the same transaction**. |
| 11 | **Auth / session** | Staff web: `RetailerAuth` (`webLogin`, `currentRetailer`); staff API: `tokenAuth()` (Bearer per account, `api/index.php:186`); Uganda J6: `JobAccess` (assignee, leader or admin may act; `CREATE_ROLES`). Customers: `CustomerSession` (JWT in HttpOnly cookie, `customer_sessions` rows, revocable). Partners: `PartnerSession`. |
| 12 | **Audit / events** | No general audit table. Per-domain append-only logs: `job_notify_events` (jobs), `fin_audit` (money; append-only proved by test), `followup_events`, `dpo_payment_events`, `starlink_events`, `dist_notify_log`, `dist_partner_auth_log`; the webhook log; the Message Log. The `events` table is the EventBus **work queue**, not an audit log. |
| 13 | **Permissions / RBAC** | `roles / permissions / role_permissions` (032) plus the role strings on staff rows; `StaffDirectory::JOB_ROLES`; `JobAccess::canCreate` (support leader, support, support engineer, admin) and `canActOn` (the assignee, a leader, an admin), checked on the server (J6, R6). |
| 14 | **Installation-start action** | **Two server paths**, both in the plugin's staff API: `scheduling_job_update` → status 1 (J6-checked on Uganda) and `install_checkin` → status 1 (`$isSupportAny2` only — **no J6 assignee check**). **A third path is outside the plugin: uCRM's own job screen.** The plugin cannot guard that; it can only detect it (webhook `job.edit`) and record it. |
| 15 | **Completion / sign-off** | `scheduling_complete` (item 3). A `job_signatures.json` store is read into the job detail (`signature`), so a completion signature artefact already exists in the field-ops layer; it is a **completion** sign-off, distinct from authorisation (Phase 16). |
| 16 | **Notification retry** | `lib/NotificationRetry.php` (Uganda, `NotifyGate::RETRIES`): automatic retries only for its `EVENTS` allow-list (receipts, welcomes, quotations), at most three, 10/30/120 min, within six hours, only when the refusal was certain; everything else waits in the Failed Queue for a person. `CustomerEmailDispatcher`: dedupe, no retry. `JobNotifier`: no retry by design (the state has moved on). `notify_watchdog` guards the jobs. |
| 17 | **Reusable templates** | `CustomerEmails::installScheduled` + `EmailTemplate::button/facts/wrap`; `NotificationService::installationScheduled/Confirmed`; `JobMessages` (staff texts, pure functions, byte-pinned by tests); **`LegalContent` + `legal_page.php`: versioned public legal documents rendered over the tenant profile** — the natural home for versioned Installation Terms; `profiles/uganda.json` (legal entity "DishNet Africa Limited", trading name, office, contacts). |
| 18 | **Migrations** | `migrations/NNN_name.sql`, run once each by `MigrationRunner` (checksum; "already exists" and "duplicate column" are safe), additive, Uganda-only tables stay empty elsewhere. Last: 085. **Five tests pin "no migration 086–089"** (`test_document_media`, `test_document_pdf`, `test_image_media`, `test_voice_media`, `test_document_activation`): a new migration amends those five pins deliberately, each with its reason. |

**Tests and fakes already there:** `tests/fixtures/fake_ucrm_staff_jobs.php` (jobs, tasks, comments, clients, users),
`fake_evo_server.php`, `fake_smtp_server.php`, `staff_jobs_sandbox.php` (the real plugin under `php -S`),
`test_job_notifier`, `test_job_access`, `test_job_dispatch`, `test_job_photos`, `test_job_status_truth`,
`test_staff_jobs_south_sudan` (the South Sudan golden), `test_notify_evolution`, `test_notify_retries`.

## 2. What the brief needs that does not exist

1. A charge on the job (installation, transport, other, total). 2. A customer-facing page for a job. 3. Versioned,
hashed installation terms. 4. An acceptance record and an unguessable token. 5. A guard on the two start paths and on
completion. 6. An authorisation panel on the job page and a replacement for the Accept button while not authorised.
7. The three messages (customer request, customer confirmation, technician confirmation) and their e-mail copies.
8. An append-only event trail for the authorisation lifecycle. 9. A "customer already confirmed" message on
reassignment. 10. A flag, OFF.

## 3. Proposed design — additive, Uganda only, OFF by default

**Gate.** `StaffJobsGate::applies()` (Uganda) AND a new bool `install_auth_enabled` (set_config, default off). With it
off every path below is unreachable and the job workflow is byte-for-byte today's; South Sudan never reaches the gate.

**Which jobs.** A uCRM job whose title matches `JobPhotos::INSTALL_KEYWORDS` **and** contains `starlink` (D6 below
decides whether all installations or Starlink only).

**Data (one migration, 086).**
- `install_auth` — one row per uCRM job: `job_id` (PK), `crm_client_id`, `acceptance_reference` (`ACC-YYYYMMDD-NNNNNN`,
  unique), `status` (`pending · accepted · declined · cancelled · expired`), `terms_version`, `terms_hash` (SHA-256 of the
  exact text), `price_snapshot` (JSON: installation, transport, other agreed charges, total, currency), `scope_snapshot`
  (JSON: service, equipment, location, scheduled date/time), `customer_name`, `customer_phone` (the number the request
  went to, international form), `customer_email`, `requested_by` (staff id), `requested_at`, `token_hash` (SHA-256 of a
  32-byte random token; **the raw token is never stored**), `token_expires_at`, `accepted_at`, `accepted_method`
  (`web_link`), `accepted_by_name`, `declined_at`, `decline_reason`, `cancelled_at`, `updated_at`. No IP address and no
  user agent unless the operator wants them (D11).
- `install_auth_events` — append-only: `id`, `job_id`, `event` (the Phase 10 names), `actor_kind` (`customer · staff ·
  system`), `actor_id`, `detail` (a code or count; never a phone, e-mail, token or message text), `created_at`.
- A hashed per-address rate ledger for the public page, on the 082 pattern.

**Terms.** `lib/InstallationTerms.php`: the full text of `INSTALLATION-TERMS-v1.0` as a constant, its SHA-256 computed
once and pinned by a test, rendered through `legal_page.php`'s styling over the Uganda profile. A later v1.1 is a new
constant; a record keeps its own `terms_version` and `terms_hash`, so acceptance stays reproducible. The draft itself:
`docs/62`, labelled **DRAFT — SUBJECT TO LEGAL REVIEW**.

**Request (staff).** On the job page, for an installation job without a record: *Request customer authorisation*. New API
action `install_auth_request` (J6: leader, admin, or the assignee), which shows the charges **prefilled where a source
exists** (the client's KYC application, a plugin quotation) and otherwise typed, plus service, equipment, location and
date read from uCRM. It writes the `pending` record and the token in one transaction, then sends the customer message by
WhatsApp (`NotificationService::sendVia('support', …, 'ops_install_auth_request', …, CLASS_TRANSACTIONAL)`) and e-mail
(`CustomerEmailDispatcher` key `install_auth_request`, its own switch), records `INSTALLATION_TERMS_SENT` with each
channel's outcome, and never fails the request because a send failed.

**Acceptance page.** `public.php?page=install_auth&t=<token>`: HTTPS required (`CustomerSession::isHttps`), token looked
up by hash in constant time, expired or unknown or not-pending → one neutral page ("This link is no longer valid — please
contact DishNet"), rate-limited per address. It shows exactly the brief's layout from the stored snapshots — never a live
price — then the full terms, the confirmation checkbox, **ACCEPT & AUTHORISE INSTALLATION** and **DECLINE INSTALLATION**.
The POST carries the token and a per-page nonce; the server re-reads the record, and the accept is `UPDATE … WHERE
status = 'pending'` — a second click reads "Installation already authorised." and changes nothing. A view records
`INSTALLATION_TERMS_VIEWED` once per day per record.

**After acceptance (the order the brief requires).** 1 commit the acceptance; 2 only then read uCRM's job live for
`assignedUserId` → the verified staff row → WhatsApp (`CLASS_STAFF`, the brief's text) and the e-mail copy exactly as
`JobNotifier` does, with a dedupe mark `INSTAUTH<job>:<assignee>`; 3 the customer's confirmation by WhatsApp and e-mail,
with the technician's **first name only**, or the "DishNet will confirm the technician separately" line when nobody is
assigned; 4 every outcome into `install_auth_events` (`INSTALLATION_ACCEPTED`, `_ACCEPTANCE_NOTIFICATION_SENT`,
`_TECHNICIAN_NOTIFIED` / `_FAILED`). A failed send never touches the acceptance.

**Reassignment.** One additive hook in `JobNotifier::observe()`: when its decision is `reassigned` and an `accepted`
record exists for the job, the new assignee also gets "🟢 CUSTOMER ALREADY CONFIRMED INSTALLATION" (dedupe per
assignee). No second acceptance record, ever.

**The guard (`InstallAuthGuard::requireAccepted`).** Called server-side, before uCRM is written, in all three places:
`scheduling_job_update` when the target status is 1, `install_checkin`, and `scheduling_complete`. Fails closed with
*"Customer acceptance is required before installation can commence."* (422) for a job in scope with no `accepted`
record — `declined`, `expired`, `cancelled` and `pending` all refuse. The job page hides the Accept button behind the
🔴 notice and shows the 🟢 panel when accepted; the server rule stands on its own.

**Cancellation and expiry.** Decline, a cancelled job (webhook `job.delete`) or `token_expires_at` revoke the token.

**Events recorded by existing actions** (additive lines, no behaviour change): `INSTALLATION_STARTED` on the two start
paths, `INSTALLATION_COMPLETED` on completion, `CUSTOMER_SIGNED_OFF` when a completion signature is stored,
`INSTALLATION_DISPUTED` by a staff action on the panel.

**Tests.** `tests/test_install_authorisation.php` against the fake uCRM, fake Evolution and fake SMTP, covering the
brief's 32 cases with weakened copies; the five migration pins amended; the South Sudan golden unchanged.

## 4. Decisions needed before STEP 4

| # | Question | Recommendation |
|---|---|---|
| D1 | Flag name and default | `install_auth_enabled`, bool, **off**; e-mail keys `customer_email_install_auth_request` / `_confirmed` under the existing master switch |
| D2 | Guard scope when the flag is on | **every in-scope installation job needs an `accepted` record to start** — the guard binds the job, not only jobs with a request |
| D3 | Jobs already in progress when the flag turns on | exempt by `requested_at` absent AND status already 1 at activation; recorded, not refused |
| D4 | Where the charges come from | **typed or confirmed by staff at request time, prefilled when a KYC application or quotation exists**; the snapshot is what the customer accepts |
| D5 | An administrative waiver (acceptance obtained otherwise) | **not in the first implementation**; if wanted later, admin only, with a reason, audited as its own event, never silent |
| D6 | Which titles are in scope | installation jobs whose title contains `starlink`; the fiber flow is unchanged |
| D7 | Token lifetime | 14 days, or the scheduled date plus 3 days, whichever is later; configurable |
| D8 | Message wordings | the brief's, verbatim, with "DishNet Africa Ltd" from the profile's legal entity |
| D9 | The technician's e-mail copy | yes, as every job message already has (docs/44 §16.16) |
| D10 | Customer phone and e-mail | uCRM `contacts[0]`; no number → the request is recorded and the staff member told; nothing invented |
| D11 | Store the accepting browser's address and agent | **no** by default; the reference, time, terms hash and snapshots are the evidence |

## 5. Risks and limits — stated, none of them a STOP

- **uCRM's own screen can still move a job to status 1 or 2.** The plugin's guard binds the plugin's technician app, which
  is the only path technicians use; a status change made inside uCRM is detected by the webhook and recorded as an event
  with an alert to the leaders, not prevented. This must be said in the final report.
- **`install_checkin` has weaker access control than the Accept path** (no J6 assignee check). The guard is added to it;
  widening its access check is out of scope and noted.
- **Charges have no single authoritative source**, so the evidentiary snapshot is only as good as what staff enter.
- **A new unauthenticated page** is a new attack surface: hashed tokens, constant-time comparison, rate limiting, HTTPS,
  neutral failure pages, no identifiers in the URL beyond the token.
- **Five tests pin the migration count** and are amended with reasons, not deleted.
- Nothing found requires changing existing Starlink behaviour in a risky or unrelated way; the feature is reachable only
  behind the Uganda gate and a flag that ships off.

## 6. Order of work after approval (the brief's STEP 4–16)

1 migration 086 and `InstallationTerms` · 2 the acceptance record and token service · 3 the public page · 4 the guard on
the three paths · 5 the staff request action and the e-mail and WhatsApp templates · 6 the post-acceptance messages and
the reassignment hook · 7 the job-page panel · 8 events · 9 the terms draft (docs/62) · 10 tests, twice · 11 full suite,
twice, lint, secret scan, Domain B and South Sudan checks · 12 the report. Local commit only; no push; no deploy; no
flag on; no real message.

---

Discovery: COMPLETE · Approved 2026-10-05 (D1–D11 as recommended) · Built: docs/63 (5.18.82, development only) · Push: NO · Deploy: NO · Production: UNTOUCHED
