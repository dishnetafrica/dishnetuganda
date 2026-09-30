# 46 — Notification reliability and delivery: the bug-fix project (baseline and checklist)

30 September 2026. **Authorised:** implementation in the repository only (*"DishNet Notification Reliability &
Delivery — Bug Fix Project"*). **Not authorised:** production deployment, production configuration, live customer
messages, production migrations, switching any live notification on or off. **Source of the defect inventory:**
docs/45 (the audit, commit `ebff24b`), §§4–8.

This document starts as the baseline the authorisation asks for before any code changes. Sections §B onward are
filled in as the work is done, and end as the final report.

## §0 Baseline

| Fact | Value |
|---|---|
| Branch | `claude/study-this-jhe2eg`, at `ebff24b` (docs/45) when this work began |
| Last plugin commit | `6b71ea6` (5.18.53), 30 Sep 05:35 UTC. Every commit after it touched documents only |
| Plugin version in the repository | 5.18.53 (`dishnet-hybrid-sudan/manifest.json`) |
| Installed on the Uganda server | 5.18.53, deployed 30 Sep (docs/44 §16.30, *PASSED 49/0/2*) |
| Fixes since the audit | **none**: no plugin file changed after `ebff24b` |

**Every finding was re-read in the current code** (the line numbers below are today's). All ten defects (D-1…D-10)
and all seven staff-side findings (S-1…S-7) are still present, at the lines the audit gave, except D-10, which is
at `lib/NotificationService.php:2010` (sendVia) and `:1579` (sendDocument).

### §0.1 How South Sudan is kept unchanged

The plugin runs in both countries from one codebase. The authorisation says: *"Do not change South Sudan
configuration or production behaviour without explicit approval."* So every behaviour change below sits behind one
gate, `lib/NotifyGate.php`, which is true only where the tenant profile resolves to Uganda (the same resolution
`StaffJobsGate` has used since 5.18.50). Where it is false, the 5.18.53 code runs, verbatim.

- **The exception, and its reason.** A *prepared switch* that defaults to today's behaviour (D-8) is read in both
  countries, because unset it changes nothing anywhere.
- **Some fixes would help South Sudan too.** The payment crash (D-1) and the unprotected failure-queue API (S-1)
  happen there as well. Each is listed in §E as a decision, with a recommendation. Nothing reaches South Sudan until
  you approve it.

### §0.2 Production facts this work cannot read

These decide how some fixes behave on the server. None is guessed: each fix either works whatever the value is, or
keeps today's behaviour until the fact is known.

| Fact | Where it is read | What depends on it |
|---|---|---|
| `billing_model` (absent = postpaid) | plugin settings (V11) | whether the prepaid rules below apply at all |
| uCRM's own notification settings | uCRM → Settings → Notifications (V1) | every "uCRM also sends one" duplicate (D1, D5, D9) |
| whether uCRM raises `invoice.near_due` / `invoice.overdue` | uCRM's webhook request log (V4) | D-4: which path sends reminders today |
| `whatsapp_admin_phone`, `alert_whatsapp` | plugin settings (V11) | whether any admin alert can reach a person |
| `wa_accounts_number` | plugin settings (V11) | whether the retailer app sends receipts at all (D-2) |
| `identity_enabled` | plugin settings (V11) | D-6 |
| How client numbers are stored in uCRM | uCRM clients | D-10: how many numbers were being sent in a form WhatsApp cannot use |
| The shape of one Evolution `messages.update` receipt | a real event (docs/44 V5) | M5: delivery receipts |

## §A The checklist: every finding, its fix, its test, and what "done" means

**Priority** follows the authorisation: security, payment processing, duplicate financial notifications and
incorrect service-status messages first. **P1** = those; **P2** = other customer-facing defects; **P3** = staff and
administrator side, and reliability.

| # | Finding (docs/45) | Pri | Files | Fix, Uganda only unless said | Acceptance: the test proves |
|---|---|---|---|---|---|
| 1 | **D-1**, C7: `payment.add` dies on an undefined lock after the first receipt | P1 | `webhook.php` | The request carries on after the receipt: the "just paid" marker, the payment push, the Starlink instant restore, the app-cache refresh and the Workbench close all run. One answer to uCRM, not two | through the real webhook: all six run on a first receipt; exactly one 200; no uncaught error; South Sudan still takes the old path |
| 2 | **D-2**, D3a: the retailer app's receipt writes an "already sent" note nobody reads | P1 | `includes/api/api_retailer.php`, `webhook.php` | The app claims the shared `PAY<id>` guard before it sends | a payment collected in the app gets one WhatsApp receipt, not two; the e-mail and the PDF still go |
| 3 | **D3b**: a collection whose uCRM post failed gets a `COL-…` receipt, then a second when the retry job posts it | P1 | `api_retailer.php`, `webhook.php` | The app claims its payment reference; the webhook recognises the reference in the payment's note | one receipt, whichever path posts the payment |
| 4 | **D3c**: when a staff receipt wins, the webhook skips the e-mail, the receipt PDF and the delivery note too | P1 | `webhook.php` | The WhatsApp text has its guard; the e-mail, the PDF and the delivery note each keep their own | the e-mail and the PDF still go once when the WhatsApp was sent elsewhere |
| 5 | **D-4**, D4, D5: two reminder paths, two guards; the webhook's lasts a day, so "Final Notice" can come every day | P1 | `webhook.php`, `cron_maintenance.php`, `cron/master.php`, new `lib/InvoiceReminders.php`, new `cron/customer_reminders.php` | One path: the daily reminder run. uCRM's `near_due`/`overdue` events are recorded, not sent. Each tier once per invoice | every tier goes once, whatever uCRM raises and however often the job runs |
| 6 | **New, N-2**: the daily reminders go out at 02:00 | P1 | same | The reminder run moves to a daytime hour (setting, default 09:00 Kampala) with a window, so a missed hour is caught up the same day | nothing is sent outside the window; a run missed at 09:00 is made up later that day, once |
| 7 | **New, N-3**: a tier is claimed before the checks, so a failed check loses it | P2 | same | Claim just before the send | a skipped check leaves the tier to be sent |
| 8 | **C1**, C2: on prepaid, the overdue WhatsApp still says "suspended at midnight"; the suspension WhatsApp uses postpaid words | P1 | `lib/InvoiceReminders.php`, `webhook.php` | With `billing_model = prepaid`, no overdue WhatsApp in postpaid words (recorded as suppressed, with the reason). Nothing changes while the setting is absent or postpaid | prepaid: none sent, one line saying why; postpaid: the same texts as today |
| 9 | **D-3**, C6: the WhatsApp Event Map's switches and texts are read by no sender | P2 | `tabs/engage/whatsapp.php` | The screen says plainly that these are not used, and its switches no longer look like they work. Wiring them is a decision (§E), because the saved values on the server are not known | the page shows the notice and no live toggle |
| 10 | **S-1**: the failure-queue API checks no role | P1 | `includes/api/api_notifications.php` | The WhatsApp administrator rule (`WhatsAppAccess`) on all six actions, as on the screen | a non-admin gets 403 on each; an admin still works |
| 11 | **D-7**, C3: three GET links act | P1 | `includes/api/api_notifications.php` | Test notification: admin, POST, `confirm=1`, no `debug_key`. Invoice scan: sending needs POST and `confirm=1`, and uses the shared guard, not the old file. `crm_fix_notifications`: **read-only**. uCRM's notification settings are changed in uCRM's own screen | GET sends nothing and changes nothing; the scan cannot resend a notified invoice; no PATCH reaches uCRM |
| 12 | **D7**, C8: a staff credit note sends its own WhatsApp and uCRM's event sends a second; the first says `$` | P1 | `includes/api/api_payments_admin.php`, `webhook.php` | One message per credit note (`CN<id>`); the staff screen's is kept when it sends; the amount in the tenant's currency | one message; UGX, never `$` |
| 13 | **D-9**, C5: activation promises login details "via email" that nothing sends | P1 | `webhook.php` | A true sentence: how to sign in to the DishNet portal (the phone-number code, proven 26 Sep). **Wording for your approval** | the promise is gone; the portal sentence is there |
| 14 | **D-10**, M9: numbers are sent without an international form | P2 | `lib/NotificationService.php` | Put into international form with the existing `PhoneNumber` helper before the opt-out check and the send; a number that cannot be read is logged as such | `07…` goes as `2567…`; an opt-out stored either way still blocks |
| 15 | **D10**: WhatsApp messages with no guard, if uCRM delivers an event twice | P2 | `webhook.php` | A guard keyed on uCRM's event id (`uuid`), claimed just before each such send | the same event twice sends once; two real events send twice |
| 16 | **D-6**: `invoice.add_draft` passes an invoice id where a client id belongs | P2 | `webhook.php` | Use the invoice's client | the right client's identity record is touched (only matters with `identity_enabled`) |
| 17 | **D2c**: a quote made in the plugin sends its WhatsApp, then `quote.add` sends a second | P2 | `lib/QuotationService.php`, `webhook.php` | The plugin's send claims the quote in the ledger `quote.add` already honours | one WhatsApp per quote |
| 18 | **D-8**, D2a: the KYC quote always calls uCRM's send and never reads the answer | P2 | `lib/KycService.php` | A **prepared switch**, `kyc_quote_send_via_crm`, default on (today's behaviour). The answer is read and a failure logged | unset: the same call as today; off: no call; a failed call leaves a line |
| 19 | **D8**: a KYC sign-up can get both the welcome and "Request Confirmed!" | P2 | `lib/KycService.php`, `webhook.php` | A short-lived marker written before uCRM creates the client, which `client.add` honours | the race, forced: one message |
| 20 | **C9**: "STOP" does not stop win-back | P2 | `lib/NotificationService.php` | Win-back is marked promotional (proactive), so a proactive opt-out blocks it. The outage notice stays a service message (reason in §C) | a STOP-ed number gets no win-back; invoices and receipts still go |
| 21 | **C8**: South Sudan content in Uganda paths (`$`, `+211`, USD) | P2 | quote resend, credit note, app push, ladder WhatsApp | Tenant currency and contacts | UGX and Uganda contacts only |
| 22 | **C4**: the settings screen calls uCRM's mailer "RECOMMENDED" | P3 | `tabs/admin/settings.php` | Neutral wording that matches this install | the word is gone on Uganda |
| 23 | **D-5**: the e-mail ladder cannot record a later success, so it resends weekly | P2 | `cron_overdue_email.php` | Record a later success on the same row | a stage sent after a failed try is not sent again. **Runs only where the ladder runs (postpaid)** |
| 24 | **S-2**: the leaders' "Job Accepted" copy goes on every Accept | P3 | `includes/api/api_scheduling.php` | Once per job and assignment, like message 2 | a second Accept sends no second copy |
| 25 | **S-3**: the 07:00 staff-jobs brief dies on its first line | P3 | `cron/staff_jobs_summary.php` | Build the uCRM client the way every other job does | the brief is built and sent (to fakes) |
| 26 | **S-4**, M10: admin alerts go nowhere, silently | P3 | `lib/NotificationService.php`, `lib/AlertService.php` | A line in the plugin log when an alert has no number; watchdog alerts recorded in the Message Log | the missing number is visible; each alert leaves a row |
| 27 | **S-5**: the alert-number field suggests `+249…` | P3 | `tabs/engage/wa_ai_setup.php` | The tenant's example number | `+256` on Uganda |
| 28 | **S-6**: the Workbench counts WhatsApps as sent whether they went or not | P3 | `includes/api/api_crm_misc.php` | Count what the provider accepted | a failed send is counted as failed |
| 29 | **S-7**: the help page sends staff to an outage screen that does not exist | P3 | `tabs/help/faq.php` | Correct the answer. The broadcast screen itself is G10, a decision | the answer names no missing tab |
| 30 | **M4**: failed WhatsApps wait for a person | P3 | new `lib/NotificationRetry.php`, `cron/master.php` | Automatic, bounded retries, only for failures the provider certainly did not accept, only for messages that cannot go stale in the window; then `exhausted` | a refused send is retried and sent once; a timeout is never retried automatically; after the last try the row reads `exhausted` |
| 31 | **New, N-1**: Evolution sends are retried after a timeout, which can duplicate | P2 | `lib/EvolutionApiService.php` | A POST is retried only when the connection was never made | a timeout after connecting is not retried |
| 32 | **Watchdog**: nobody hears when a job stops or the failure queue grows | P3 | new check in the reminder/retry job | An admin alert, and a plugin-log line, when a scheduled job is overdue or failed sends pile up | both raise one alert, with a cooldown |

## §D Deferred, with the reason

| # | Finding | Why not now |
|---|---|---|
| M1 | No e-mail for a customer with no phone | Adds e-mails that do not exist today on the invoice, receipt and service paths. Whether uCRM already e-mails those customers is not known (V1), so this could create duplicates. It also restructures live handlers (docs/45 G2) |
| M2 | No e-mail for draft or scanner invoices | Same: a new e-mail whose uCRM twin may exist (V1, V4) |
| M3 | No notice when an installation moves | A new customer message: wording needs your approval first (G4) |
| M5 | Delivery receipts | Operator position since docs/44 (J7, V5): not captured until one real `messages.update` event has been recorded, and linking needs a column (a schema change). "Sent" keeps meaning *handed to WhatsApp* |
| M6 | SMS | No provider for this plugin (G9) |
| M7 | Bulk e-mail | uCRM Mailing is the candidate (O7), after V2 and V5 |
| M8 | Consent and unsubscribe for promotional messages | Beyond win-back (row 20): a design of its own, needed before any marketing (G5) |
| M12 | No production proof | A controlled test with test accounts after deployment (§G). Tests here prove the code, not delivery |
| D1, D5 (uCRM's e-mail), D9 (uCRM's notice) | uCRM may send its own copy | Ownership decisions O1–O5, after V1–V3. **uCRM's notifications are not switched off by this work** |
| G10, S-7's screen | A WhatsApp broadcast screen | A new feature and a decision |
| G6 | Uganda-branded uCRM templates | Only if uCRM keeps any client e-mail after O1–O3 |

## §E Decisions for you (none needed to review this work)

Filled in with the build.
