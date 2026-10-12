# 63 — Customer Installation Authorisation for Starlink installation jobs — BUILD RECORD and FINAL REPORT (plugin 5.18.82, 2026-10-05)

**Status: superseded in part by `docs/64` (plugin 5.18.83, 2026-10-06) — see §0.1 for the statements below that were not true of 5.18.82.**

**Status at 5.18.82: BUILT in development only (plugin 5.18.82). The feature flag `install_auth_enabled` is OFF everywhere and was
turned on nowhere. NOT deployed, NOT pushed, no production configuration touched, no message sent to any real person, no
real financial record, no production installation job altered. Domain B (`dishnet-mikrotik-control-plane/`) untouched.**
This is STEP 16 of the emergency brief — the final implementation report (sections A–T below) — written after STEP 4–15
were built and proved on the approved design (`docs/61` §3, decisions D1–D11 approved 2026-10-05 as recommended, the
terminology "Customer Installation Authorisation", uCRM-side status changes out of scope). The Installation Terms draft is
`docs/62`. Everything stated as behaviour here is proved by `tests/test_install_authorisation.php` (§M) unless it says
otherwise.

**Reading rule.** VERIFIED means read from the code at this commit or proved by a test named here; DECIDED means a choice
taken while building, stated with its reason; OPEN means a question nobody has answered yet.

## 0. The answer in one paragraph

Around the existing Starlink Installation Job — uCRM's scheduling job, which the plugin never replaced — sit one new record
per job (`install_auth`, migration 086) and its append-only trail (`install_auth_events`). A staff member who may act on the
job requests the customer's authorisation from the job page, confirming the charges (D4); the customer receives the request
by WhatsApp and e-mail with a secure link whose 32-byte token is stored only as a SHA-256; on the page the customer sees the
job, the location, the service, the equipment, the charges and the total **as snapshots taken at the request**, the full
Installation Terms `INSTALLATION-TERMS-v1.0` with their hash, a confirmation box, **ACCEPT & AUTHORISE INSTALLATION** and
**DECLINE INSTALLATION**. Acceptance is one `UPDATE … WHERE status = 'pending' AND terms_hash = <the hash shown>` — a second
click reads "Installation already authorised." and changes nothing. Only after the record is committed are people told: the
technician uCRM names on the job **now** (WhatsApp, `CLASS_STAFF`, and an e-mail copy — D9), then the customer (confirmation
with the technician's first name only, or the "DishNet will assign/confirm the technician separately" line). The server
refuses **Accept Job, the GPS check-in and Complete Job** on a Starlink installation that has no `accepted` record —
pending, declined, cancelled, expired and "never requested" all fail closed with *"Customer acceptance is required before
installation can commence."* — and a job already in progress with no record is exempt (D3). A reassignment after acceptance
tells the new engineer "🟢 CUSTOMER ALREADY CONFIRMED INSTALLATION". With the flag off, or on South Sudan, every one of
these paths is unreachable and the job workflow is byte for byte what it was.

## 0.1 Corrections after the pre-release review (added 2026-10-06; fixed in 5.18.83 — `docs/64`)

The final pre-release review of 5.18.82 found that the following statements in this record were not true of 5.18.82. They are
corrected here rather than rewritten in place, so the record still shows what was claimed.

| Claimed here | What was true of 5.18.82 | Fixed in 5.18.83 |
|---|---|---|
| "the raw token is never stored" (§0, §C) | the row held only the hash, but the send layer kept the whole request message, link included, in the WA Inbox's conversation store after a successful send and in the failure queue after a failed one; a staff member could accept for the customer from either | `docs/64` §B |
| the server refuses "Accept Job, the GPS check-in and Complete Job" — the paths that start or complete a job | GPS check-out sets uCRM status 2 and was not guarded: it closed a pending Starlink installation that nobody had accepted | `docs/64` §A.1 |
| D3: "a job at status 1 with no record is not refused" | this exempted any job uCRM showed in progress — including one started in uCRM's own screen after the feature was switched on, which then left no trace | `docs/64` §A.4, §A.2 |
| D6: `inScope()` = `JobPhotos::isInstallJob()` AND the title names `starlink` | "starlink" is one of `isInstallJob()`'s keywords, so this meant "the title names Starlink": repairs, relocations and power-issue visits were bound, and so was any job whose customer's name contained Starlink | `docs/64` §A.3 |
| D11: the rate ledger "holds a salted SHA-256 of the address" | behind a local proxy the address was the first X-Forwarded-For hop, which the client chooses — the limit could be bypassed, and every request wrote a row | `docs/64` §F |
| the two e-mail keys: "absent means ON" (§F) | true, and the review judged it unsafe: an install with customer e-mails already on sent these without anyone choosing to | reversed to absent means off, `docs/64` §E |
| the customer's confirmation: "Our assigned technician has been notified." | said even when both of the technician's channels had failed | `docs/64` §C |

## 1. The approved decisions and how each is implemented — VERIFIED

| | Approved | Implemented |
|---|---|---|
| D1 | `install_auth_enabled`, default OFF | `InstallAuth::FLAG`; `InstallAuth::enabled()` = `StaffJobsGate::applies()` (Uganda) AND the flag. Declared in `tools/set_config.php`. Off: the detail carries no `install_auth`, the list no marks, the page is a 404, the six actions answer 404 |
| D2 | every Starlink installation job needs acceptance before starting | `InstallAuth::guard()` binds the JOB, not only jobs with a request: a job at status 0 with no record is refused |
| D3 | jobs already in progress at activation are exempt | a job at status 1 with no record is not refused (completion included); a record that exists and is not accepted binds completion too |
| D4 | staff confirm/enter the charges; the customer accepts the snapshot | `install_auth_request` takes installation / transport / other (+ label); `InstallAuth::validateCharges()`; `price_snapshot` + `scope_snapshot` stored; the page and every message render the snapshots, never a live price; the prefill never prefills a charge (there is no authoritative one, docs/61 item 7) |
| D5 | no admin waiver initially | none exists: no action, no route, no SQL path sets `accepted` except the customer's statement |
| D6 | Starlink installation jobs only; Fiber unchanged | `InstallAuth::inScope()` = `JobPhotos::isInstallJob()` AND the title names `starlink`; a Fiber job is accepted and completed exactly as before (proved) |
| D7 | 14 days or the scheduled date + 3 days, whichever is later | `token_expires_at = max(now + install_auth_link_days, scheduled + 3 days)`; `install_auth_link_days` default 14, range 1–90, refused outside the range by `set_config.php` |
| D8 | the brief's wording | `InstallAuthNotifier::requestText / technicianText / confirmedText / alreadyConfirmedText` and `InstallAuthEmails` carry the brief's phase 6, 7, 12, 13 and 14 texts; the legal entity comes from the tenant profile ("DishNet Africa Limited") |
| D9 | technician e-mail copy | yes — the same text, to the staff account's address, as every job message (docs/44 §16.16) |
| D10 | uCRM `contacts[0]`; nothing invented | `InstallAuth::clientPhone / clientEmail`; a client with neither is refused (422) and nothing is stored; a client with a number and no e-mail gets the WhatsApp and the e-mail is recorded `no_email` |
| D11 | no IP / browser data | not a column, not an event; the public page's rate ledger holds a salted SHA-256 of the address, purged after the window |

**Terminology.** The product is called **Customer Installation Authorisation** in the panel, the configuration, the API,
the documents and the events' names; "acceptance" is kept where the brief uses it (the acceptance record, the acceptance
reference, `INSTALLATION_ACCEPTED`), and the terms are one part of what the customer authorises.

## A. Existing Starlink Installation Job architecture discovered

`docs/61` §1 is the eighteen-item discovery, unchanged. In one paragraph: the Starlink Installation Job is **uCRM's
scheduling job** (`scheduling/jobs/{id}`: title, client, assignee, date, duration, status 0 Pending / 1 Open = in progress /
2 Closed), created from My Jobs (`create_job`) or inside uCRM, with the plugin keeping side records by job id
(`job_notify_state`, `job_notify_events`, photos, completion position, completions, check-ins). Start = status 1, reached by
the technician's **Accept Job** (`scheduling_job_update`, J6-checked on Uganda) or a **GPS check-in** (`install_checkin`);
complete = `scheduling_complete`. The assignee is `assignedUserId`, read live from uCRM (R2), linked to one verified staff
account; every job message goes through `JobNotifier` → `NotificationService` (Evolution) and `MailService`. Nothing asked the
customer anything, no charge sat on the job, no customer-facing job page existed, and the two start paths checked only who
the caller was. **None of that was rebuilt or replaced**: the new layer sits beside it, keyed by the same job id.

## B. Files changed — VERIFIED (`git status` at the commit)

| File | Change |
|---|---|
| `migrations/086_install_authorisation.sql` | **new** — `install_auth`, `install_auth_events` (append-only by trigger, event vocabulary by CHECK), `install_auth_rate` (§C) |
| `lib/InstallationTerms.php` | **new** — `INSTALLATION-TERMS-v1.0` as a constant, `text()`, `hash()`, `matches()`, `sections()`, `VERSIONS` (§J) |
| `lib/InstallAuth.php` | **new** — the record: gate and scope, `request()`, `resend()`, `cancel()`, `dispute()`, `expireIfDue()`, `byToken()`, `markViewed()`, `accept()`, `decline()`, the **guard**, `recordLifecycle()`, `event()`, the rate ledger, the words and formats (§D, §I, §K) |
| `lib/InstallAuthNotifier.php` | **new** — the three messages and their delivery through `NotificationService::sendVia()`, `CustomerEmailDispatcher` and `MailService` (§F–§H) |
| `lib/InstallAuthEmails.php` | **new** — the request and confirmation e-mails on `EmailTemplate` (§F) |
| `tabs/customer_app/install_auth_page.php` | **new** — the customer's page (§E) |
| `includes/api/api_install_auth.php` | **new** — six staff actions: `install_auth_status`, `_prefill`, `_request`, `_resend`, `_cancel`, `_dispute` (Uganda + flag; J6) |
| `tests/test_install_authorisation.php` | **new** — the proof (§M) |
| `public.php` | +10 — the `?page=install_auth` route |
| `includes/api_handlers.php` | +1 — the include, after `api_job_photos.php` |
| `includes/api/api_scheduling.php` | +62 — the guard and the lifecycle events in `scheduling_job_update` and `scheduling_complete`; `install_auth` in the job detail; `_install_auth` marks in the jobs list |
| `includes/api/api_field_ops.php` | +17 — the guard and `INSTALLATION_STARTED[checkin]` in `install_checkin` |
| `includes/api/api_crm_misc.php` | +17/−3 — `$_cmJobGuard` returns uCRM's job; `save_job_signature` records `CUSTOMER_SIGNED_OFF` |
| `webhook.php` | +9 — `job.delete` cancels a pending request |
| `lib/JobNotifier.php` | +26/−1 — the `reassigned` hook (`installAuthReassigned()`) |
| `lib/CustomerEmailDispatcher.php` | +60 — `sendInstallAuth()` and `installAuthEnabled()`, off the catalogue |
| `tabs/support/scheduling.php` | the shared panel block, the Accept / Complete replacements in both job renderers and the list badge — **every added line inside a `<?php if ($_sjUganda): ?>` branch**, so South Sudan's page is byte for byte 5.18.81's (the golden compares pages byte for byte) |
| `tools/set_config.php` | four keys, the link-days range, and the listing's name column widened from 32 to 40 characters — `customer_email_install_auth_confirmed` is 37 (Phase 2 widened it from 27 to 32 the same way) |
| `tests/test_set_config_tool.php` | its column reader follows the listing to 40 characters, with the reason in its comment; the assertion and its tally (40) are unchanged |
| `manifest.json` | 5.18.81 → 5.18.82 |
| 15 test files | the version pin → 5.18.82 |
| 5 test files | the "no migration 086–089" pin amended, with the reason, to "no migration 087–089, and 086 is Customer Installation Authorisation" |
| `tests/test_job_access.php` | two weakened-copy anchors amended, never deleted, with their reasons: the J6-then-PATCH anchor now spans the guard that sits between them (its weakened copy still writes uCRM before the check); the signature anchor follows `$_cmJob = $_cmJobGuard(...)` (its weakened copy leaves `$_cmJob` null) |

**Untouched:** every other migration (085 and below), `lib/EventBus.php`, the media / document / voice / image batches,
`JobMessages`, `JobAccess`, `StaffDirectory`, `NotificationService`, `MailService`, `EmailTemplate`, `CustomerEmails`
(its catalogue is unchanged), every South Sudan path, `dishnet-mikrotik-control-plane/`.

## C. Database changes — migration 086 — VERIFIED

Additive, idempotent (`CREATE TABLE IF NOT EXISTS`), run once by `MigrationRunner` at the plugin's next boot; no backfill,
no existing row touched; on every install other than Uganda-with-the-flag the three tables stay empty (proved on South
Sudan).

- **`install_auth`** — one row per uCRM job (`job_id` PRIMARY KEY): `crm_client_id`, `seq` + `acceptance_reference`
  (`ACC-YYYYMMDD-NNNNNN`, UNIQUE; the date is Kampala's at the request, the counter is global and never reused), `status`
  (CHECK: `pending | accepted | declined | cancelled | expired`), `terms_version`, `terms_hash`, `price_snapshot` (JSON:
  currency, installation, transport, other, other_label, total), `scope_snapshot` (JSON: title, service, equipment,
  location, scheduled, scheduled_label, duration_min), `customer_name`, `customer_phone`, `customer_email` (where the request
  went — uCRM's `contacts[0]`), `requested_by` (retailers.id), `requested_by_name`, `requested_at`, **`token_hash`**
  (UNIQUE; SHA-256 of the 32 random bytes — the raw token is never stored), `token_expires_at`, `viewed_at`, `accepted_at`,
  `accepted_method` (`web_link`), `accepted_by_name`, `declined_at`, `decline_reason`, `cancelled_at`, `cancel_reason`,
  `created_at`, `updated_at`. A declined, cancelled or expired request may be **superseded on the same row** (new
  reference, new token, new snapshots); an accepted row is never rewritten.
- **`install_auth_events`** — append-only: `job_id`, `event` (CHECK over the fourteen names of §K), `actor_kind` (CHECK:
  `customer | staff | system`), `actor_id` (retailers.id for staff), `detail` (a code or count — never a phone, an e-mail, a
  token or a message text), `created_at`. Two triggers refuse UPDATE and DELETE (proved by execution).
- **`install_auth_rate`** — `rkey`, `at`: the page's per-address buckets as `page:<sha256>` / `post:<sha256>` under a
  fixed label; purged on every write beyond the window. No address is stored (proved: the ledger's keys never contain the
  caller's address).

**What is NOT stored, by decision:** the browser's address or agent (D11); the raw token; any uCRM credential; any price
other than what the staff member confirmed.

## D. New acceptance workflow — VERIFIED

```
staff (assignee / leader / admin; J6)     POST install_auth_request {job, charges, service, equipment, location}
   → uCRM's job and client are read live (never the body) → InstallAuth::request(): one BEGIN IMMEDIATE transaction,
     the record 'pending', the reference, SHA-256(token) → COMMIT → the customer's WhatsApp + e-mail with the link
     → INSTALLATION_TERMS_SENT[request;wa:<o>;email:<o>]
customer  GET  ?page=install_auth&t=<token>      → the snapshots, the terms of the record's version, the form
                                                  → INSTALLATION_TERMS_VIEWED (once a day)
customer  POST accept {t, agree, name, terms_hash} → UPDATE … WHERE status='pending' AND terms_hash=? AND not expired
                                                  → INSTALLATION_ACCEPTED, then (after the commit, never before):
                                                    the technician uCRM names NOW  → INSTALLATION_TECHNICIAN_NOTIFIED / _FAILED
                                                    the customer's confirmation    → INSTALLATION_ACCEPTANCE_NOTIFICATION_SENT
customer  POST decline {t, reason?}               → 'declined' → INSTALLATION_DECLINED (nobody is messaged; the panel shows it)
technician Accept Job / GPS check-in              → guard → uCRM status 1 → INSTALLATION_STARTED[accept|checkin]
technician Complete Job                           → guard → uCRM status 2 → INSTALLATION_COMPLETED[complete]
technician completion signature                   → CUSTOMER_SIGNED_OFF[signature]   (distinct from the acceptance — phase 16)
staff     resend / cancel / dispute               → a new token (same record) / 'cancelled' / INSTALLATION_DISPUTED
uCRM      job.delete                              → a pending request is 'cancelled' (job_deleted); an accepted record stays
time      token_expires_at passed                 → 'expired' on the next read, INSTALLATION_LINK_EXPIRED[due]
uCRM      job reassigned after acceptance         → the new engineer: "CUSTOMER ALREADY CONFIRMED INSTALLATION"
```

Statuses: `pending → accepted` (final; never rewritten) · `pending → declined | cancelled | expired` (each may be superseded
by a new request on the same row) · an accepted record cannot be cancelled (409: record a dispute instead). The customer's
side never changes anything but its own row, by one statement, with the token's hash in the WHERE.

## E. The customer-facing page — VERIFIED (`tabs/customer_app/install_auth_page.php`)

- **Route:** `public.php?page=install_auth&t=<token>`. No login. The token is the whole credential; no id, name or amount is
  carried in the URL or accepted from the form.
- **Gate:** `InstallAuth::enabled()` — Uganda AND the flag — or a neutral **404** like any page that does not exist (South
  Sudan, and Uganda with the flag off, both proved).
- **HTTPS required** (`CustomerSession::isHttps()`, i.e. `HTTPS`, `X-Forwarded-Proto` or port 443); a loopback request is
  allowed so the tests can drive the real page; otherwise **403** "Secure connection required".
- **Rate-limited per address** through the hashed ledger: 60 page views / 10 min, 20 POSTs / 10 min → **429**. A ledger
  that cannot be read refuses (fail closed).
- **Lookup by hash**, then `hash_equals()` against the stored hash; an unknown, malformed or absent token, or the HASH of a
  real token, all answer one neutral **404** page with no form (proved for all four).
- **The layout** (the brief's phase 3): the legal entity in the top bar and the heading *Starlink Installation
  Authorisation*; Job Number, Customer, Installation Location, Service, Equipment, Scheduled; Installation Charges,
  Transport Charges, Other agreed charges (*None* when none — never an invented figure), **Total**; the reference and the
  link's expiry; the **complete Installation Terms** of the record's version with their SHA-256; the confirmation box with
  the brief's wording ("…authorise DishNet Africa Limited to proceed with the installation described in this Installation
  Job."); the name; **ACCEPT & AUTHORISE INSTALLATION**; **DECLINE INSTALLATION** with an optional reason.
- **GET never changes the record** (it records a view once a day). **POST** carries the token, the action, the terms hash
  the page showed, the box and the name; the server re-reads the record. Outcomes: accepted → the confirmation page with
  the reference; already accepted → "Installation already authorised."; hash not the record's → **409** "The terms have
  changed"; expired → **410**; declined / cancelled → their pages; no box or no name → **422**, still pending.
- **After the status is no longer pending** the link answers with its state whatever is asked (accepted: the reference
  and time; declined; cancelled 410; expired 410).
- **Headers:** `Cache-Control: no-store`, `Referrer-Policy: no-referrer`, `X-Robots-Tag: noindex`, `X-Frame-Options: DENY`,
  `nosniff`, `Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; form-action 'self'`. **No script runs
  on the page and nothing is loaded from any other origin** (proved on the rendered HTML); system fonts, inline styles.
- **Not exposed:** no uCRM or admin API, no database id beyond the uCRM job number the customer already has, no staff
  names, no technician number.

## F. New e-mail flow — VERIFIED

Through the existing `MailService` (the plugin's SMTP) and `CustomerEmailDispatcher`, with a new off-catalogue method
`sendInstallAuth('request' | 'confirmed', …)` on the `sendReminderDue` pattern (the catalogue's tests iterate
`CustomerEmails::CATALOGUE`, so a feature with its own switch sits beside it). Both e-mails are built by
`lib/InstallAuthEmails.php` on `EmailTemplate` (`h1`, `facts`, `button`, `note`, `wrap`, `textFooter`).

- **Request** — subject verbatim: *"Action Required: Authorise Your DishNet Starlink Installation – Job {{JOB_NUMBER}}"*;
  the job, service, equipment, location, scheduled date; the charges and total; the terms version; **two buttons**, both to
  the secure page — *ACCEPT & AUTHORISE INSTALLATION* and *DECLINE INSTALLATION* (the second opens the page with the
  decline form in focus; a GET never accepts or declines); the link in clear for clients that drop buttons; the expiry; the
  support line from the e-mail brand. Dedupe key: the job and the token's hash prefix, so a resent link is a new send.
- **Confirmation** — subject *"Installation authorised – Job N (ACC-…)"*; the brief's phase 7 text; the technician's first
  name, or the "assign/confirm separately" line; the reference, the time, the terms version. Dedupe: the job and the
  reference.
- **Switches** — the customer e-mails **master switch** (`customer_emails_enabled`) still rules, as for every customer
  e-mail. Each has its own key, `customer_email_install_auth_request` / `_confirmed`, and — DECIDED, unlike the catalogue
  keys — **absent means ON**: the feature is already behind `install_auth_enabled` (off by default) and the brief asks
  for e-mail and WhatsApp together, so a second default-off switch would make the e-mail silently absent the day the
  feature is turned on. `0` / off / no turn one off. Declared in `set_config.php` with that sentence.
- **Outcomes** are recorded in the events as `email:sent | failed | no_email | switched_off | not_configured | already_sent`;
  the request answers them to the staff member; the panel shows them.

## G. New WhatsApp flow — VERIFIED

Through `NotificationService::sendVia('support', <number>, <text>, <event>, [], <class>)` — Evolution first, the Message
Log, the Failed Queue, opt-outs — exactly as every other WhatsApp. No new provider, no credential in code.

| Message | To | Event in the Message Log | Class |
|---|---|---|---|
| the request (brief phase 14 + phase 2's details) | the customer (`contacts[0].phone`) | `ops_install_auth_request` | `CLASS_TRANSACTIONAL` |
| the confirmation (brief phase 7) | the customer | `ops_install_auth_confirmed` | `CLASS_TRANSACTIONAL` |
| CUSTOMER CONFIRMED / ALREADY CONFIRMED (brief phases 6 and 12) | the technician's staff number | `ops_install_auth_technician` | `CLASS_STAFF` |

The request text is the brief's phase 14 text with phase 2's details (equipment, location, scheduled date, transport,
other, total) between the service and the charges. Outcomes recorded as `wa:sent | failed | no_number`. **Retry:**
`NotificationRetry` retries only its own allow-list (receipts, welcomes, quotations); these three are not added to it —
DECIDED — because the acceptance record is the source of truth (§H), the CRM panel shows the state, and a failed
technician message is a Failed Queue row a person sees, exactly as for every job message (`JobNotifier` has no retry by
design, docs/44). Adding them to the allow-list is one line, if wanted later.

## H. Technician notification flow — VERIFIED (the brief's phase 6 order)

1. The acceptance is committed (one UPDATE, `rowCount === 1`), and `INSTALLATION_ACCEPTED` is written.
2. Only then `InstallAuthNotifier::afterAcceptance()` runs, inside a `try/catch` whose failure is logged and **cannot touch
   the record** (proved: with WhatsApp failing, with the mail server unreachable, and with nobody assigned, the record is
   accepted and the page confirms).
3. The technician is **uCRM's `assignedUserId` read live at that moment** (R2) — never the request body, never a cached
   assignee — mapped to the one verified staff account (`StaffDirectory::byUcrmUser`); none or two → recorded, not sent.
4. One claim per acceptance and engineer (`dedupMark('INSTAUTH<job>:<reference>:<assignee>')`), then the WhatsApp
   (`CLASS_STAFF`) and the e-mail copy (D9). Outcomes → `INSTALLATION_TECHNICIAN_NOTIFIED` (WhatsApp sent, or e-mail only)
   or `INSTALLATION_TECHNICIAN_NOTIFICATION_FAILED` (both failed, no staff account, ambiguous account, no usable number,
   `no_assignee`, `ucrm_unreadable`), with the codes in the detail.
5. The customer's confirmation (WhatsApp + e-mail) with the technician's **first name only**, or the brief's fallback
   line; → `INSTALLATION_ACCEPTANCE_NOTIFICATION_SENT[wa:<o>;email:<o>]`.
6. **Reassignment** (brief phase 12): `JobNotifier::observe()`'s `reassigned` decision calls
   `InstallAuthNotifier::alreadyConfirmed()` for an `accepted` record — the new engineer gets message 1 (as before) and
   "🟢 CUSTOMER ALREADY CONFIRMED INSTALLATION" with the reference; the previous engineer gets "no longer assigned" (as
   before); the same claim key means an engineer told once is never told twice; no second acceptance record exists
   (proved, including a reassignment back).

The technician's message carries the customer's **name, location and service** and never the customer's phone number or
e-mail (asserted).

## I. Installation-start enforcement — VERIFIED

`InstallAuth::guard($pdo, $config, $dataDir, $job, $target, $path, $actorId)` is called **server-side, before anything is
written anywhere**, in the three places the operator named:

| Path | Where | Target |
|---|---|---|
| Technician **Accept Job** | `scheduling_job_update`, after J6, before the uCRM PATCH | the requested status (1, or 2) |
| GPS **check-in** | `install_checkin`, before the check-in is stored | 1 |
| **Complete Job** | `scheduling_complete`, after J6, before tasks, photos, the record or any message | 2 |

| Job | Record | Start (→1) | Complete (→2) |
|---|---|---|---|
| Starlink installation at status 0 | none / pending / declined / cancelled / expired | **refused** | **refused** |
| Starlink installation at status 0 | accepted | allowed | allowed |
| Starlink installation already at status 1 | none (D3) | — | allowed, as before |
| Starlink installation already at status 1 | not accepted | — | **refused** ("…before installation can be completed.") |
| Starlink installation already at status 1 | accepted | — | allowed |
| a job whose status uCRM did not give | any but accepted | **refused** | **refused** |
| Fiber or any non-Starlink job (D6) | — | as before | as before |
| flag off, or South Sudan | — | as before | as before |

The refusal is **422** with the brief's words, *"Customer acceptance is required before installation can commence."*
(completion of a started job: *"…before installation can be completed."*), and every refusal is in the trail as
`INSTALLATION_START_BLOCKED[<path>;status:<record>;job:<from>><to>]`. The check-in must read the job from uCRM to decide;
when uCRM cannot answer, the check-in is refused (502) rather than let through — DECIDED (fail closed). Once a start or a
completion goes through, `INSTALLATION_STARTED[accept|checkin]` / `INSTALLATION_COMPLETED[complete|status_update]` follow
the write (only for a job that carries a record: a D3 job leaves no authorisation trail).

**The UI** (`tabs/support/scheduling.php`) replaces the Accept button with the 🔴 notice and the Complete button with its
counterpart whenever the server's `install_auth` says so — but the rule stands on the server alone (five weakened copies,
§M). **Accepted limitation (the operator's):** uCRM's own job screen can still move a job's status; that path is outside
the plugin and out of scope for this phase. The plugin neither prevents nor, in this phase, records it.

## J. Terms versioning / hash — VERIFIED

`InstallationTerms::TEXT_V1_0` is the exact text; `VERSION = 'INSTALLATION-TERMS-v1.0'`; `hash()` = SHA-256 of the text,
`9308072840e784e49a0656987a3edfae33c6f3d081558cbf63625c23b071d2e1`, pinned by the test. Every record stores
`terms_version` + `terms_hash` at the request; the page renders the **record's** version from the code and shows the terms
only while `matches(version, hash)` holds; the accept statement requires the hash the page showed. An unknown version
renders nothing rather than the latest. A v1.1 is a new constant and a new `VERSIONS` entry; v1.0 is never edited
(docs/62 is a copy of the constant for legal review, generated from it).

## K. Audit events — VERIFIED (`install_auth_events`, append-only)

| Event | Actor | Detail |
|---|---|---|
| `INSTALLATION_TERMS_SENT` | staff | `request|resend;wa:<o>;email:<o>` |
| `INSTALLATION_TERMS_VIEWED` | customer | the record's status (once a day) |
| `INSTALLATION_ACCEPTED` | customer | `web_link` |
| `INSTALLATION_DECLINED` | customer | `web_link;reason:yes|no` |
| `INSTALLATION_ACCEPTANCE_NOTIFICATION_SENT` | system | `wa:<o>;email:<o>` |
| `INSTALLATION_TECHNICIAN_NOTIFIED` | system | `accepted|reassigned;wa:<o>;email:<o>;staff:<id>` |
| `INSTALLATION_TECHNICIAN_NOTIFICATION_FAILED` | system | `accepted|reassigned;<code>` |
| `INSTALLATION_STARTED` | staff | `accept|checkin` |
| `INSTALLATION_COMPLETED` | staff | `complete|status_update` |
| `CUSTOMER_SIGNED_OFF` | staff | `signature` |
| `INSTALLATION_DISPUTED` | staff | `status:<record status>` |
| `INSTALLATION_START_BLOCKED` (beyond the brief) | staff | `<path>;status:<record>;job:<from>><to>` |
| `INSTALLATION_REQUEST_CANCELLED` (beyond the brief) | staff / system | `staff` / `job_deleted` |
| `INSTALLATION_LINK_EXPIRED` (beyond the brief) | system | `due` / `superseded` |

The brief's eleven are there by name (`INSTALLATION_CUSTOMER_ACCEPTED` of phase 6 is `INSTALLATION_ACCEPTED` of phase 10);
three were added so that a refusal, a withdrawal and an expiry leave a trace too. The detail is **asserted** to carry no
phone number, no e-mail address and no token; a misspelt event is a database error (CHECK) and a code error
(`InstallAuth::EVENTS`, asserted equal to the CHECK). The existing per-domain pattern (`job_notify_events`, `fin_audit`) was
followed; the EventBus `events` table is a work queue, not an audit log, and was not used.

## L. Security controls — VERIFIED

- 32 random bytes (`random_bytes`), hex, **stored only as SHA-256**; lookup by hash, then `hash_equals()`.
- One token names exactly one job; the form carries no job, price, customer or status that is read — a POST naming another
  job authorises the token's own job only (proved); query and form fields naming a price, service, customer, version or
  status leave the snapshots untouched (proved).
- Acceptance by **one statement** with `status = 'pending'`, the token's hash, the terms hash shown and the expiry in the
  WHERE; a second click, a stale page or a tampered hash change nothing; an accepted row's time, name, version and hash are
  never rewritten (proved; weakened copy caught).
- The page: HTTPS required, per-address rate limit on a hashed ledger, one neutral page for every refusal, no script, no
  external origin, `no-store`, `no-referrer`, `noindex`, `DENY`, `nosniff`, CSP `default-src 'none'`.
- Expiry and revocation: `token_expires_at` (D7); decline, staff withdrawal and a job deleted in uCRM end the link at once;
  a resend rotates the token and kills the old one.
- The staff side: every action behind the staff Bearer / session, Uganda, the flag, and J6 (the assignee, a support leader
  or an admin — another engineer gets 403, sales gets 403); the job and the client are read from uCRM, never from the body.
- Nothing invented: no number, e-mail or price is ever filled in by the code (D10, D4).
- Privacy: the trail carries codes only; the panel masks the contact details; the technician never receives the customer's
  number or e-mail; the customer never receives the technician's surname or number; no IP or agent is stored (D11).
- The guard fails closed, and a check-in that cannot read uCRM is refused rather than let through.
- Secrets: none added; no credential in code, configuration or documents; `tools/set_config.php` refuses secrets as before.

## M. Tests and exact counts — VERIFIED

`tests/test_install_authorisation.php` — the brief's thirty-two cases in its order, then the lifecycle beside the brief
(resend, withdraw, dispute, job.delete, the trail's privacy and immutability, the rate ledger), then five weakened copies.
Against the fake uCRM, the fake Evolution and a fake SMTP relay; every person, number, e-mail and job fictitious.

| Run | Result |
|---|---|
| focused run 1 | **245 passed, 0 failed — 5 weakened copies caught** |
| focused run 2 | **245 passed, 0 failed — identical** |

The brief's thirty-two cases → sections: 1 job created · 2 the request (and the refusals around it) · 3–6 the page, details,
price, terms version · 7–11 acceptance, record, reference, hash, time · 12 customer confirmation · 13 technician message ·
14 CRM panel (detail, page HTML, list marks) · 15 start, completion, sign-off after acceptance · 16 refusals before it (run
first, as the control) · 17–18 decline and its refusals, then a new request superseding it · 19 invalid tokens (four
shapes) · 20 expired · 21 one job per token · 22 price tampering (query and form) · 23 duplicate acceptance · 24 duplicate
technician message (the notifier's own first sight distinguished) · 25 WhatsApp failure · 26 e-mail failure · 27 no
assignee, then a check-in start · 28 reassignment (forward, repeated, back) · 29 historical terms · 30 the existing
workflow (Fiber, a job already in progress, a flag-off Uganda sandbox) · 31 South Sudan · 32 Domain B (`git status` and
`git diff` under `dishnet-mikrotik-control-plane`, and the new files never mention it).

**Weakened copies, each caught:** (1) the Accept Job guard removed → the job starts with no acceptance; (2) the GPS
check-in guard removed → the check-in starts it; (3) the completion guard removed → a never-started job is completed; (4)
`InstallAuth::guard()` answering "allowed" for every job → Accept Job goes through; (5) the accept statement no longer
requiring a pending record → the service accepts a second time and overwrites the name (probed directly, with the real
tree as the control — and the page alone still answers "already authorised", a second guard above the statement).

## N. Full regression results — VERIFIED

| Run | `tests/run.sh` |
|---|---|
| run 1 | **278 files, 13,236 passed, 0 failed, 0 skipped** |
| run 2 | **278 files, 13,236 passed, 0 failed, 0 skipped — every suite's tally identical to run 1** |

Baseline at 5.18.81 (docs/60): 277 files, 12,991 passed, 0 failed. The only suite that moved is the new `test_install_authorisation` (+245); every other suite's tally is unchanged. PHP warnings: 5 (test_dpo_endpoints 5), all pre-existing.

## O. South Sudan regression result — VERIFIED

`test_staff_jobs_south_sudan.php` (the 5.18.49 golden, byte for byte): **51 passed, 0 failed** in both full runs, unchanged.
`test_install_authorisation.php` §31 on a South Sudan sandbox with the flag set: the page is a 404, the detail carries no
`install_auth`, the actions do not exist ("Unknown API action"), Accept Job / check-in / completion work as 5.18.49 did,
and the three tables stay empty. Every Uganda line in the changed files is a separate branch or a condition that is false
off Uganda (`$_sjUganda`, `$_cmUganda`, `StaffJobsGate`, `InstallAuth::enabled()`). In `tabs/support/scheduling.php` the
new lines are PHP branches, not JavaScript conditions: a first version put `if (window.schIaBlocksStart && …)` into the
shared script, which the golden would have caught as a changed South Sudan page — the South Sudan view of the file is
now proved identical to 5.18.81's, and the golden passed (51) before the full runs were restarted.

## P. Domain B result — VERIFIED

`git status --porcelain -- dishnet-hybrid-sudan/dishnet-mikrotik-control-plane` and `git diff --stat HEAD -- …` are both
empty at the commit (asserted by the test too). No new file mentions the control plane. No Domain B document, migration,
role or test changed. The CLAUDE.md "Always" rule holds: no production FreeRADIUS, database, schema, privilege or
deployment was touched.

## Q. Deployment requirements — NOT done here

Nothing was deployed. Live is 5.18.74 (`db18ad9`); this is 5.18.82 in development, the eighth unpushed local commit on
`claude/study-this-jhe2eg`. If and when the operator decides to take it live, the steps are the project's own, in this
order, each a separate decision:

1. **Legal review of `docs/62`** before any customer sees the terms (§S). A changed text is a new version (`v1.1`), not an
   edit of v1.0.
2. The seven unpushed commits before this one (5.18.75–5.18.81, the AI communication layer, all dark) sit between live and
   this change on the same branch; a release cut must decide what it carries. A pinned deploy script
   (`scripts/deploy-5.18.NN.sh`, cut on live, rehearsed) is the only deployment path; never a bare `deploy-hybrid.sh`.
3. Migration 086 applies itself at the plugin's next boot (`MigrationRunner`), additive; nothing to run by hand.
4. **The flag stays OFF after deployment.** Turning it on is `php tools/set_config.php --key install_auth_enabled --value 1`
   on the server, at a chosen moment, after staff know the new step (the request on the job page). From that moment every
   Starlink installation still pending needs a request and the customer's acceptance before Accept Job works; jobs already
   in progress are exempt (D3).
5. The customer e-mails master switch (`customer_emails_enabled`) must be on for the e-mails to go; the two feature keys are
   on unless set to 0; `install_auth_link_days` is 14 unless set.
6. Evolution (WhatsApp) and the plugin's SMTP must be configured — they are the existing channels; nothing new is needed.
7. The public address of the plugin must be reachable by customers over HTTPS (it is: the customer portal already uses it).
8. Staff training: who may request (the assignee, a support leader, an admin), what the charges mean (the customer accepts
   exactly what is typed), how to resend or withdraw, and that uCRM's own screen bypasses the guard (§I).

## R. Rollback procedure

- **Flag first:** `php tools/set_config.php --key install_auth_enabled --clear` — every path is unreachable again; Accept
  Job, check-in and completion behave as before; the page is a 404; the records stay in the tables untouched as evidence.
- **Code:** reverting the commit restores 5.18.81 exactly. The three tables are additive and ignored by older code; no
  data migration is needed in either direction. A record written by 5.18.82 is readable by 5.18.82 again later.
- There is no partial state to unwind: no cron, no worker, no queue entry, no uCRM write other than the status changes the
  technician's own actions already made.

## S. Remaining legal / compliance questions — OPEN

1. The seven questions in `docs/62` (enforceability of sections 12–13, the liability wording of 17 and 25, the complaint
   process in 21, the data notice in 23, electronic acceptance as evidence, naming the Starlink terms, the registered
   company name).
2. Whether the evidence stored (reference, time, version, hash, snapshots, the name typed) is sufficient without the
   browser's address and agent (D11 says no for now; a later decision could add them as columns and a consent line).
3. Whether a declined request should notify a staff member at once (today the panel shows it and the trail records it;
   nobody is messaged — a candidate follow-up, deliberately not added to the approved scope).
4. Whether the technician's message should join `NotificationRetry`'s allow-list (§G).
5. Whether uCRM-side status changes should at least be recorded as an event (out of scope by the operator's decision).
6. Whether `install_checkin`'s access check should be tightened to J6 (it stays `$isSupportAny2`, as found; the guard was
   added, the access rule was not changed — docs/61 §5).
7. The Data Protection and Privacy Act, 2019 register / notice for the authorisation records themselves.

## T. Confirmation that NO production deployment occurred

- Nothing was deployed; the server checkout `/opt/dishnet` was not touched; live stays 5.18.74 (`db18ad9`).
- Nothing was pushed to GitHub; the branch's remote head is unchanged.
- No production configuration was changed; `install_auth_enabled` was never set anywhere but in test sandboxes.
- No message, e-mail or WhatsApp went to any real person: every send in this work went to the fake Evolution and the fake
  SMTP relay in a sandbox, to fictitious numbers and `example.test` addresses.
- No real financial record was created; no production installation job was altered.
- Domain B untouched (§P).

## Decisions taken while building — DECIDED, stated with reasons

- **The e-mail keys default on** under the master switch (§F).
- **Three events beyond the brief's eleven** (`INSTALLATION_START_BLOCKED`, `INSTALLATION_REQUEST_CANCELLED`,
  `INSTALLATION_LINK_EXPIRED`), so a refusal, a withdrawal and an expiry are not silent.
- **Completion has its own refusal sentence** ("…before installation can be completed.") when the job is already in
  progress; the brief's sentence is kept for every start.
- **A check-in that cannot read uCRM is refused** (502) when the flag is on: the guard needs the job's title and status,
  and letting it through would be the one path that bypasses the rule.
- **The loopback exemption from HTTPS** exists so the tests drive the real page; production always arrives over TLS through
  uCRM's nginx (`X-Forwarded-Proto`).
- **The rate ledger hashes the address** with a fixed label and purges it after the window — operational telemetry, not
  stored personal data (D11).
- **The prefill never prefills a charge**: there is no authoritative installation charge in this plugin (docs/61 item 7);
  service and equipment are suggested from the client's uCRM services and the KYC application, and staff may change them.
- **A decline notifies nobody** (§S.3); the panel and the trail carry it.
- **Flag off on Uganda answers "not switched on here" (404)** on the six actions, where South Sudan answers "Unknown API
  action" (404): the actions exist in code but are inert; both are 404 and both store nothing.
- **`$_cmJobGuard` now returns uCRM's job** so the signature handler can record the sign-off without a second uCRM read;
  its check is unchanged.

## Not done, by decision

No deployment, no push, no flag on anywhere, no production configuration; no admin waiver (D5); no IP or agent stored
(D11); no decline notification; no retry allow-list change; no uCRM-side detection; no change to `install_checkin`'s
access rule; no second terms version; no change to Domain B or to the AI / document batches (this work is a separate commit
on top of Batch 5, not mixed with it).

---

Built: 5.18.82 · Flag: OFF everywhere · Deployed: NO · Pushed: NO · Production: UNTOUCHED · Domain B: UNTOUCHED ·
Legal review of the terms: PENDING (docs/62)
