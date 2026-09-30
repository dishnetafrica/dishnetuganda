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
| Whether the database copy of the settings holds the WhatsApp connection the settings files hold (docs/45 §2.3) | the watchdog's *transport* alert, or uCRM's log for the plugin (row 32) | whether the scheduled jobs send any WhatsApp at all |

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
| 30 | **M4**: failed WhatsApps wait for a person | P3 | new `lib/NotificationRetry.php`, new `cron/notify_retry.php`, `cron/master.php`, `lib/NotificationService.php`, `tabs/engage/failed_queue.php`, `includes/navigation.php`, `tabs/engage/wa_inbox.php`, `tabs/help/faq.php` | Automatic, bounded retries, only for failures the provider certainly did not accept, only for messages that cannot go stale in the window; then `exhausted` | a refused send is retried and sent once; a timeout is never retried automatically; after the last try the row reads `exhausted` |
| 31 | **New, N-1**: Evolution sends are retried after a timeout, which can duplicate; and two callers send again by themselves after any failure (the AI reply worker through its event queue, the follow-up sender at its next run) | P2 | `lib/EvolutionApiService.php`, `workers/AiReplyWorker.php`, `cron/followup_send.php`, `lib/FollowUpService.php` | A POST is retried only when nothing was sent; a send that may have gone says so, and neither caller sends it again | a timeout after connecting is not retried; the AI reply and the follow-up go once |
| 32 | **Watchdog**: nobody hears when a job stops or the failure queue grows. And (docs/45 §2.3) most scheduled jobs send nothing, and say nothing, when their copy of the settings lacks the WhatsApp connection | P3 | new `lib/NotifyWatchdog.php`, new `cron/notify_watchdog.php`, `cron/master.php`, `lib/NotificationService.php` | A job of its own, second in master's list: one WhatsApp to the administrator and one line in uCRM's log for the plugin when a notification job is overdue or died in a run, when ten or more failed WhatsApps from the last 24 hours wait, or when the database copy of the settings has no WhatsApp connection and the files have one. Each at most once in six hours. A send that finds no connection leaves a log line, once a day per sender | each condition raises one alert and one log line; a second run inside six hours raises none; the log line is written when the alert itself fails; South Sudan: nothing |
| 33 | **New, N-4** (found while building row 6): the 15-minute invoice scanner's log helper reads `$dataDir`, which is not in its scope, so every line goes to `/invoice_notify_cron.log` — two PHP warnings a line, or a file at the root of the filesystem | P3 | `cron_invoice_notify.php` | The helper reads the data directory the script resolved | the log lands in the data directory, with no warning |
| 35 | **New, N-6** (found while building rows 9–11): **a guard kept in a keyed JSON document does not work.** The store reads a keyed document back as a list holding the object unless its table is on `SqliteStore::$FLAT_TABLES`, so `isset($log[$key])` is never true — and each save nests the old data a level deeper. Measured on `winback_log.json`, `invoice_notify_log.json`, `wa_templates.json` and `renewal_remind_log.json` | P1 | `lib/WinBack.php`; `includes/api/api_notifications.php` (row 11) | Win-back: once per ended service, guarded in `notification_dedup` (it went on each of the four days of its window); the invoice scan: row 11. The other effects are recorded in §B and decided in §E | two runs, and a run the next day, send one win-back; the old log, nested, is still honoured |
| 36 | **New, N-8** (found while building row 25): the morning brief asks uCRM for each person's jobs with `assigneeId`, a filter uCRM ignores (`cron/jobs_cache.php` records it). Unnoticed only because the brief never ran: fixed as it stood, it would have sent every technician the whole company's job list, customers' names included | P1 | `cron/staff_jobs_summary.php` | The jobs are read once and each person gets those assigned to them, as My Jobs does, through a verified uCRM link only | a technician's brief holds their jobs and nobody else's; an id typed without the picker matches nobody |
| 37 | **New, N-7** (found while building row 16): `client.invite` shares D-6's block, so inviting a customer to uCRM's client zone suspends their own identity mailbox | P2 | `webhook.php` | With D-6: only a deleted or archived client is suspended | an invitation touches no mailbox |
| 38 | **New, N-9** (found while building row 17): a quote made in uCRM gets `quote.add`'s WhatsApp and, within five minutes, the quote cron's too. The cron's list of sent quotes, which the webhook also checks, sits in `quote_wa_state.json` and never reads back (row 35), and the webhook never took the claim the cron honours | P2 | `webhook.php` | On Uganda every quotation takes the claim (`QuoteWaLedger`) before it is sent, as the cron does | a quote made in uCRM: one WhatsApp, the webhook's; the cron finds the claim |
| 39 | **New, N-10** (found while building row 21): the dunning template screen shows South Sudan's number and addresses in every field nobody has set, and Save stores what the form shows — so the first Save puts the Juba number into Uganda's configuration, where it outranks the tenant profile in every ladder e-mail. Its preview printed Juba's number, company line and website too | P2 | `tabs/admin/overdue_email_tpl.php`, `includes/api/api_crm_misc.php` | On Uganda an unset field shows this install's value (its settings, then the tenant profile), as the e-mail builder already does; the preview prints what the e-mail prints | a Save without typing stores Uganda's number; the preview shows no +211 and no South Sudan line |
| 40 | **New, N-11** (found while building row 23): since 11 September (`4c3edb6`) the ladder's own SMTP sender announces itself with `MailService::ehloName()`, and nothing on the ladder's path loads `MailService`. `master.php` runs its jobs inside one process, so the ladder has the class only if something earlier in that process loaded it. From `main.php`'s tick, unless an earlier job happened to load it, every ladder e-mail fails after connecting — `Class "MailService" not found` — and is logged as a failure. The EHLO test loaded the class itself, so it never saw this | P2 | `lib/OverdueDunningHelpers.php` | On Uganda the sender loads the class it calls | the cron run on its own relays the e-mail. **Whether production's Monday runs failed this way is not known**: its log answers it (§G) |
| 41 | **New, N-13** (found while building row 31): a read (GET) that meets a 500 with a plain-text body retries with the response where its request body belongs, and dies of a TypeError. The reads are the instance and webhook checks | P3 | `lib/EvolutionApiService.php` | On Uganda a read's retry keeps its own body | the read retries, three requests, and ends in an error, not an exception |
| 42 | **New, N-15** (found while building row 30): a person's retry from the Failed Queue could lose later failures, and report a message sent that was not. A retry whose send stopped with an error left the notifier in retry mode, so every later failed send in that process went unqueued, and left its row `retrying`, which no list shows. A retry that sent nothing read the previous send's result: a document row for a number that had opted out read *sent* | P2 | `lib/NotificationService.php` | On Uganda retry mode always ends, the last result is cleared before the send, a retry that sent nothing says so, and the row is claimed in one statement | a later failure after a stopped retry is queued; the opted-out row reads failed, *no attempt was made* |
| 34 | **New, N-5** (found while building row 5): the 02:00 job counts the days to a due date from an instant. uCRM sends a date as midnight in its own zone (`…T00:00:00+0300`); under another zone the count is off by one — measured under Africa/Juba: a date 7 days away counts 6, so the 7-day reminder is never sent, and each earlier tier goes a day early | P2 | `lib/InvoiceReminders.php` | Uganda's run reads the due date as a calendar date. Uganda itself was not affected (Kampala is +0300, like its uCRM); South Sudan's job is unchanged, and whether its uCRM sends +0300 is not known | a +0300 date 7 days away counts 7 under Juba; South Sudan recorded as it is |

## §B The build, row by row

Every change below applies only where `NotifyGate` says Uganda; everywhere else the 5.18.53 code runs, verbatim
(§0.1). Test counts are the suite's own; each test also runs weakened copies of the code and must catch every one.

### Rows 1–4: the payment webhook and one receipt per payment (commits `ebe1ff1`, `46ad439`)

- **Row 1, D-1.** `payment.add` now carries on after the first receipt instead of dying on an undefined lock, so the
  "just paid" marker, the payment push, the Starlink instant restore, the app-cache refresh and the Workbench close
  run, and uCRM gets one answer. `tests/test_payment_webhook_flow.php`: **20**, three weakened copies caught.
  *Customer-visible consequence:* the "just paid" marker now works, so the redundant "Service Restored" WhatsApp
  within three minutes of a payment is no longer sent (C7), as designed.
- **Rows 2–4, D-2, D3a–c.** `lib/ReceiptOnce.php`: the WhatsApp receipt is claimed once per payment (`PAY<id>`), and
  by payment reference (`PAYREF:<ref>`) for a collection receipted before uCRM had it; the e-mail (`PAYWORK<id>`)
  and the receipt PDF (`PAYPDF<id>`) keep guards of their own, so a receipt sent by the app or by staff no longer
  costs the customer the e-mail and the PDF. `tests/test_receipts_once.php`: **23**, five weakened copies caught.

### Rows 5–8 and 20: reminders from one path, in the daytime, with the prepaid rules

- **One path (row 5, D-4, D5).** uCRM's `invoice.near_due` and `invoice.overdue` events are written to the webhook
  log and answered *"recorded"*; they send nothing. Before, each sent once per invoice **per day**, so an event
  raised daily meant a daily "Final Notice". The reminders come only from `lib/InvoiceReminders.php`: 7, 3 and 1
  day before the due date, and 1, 3, 5 and 7 days after, each once per invoice. Its guard keys are the 02:00 job's
  own (`<number>-pre-d7`, `<number>-d3`), so nothing reminded before the upgrade is reminded again after it.
  **uCRM's own e-mail for these events is untouched** (§0.2, V1).
- **Daytime (row 6, N-2).** `cron/customer_reminders.php` runs the reminders, then win-back, once a day at the first
  master cycle between 09:00 and 17:00 Kampala time (`reminder_hour`, `reminder_until_hour`), so a cycle missed at
  09:00 is made up later that day, once (`lib/JobWindow.php`). It is in master.php's job list with
  `'gate' => 'reminders'`, and the dispatch loop skips a gated job before it records anything: on South Sudan it
  never runs. The 02:00 maintenance job's reminder tasks (4a, 4), its new-invoice scan (4b, which the 15-minute
  scanner covers with the same guard) and its win-back say `MOVED` on Uganda. The 15-minute invoice scanner sends
  nothing during quiet hours, 21:00–08:00 (`notify_quiet_from_hour`, `notify_quiet_until_hour`; equal values switch
  them off): it looks back 24 hours, so an invoice raised in the night is announced at 08:00. *Not changed:* the
  `invoice.add` webhook still announces an invoice a person creates, at whatever hour they create it.
- **The claim after the checks (row 7, N-3).** A tier is recorded only once every check has passed, just before the
  send. An invoice paid since the list was read, or a client with no phone, no longer uses the tier up: the
  reminder goes on a later run that day, once the phone is in uCRM.
- **Prepaid (row 8, C1, C2).** With `billing_model = prepaid`, nothing is sent after the due date: those four texts
  speak of suspension and debt. Each is logged (*"SUPPRESSED #… prepaid"*), counted, and not recorded as sent. The
  reminders before the due date still go. The suspension WhatsApp becomes a **pause** notice on prepaid: *"Your paid
  service period for … has ended, so your internet is paused for now. Nothing is cancelled."*, with the pay link, no
  reconnection fee, and "Already paid? Reply…". It says what the service-paused e-mail (approved, live since 15
  September) says; **the WhatsApp wording is listed in §E for your confirmation.** Absent or `postpaid`: the texts are
  today's, unchanged.
- **Win-back (row 20, C9).** `lib/WinBack.php` is the maintenance job's win-back, moved to the daytime run, sent as a
  *proactive* message: a customer who wrote STOP no longer receives it. It is also sent **once** per ended service:
  the old task's guard never read back and it went on each of the four days of its window (row 35, N-6).
- **Also changed, on Uganda's run:** the phone is the first contact that has one (the old job read contact 0 only);
  a list that comes back at uCRM's page limit (500) is reported in the run's log and in uCRM's log for the plugin,
  instead of silently missing reminders past it; the due date is read as a calendar date (row 34, N-5); the scanner's
  log reaches the data directory (row 33, N-4).
- **Unchanged, and flagged:** the postpaid day-5 text says *"Service Suspending Tonight … suspended at midnight"*.
  Whether that is true depends on uCRM's suspension settings, which this work cannot read (§E).
- **Tests:** `tests/test_reminders_one_path.php`, **90**: every tier once; a second run the same day sends nothing;
  the missing phone added, the reminder goes; the old job's keys honoured; prepaid; the page limit; STOP stops
  win-back; uCRM's events recorded; pause vs suspension; the 02:00 job run whole, `MOVED`, nothing sent; the daytime
  job run as master.php includes it; the scanner inside and outside quiet hours; the window, the quiet hours and
  the due date as unit answers; master.php's gate; and South Sudan unchanged in every one of those. Eleven weakened
  copies, each caught.

### Rows 9–11: the Event Map, the failure queue, and the links that act

- **The failure queue (row 10, S-1).** The six queue actions (`notification_queue`, `_retry`, `_retry_bulk`,
  `_dismiss`, `_dismiss_all`, `_purge`) follow the Failed Queue screen's rule, the WhatsApp administrator rule
  (`WhatsAppAccess`). Measured through the real API: a support or sales account gets **403** with the rule's own words,
  and the refused calls change nothing and send nothing; an administrator lists, retries (one WhatsApp) and dismisses as
  before. **The inbox banner's "Retry Now" needed no change:** the router's tab map (`'wa_inbox' => '*admin'`) already
  opens the inbox to administrators only, whatever `wa_inbox_roles` says — measured, a support account granted the
  inbox is not shown it.
- **The links that act (row 11, D-7, C3).**
  - *Test invoice notification:* an administrator's session only — the webhook secret no longer opens it — and only a
    **POST with `confirm=1`**. A GET, even a link that says `confirm=1`, gets **405** and sends nothing.
  - *Invoice scan:* a GET previews, `send=1` or not. Sending is a POST with `confirm=1`, under the guard every other
    invoice sender uses (`INV<number>`, claimed just before the send), so an invoice the webhook or a scanner already
    announced shows as sent and is never announced again; and, as the scanners, only an unpaid invoice (status 1 or 2).
    *Found while building (row 35, N-6):* the old file guard never read back, so until now each run with `send=1`
    re-announced every recent invoice.
  - *`crm_fix_notifications`:* **read-only**. It shows uCRM's current values and says where to change them (uCRM →
    Settings → Notifications). No PATCH reaches uCRM, by GET or by POST.
- **The Event Map (row 9, D-3, C6).** The page says it is reference only and why; the false "Duplicate Prevention
  Active" banner, the on/off switch, Save and Reset are gone; each row says *not used*; a forged save is refused with a
  message. **Test Send** stays: it sends the text to the number the administrator gives, to check the connection.
  *Also measured:* the page could not even show its own saves — `wa_templates.json` is a keyed document the store
  does not read back (row 35).
- **Tests:** `tests/test_notify_staff_controls.php`, **45**, through the real plugin under `php -S` as administrator,
  support and sales accounts: every refusal changes nothing; the controls prove the administrator's path still works;
  South Sudan unchanged in each; six weakened copies, each caught.

### Rows 24–29 and 36: the staff side (S-2 … S-7, N-8)

- **The leaders' "Job Accepted" copy (row 24, S-2).** On Uganda it goes once per job and assignment, as message 2
  does: the claim is `JOBACC<job>:<assignee>`, taken before the first send, and nothing is claimed or sent when the job
  notifier says the job was not waiting to be accepted. A second tap, or an Accept of a job already in progress, tells
  the leaders nothing new; a new engineer's Accept does. The text is unchanged.
- **The morning jobs brief (row 25, S-3; row 36, N-8).** It died on its first line — `new CrmApiClient($config)` hands
  the settings array to a constructor that takes an address, a `TypeError` — so it has never reached anyone. On Uganda:
  - the uCRM client is built as every other job builds it;
  - *found while fixing*: its query filtered by `assigneeId`, which uCRM ignores, so each person would have been sent
    the whole company's job list, customers' names included (row 36). The open and pending jobs of the past week are now
    read **once**, and each person gets those assigned to them, as My Jobs does;
  - a person's jobs are found through their **verified** uCRM link only (docs/44 M7): an id typed without the picker
    matches nobody — measured, a leader with the technician's id typed in gets no brief;
  - it goes once a day per person (`STAFFBRIEF:<account>:<date>`), as a staff message through the support number, to
    the number in international form, as every job message goes; the administrator's list of accounts with no verified
    link, once a day;
  - its link opens My Jobs (`?page=dashboard&tab=scheduling`); without `page=dashboard` it was the sign-in page, which
    sends a signed-in person to their role's dashboard instead;
  - the run's log says what WhatsApp answered — *accepted*, not *delivered* — and carries no phone number;
  - **it goes from the first 07:00 after deployment** (§E-7). `staff_jobs_brief = 0` holds it back
    (`php tools/set_config.php --key staff_jobs_brief --value 0`).
- **Alerts that went nowhere (row 26, S-4).**
  - An administrator alert with no number (`whatsapp_admin_phone` unset) leaves one line a day, per alert, in uCRM's
    log for the plugin, naming the alert and the setting; before, it vanished.
  - An operations alert (`AlertService`: the watchdog, the escalations) with no number (`alert_whatsapp`) does the
    same. One that went, or failed, is a Message Log row like any other send — *sent* meaning WhatsApp took it.
  - *Measured while building:* the alert service read the HTTP status under a key the WhatsApp client does not return,
    so it was always empty; it reads the right one.
- **The alert-number field (row 27, S-5)** suggests `+256 7XX XXX XXX`, the tenant's example, not Sudan's `+249`.
- **The Workbench bulk send (row 28, S-6)** counts what WhatsApp accepted: a refused message is counted as failed,
  with WhatsApp's reason, where it was counted as sent. A message with no text is counted as failed too (the sender
  returns before it records a result). *Unchanged, and stated:* the Workbench claims its guard before it sends, so a
  refused message is not sent again by a second bulk send — it waits in the Failed Queue, as every failed send does.
- **The help page (row 29, S-7).** The WhatsApp answers say what this install does: the Message Log and the Failed
  Queue (and that *sent* means WhatsApp took it); the plugin sends the WhatsApp messages, uCRM its own e-mails, set in
  uCRM; a one-off message goes from WA Inbox & Bot; there is no broadcast screen. Gone: the "Notify tab" that does not
  exist, the WASender server this install does not use, and "our plugin never touches" the invoice, payment and
  suspension messages it sends.
- **Tests:** `tests/test_notify_staff_side.php`, **53**, through the real plugin under `php -S`: the Accept four ways;
  the brief run twice against a week of jobs, a leader with an unverified id and an accountant, and held back once; the
  alerts raised by `tests/fixtures/notify_alert_probe.php` inside the sandbox, with and without numbers and with a
  refusal; both screens; the Workbench against a refusing WhatsApp. South Sudan unchanged in each — its brief still
  dies on its first line. Thirteen weakened copies, each caught, each by the defect itself (a copy that merely crashed
  is never counted as caught).

### Rows 12–14, 16 and 37: credit notes, activation, numbers, identity (D7, D-9, D-10, D-6, N-7)

- **One message per credit note (row 12, D7).** The staff screen's message is the one kept: it says whether the money
  came back in cash, with the reference and the reason. So the screen claims the note (`CN<id>`) as soon as uCRM has
  numbered it — before the cash is booked, because uCRM's `credit_note.add` for it can arrive while the request is still
  running — and only when there is a number to send to. uCRM's event then sends nothing; a note made in uCRM itself is
  announced by the event once, however often it is delivered. The staff screen's amount is in the tenant's currency
  (`UGX 50000`), never `$`.
- **Activation (row 13, D-9, C5).** "Login credentials have been shared via email" is gone: nothing sends them. In its
  place, on Uganda: *"🔑 To sign in to your DishNet account, open this link and enter your phone number. We send you a
  one-time code; there is no password to remember."* and the portal's sign-in address. **For your approval (§E-8).**
- **Numbers in international form (row 14, D-10, M9).** Every WhatsApp and document the plugin sends goes to the number
  in international form, by the helper the sign-in and the job messages already use: `0772 000 917` is sent to
  `256772000917`, where the digits as typed could not be delivered. A number already international is kept as it is,
  whatever its country. An opt-out recorded in either form still blocks — measured both ways. A number that is neither
  international nor a national number of this country is not sent to: it is a failed Message Log row that says why.
- **A draft invoice suspended a mailbox (row 16, D-6; row 37, N-7).** `invoice.add_draft` and `client.invite` share the
  block that suspends the identity mailbox of a deleted or archived client, so a draft invoice suspended the client whose
  id equals the **invoice's** — and an invitation suspended the invited customer's own. **The fix differs from the plan
  in §A, deliberately:** taking the invoice's client instead would suspend the *right* customer each time uCRM drafts
  their recurring invoice. On Uganda neither event touches an identity; a deleted or archived client is still
  suspended. It matters only with `identity_enabled` (not known on the server, §0.2).
- **Tests:** `tests/test_notify_customer_fixes.php`, **32**, through the real webhook, the staff screen signed in as an
  administrator, a fake uCRM (credit notes and services added to it) and the fake WhatsApp. South Sudan unchanged in
  each. Eight weakened copies, each caught.

### Row 15: one message per uCRM event (D10)

- **The guard.** Each webhook message with no guard of its own is claimed just before it is sent, in
  `notification_dedup`, under the event's own id and the message: `EVT:<uuid>:<message>`. When uCRM delivers the same
  event again, the claim is refused and nothing is sent; the log says the event was delivered again. The claim holds no
  text, number or name.
- **Where.** `client.add` — the welcome and the duplicate-number alert, two messages from one event, each claimed on
  its own; `service.add` — the activation WhatsApp and its app push (the e-mail keeps its own guard);
  `service.suspend` — the suspension notice with its e-mail and push, and a VIP's administrator alert; `service.postpone`;
  `service.end`; `quote.approve`; `client.message`. `credit_note.add` is guarded per note since row 12.
- **What it does not hold back.** Two real events are two ids: a second suspension, or a second message typed in uCRM,
  is sent — measured. An event with **no** id is never guarded, because nothing would tell two of them apart; measured
  too. The devices of a suspended customer are still blocked on every delivery: that step is idempotent and sends
  nothing.
- **Whether uCRM delivers an event twice is not known (V4).** The guard costs one table row per message and changes
  nothing when events arrive once.
- **Tests:** `tests/test_notify_event_once.php`, **23**: every event above delivered twice through the real webhook,
  with the webhook's own key; twelve claims for twelve messages; South Sudan sends each redelivery again, as before, and
  writes no claim. Five weakened copies, each caught — no guard, a key without the event, a key without the message, the
  suspension site unguarded, events without an id sharing one claim.

### Rows 17 and 38: one WhatsApp per quote (D2c, N-9)

- **One claim for every quote sender.** `wa_sent_quotes` (`QuoteWaLedger`) already kept the quote cron's two flows
  apart, and a KYC customer's quote under `kyc_messages_like_crm`. On Uganda every sender now takes it before sending:
  `quote.add` (`webhook`), the quote screens — lead quote, manual quote, and the LTE registration's quote
  (`plugin_lead`, `plugin_manual`, `plugin_kyc`) — and the cron, as before. Whoever is first sends.
- **A quote made on the quote screens (row 17, D2c).** The screen makes the quote in uCRM, claims it, and sends its
  WhatsApp; uCRM's `quote.add` for it then stands down, and its log says why. If `quote.add` was first — uCRM's event
  can overtake the screen — the webhook sends the quotation with its PDF and the screen sends nothing more. Its result
  and its quote log then read *sent*, with `wa_sent_by: quote.add`, so nobody is invited to send it again.
- **A quote made in uCRM (row 38, N-9, new).** Measured in South Sudan's copy of the same code: `quote.add` sends it,
  and the cron's second flow sends it again on its next run — two quotation WhatsApps (the texts were counted; the PDFs
  that follow each were not). The store itself warns that
  `quote_wa_state.json` loses its keys when read back. On Uganda `quote.add` claims it, and the cron finds the claim.
- **What changes when a send fails.** Before, on a quote made in uCRM, the cron's second send was the duplicate when
  the webhook's send had worked, and a retry when it had not. Now a failed send is in the Failed Queue (row 10), like
  every other failed WhatsApp; bounded automatic retries are row 30's.
- **Not changed:** the PDF retry list `pdf_pending` in `quote_wa_state.json` still never reads back, so a quote whose
  PDF could not be fetched when its text went stays text-only, as today. Making it read back starts a send that has
  never run in production (§E-9). The e-mail side of quotations (D2a, D2b) is uCRM's and the plugin's quote e-mail —
  row 18 and ownership decision O6.
- **Tests:** `tests/test_notify_quote_once.php`, **19**, through `quote.add`, the manual-quote form signed in as an
  administrator, and the real `cron_quote_wa.php`, against a fake uCRM that makes, lists and prints quotes. South Sudan
  records both duplicates as they are. Three weakened copies, each caught.

### Row 18: uCRM's send for a KYC quote, behind a prepared switch (D-8)

- **What it did.** A KYC quote — a new customer's (the retry job runs the same code) or an existing customer's
  additional service — asks uCRM to send it (`PATCH billing/quotes/{id}/send`), which is uCRM's own quotation e-mail
  (docs/45 N6, reported failing on 9 September and not checked since). The plugin's `quote.add` e-mail can send a
  second (docs/45 D2a). uCRM's answer was never read.
- **What changes by default: nothing.** Which of the two owns the quotation e-mail is decision O6 (docs/45), taken
  after uCRM's own settings are read (V1–V3). So the call stays. `kyc_quote_send_via_crm` unset, or on, makes it exactly
  as before, in both countries; `0` makes none, and the quote is still made in uCRM and its WhatsApp still queued. It is
  a prepared switch, read in both countries (§0.1): unset, it changes nothing anywhere.
  `php tools/set_config.php --key kyc_quote_send_via_crm --value 0` sets it.
- **uCRM's answer, on Uganda.** A refusal leaves one line in uCRM's log for the plugin (`[quotes]`) with the quote's
  number, the application or client number, and uCRM's answer, any address or number in it masked; never the
  customer's name or number. The quote and its WhatsApp stand. South Sudan's call is the 5.18.53 call, its answer
  unread.
- **Not done here:** making the KYC quote follow `quote_email_via_plugin` like the plugin's other quotes (docs/45 F4).
  That would stop uCRM's e-mail for every KYC quote whenever the plugin's quotation e-mail is on — a change in who
  e-mails customers, which is O6.
- **Tests:** `tests/test_notify_kyc_quote_send.php`, **24**, through the real `KycService`, from a copy of the code,
  against the fake uCRM: both places the send is made; unset, `0` and `1`; a refusal on both paths, masked; South Sudan
  unset, refused (nothing new written) and `0`; the switch set through the real `tools/set_config.php`, then read by
  the form. Six weakened copies, each caught. *Found while writing it:* a copy of the code without `profiles/` cannot
  resolve a tenant, so the gate reads false everywhere and every South Sudan check passes for the wrong reason. The
  Uganda refusal check failed on it, and the copy now carries the profiles.

### Row 19: the welcome and "Request Confirmed!" never both (D8)

- **The race.** The KYC form creates the customer in uCRM, then saves its application with the new client's id.
  uCRM raises `client.add` as soon as it has made the client, and the webhook chooses between its welcome and the
  form's own "Request Confirmed!" by looking for an application with that id. When the event overtakes the form, it
  finds none, and the customer got both. The retry job (`KycCrmSync`), which creates the customers the form could
  not, has the same window.
- **The fix, on Uganda.** Before each attempt to create the client, the form and the retry job mark its username
  (`KYCNEW:<username>`, in `notification_dedup`: no new table). A webhook that finds no application looks for the
  mark on the new client's username, treats a marked client as the form's, sends no welcome, and its log says the
  mark decided it. With `kyc_messages_like_crm` on, the switch's path is unchanged: the welcome goes and the form
  sends none. A mark that cannot be written, or read, changes nothing: the webhook decides as before.
- **A limit, stated.** A mark is kept as long as the table keeps any claim (45 days). If a create fails for good and
  someone then makes a client by hand in uCRM with that same plugin-made username (`STAR…`, `FTTH…`) within that
  time, that client gets no welcome; the webhook's log names the mark.
- **Tests:** `tests/test_notify_kyc_race.php`, **14**. The race is forced, not hoped for: the fake uCRM delivers
  `client.add` to the real webhook before it answers the create (with several workers, because the webhook reads
  the client back from it meanwhile). Through the real form and the real retry job: the race — one message, "Request
  Confirmed!"; no race — one message, found by its application (control); `kyc_messages_like_crm` — the welcome only;
  the retry job — no welcome; South Sudan — both, as in 5.18.53. Three weakened copies, each caught: the form writes
  no mark, the webhook ignores it, the retry job writes none.

### Rows 21, 22 and 39: no South Sudan content in Uganda's messages and screens (C8, C4, N-10)

- **The quote resend (row 21).** The staff action that sends a quote again by WhatsApp (`wa_send_quote_pdf`) said
  "Total: $1600000" and "call +211 921 443 006" when uCRM had no PDF, "— $2500000" in the PDF's caption, and
  "Amount: $…" in the administrator's copy. On Uganda: the total in the tenant's currency (`UGX 1,600,000.00`), the
  tenant's support number (`CustomerContact`, which falls back to the tenant profile), and the same currency in both
  administrator copies. A total uCRM does not give is left out, not printed as a bare "$".
- **The app pushes (row 21).** "Invoice … for $1600000 USD" and "Your payment of $250000 USD": the callers pass no
  currency, so it was always USD. On Uganda: `UGX 1,600,000.00`. They go only where an FCM key is set.
- **The ladder's WhatsApp (row 21).** All nine stages were signed "— DishNet Accounts · +211 921 443 009". On Uganda
  they carry the accounts name and number the ladder's e-mails already print: `overdue_email_from_name` and
  `overdue_email_phone` when set, otherwise the tenant profile (`+256 705 993 348`). The ladder runs only where billing
  is postpaid. The credit note of row 21 was fixed with row 12.
- **The settings screen (row 22, C4).** The e-mail card labelled uCRM's mailer "RECOMMENDED" and the plugin's own SMTP
  "FALLBACK", on an install whose setup uses its own SMTP (`tools/email_setup.php` writes `use_ucrm_email=false`) and
  whose uCRM mailer was reported failing. On Uganda the card marks the path this install is set to use as IN USE; with
  uCRM's mailer on, the SMTP box keeps FALLBACK, which is then true. The badges show the saved setting.
- **The ladder template screen (row 39, N-10, new).** Every field nobody has set showed South Sudan's value — the Juba
  number and `accounts@dishnetafrica.com` — and Save stores whatever the form shows. So the first Save would have put
  the Juba number into Uganda's configuration, where it outranks the tenant profile in every ladder e-mail, and now in
  the ladder's WhatsApp too. On Uganda an unset field shows this install's value from the tenant profile, as the
  e-mail builder already did. The preview (`overdue_email_preview`) filled its phone the same way and printed its own
  Juba lines ("Call +211 921 443 002", "DishNet Africa Ltd · South Sudan", `www.dishnetafrica.com`); on Uganda it prints
  the settings' or the profile's.
- **Measured, not changed:** the screen's reply-to field (`overdue_email_reply_to`) is stored but read by no sender; the
  ladder's Reply-To comes from the SMTP settings. Its default on Uganda is the accounts address, for consistency only.
- **Tests:** `tests/test_notify_tenant_text.php`, **30**:
  - the resend, through the real staff API against the fake uCRM and WhatsApp, with and without a PDF;
  - the pushes and all nine ladder stages, through a probe inside the sandbox (`tests/fixtures/notify_text_probe.php`),
    each push captured before it would reach FCM;
  - both screens as a signed-in administrator, Save pressed without typing, and the preview;
  - South Sudan unchanged in each; six weakened copies, each caught.

  The South Sudan comparison (`test_staff_jobs_south_sudan.php`) now also opens the e-mail settings and the ladder
  template screen, byte for byte, with one more indented-tag copy. One value is set aside, and only there: the settings
  card prints the SMTP port, which in the sandbox is its own fake relay's, picked per run. The comparison reads that
  port from the sandbox's settings and replaces it in that one field; any other number on the page still counts.

### Rows 23 and 40: the overdue ladder sends from its own run, and records what went (D-5, N-11)

- **The class the sender calls (row 40, N-11, new).** Row 23's test sent the ladder's first e-mail through a relay
  that answers, and it failed: `Class "MailService" not found`.
  - Since 11 September (`4c3edb6`, the EHLO fix) the ladder's own SMTP sender (`_rawSmtp` in
    `lib/OverdueDunningHelpers.php`) announces itself with `MailService::ehloName()`.
  - Nothing on the ladder's path loads `MailService`: not the cron, not its helpers, not `master.php`. A walk of
    every top-level `require` found that neither `main.php` nor any job scheduled before the ladder loads it either.
  - `master.php` includes its jobs in its own process. The ladder therefore has the class only when something
    earlier in that process loaded it: a job that happened to send an e-mail first, or a cycle started from
    `public.php`, which loads it.
  - From `main.php`'s tick, the plugin's own five-minute run, a ladder e-mail therefore fails after connecting,
    and is logged as a failure, unless an earlier job in that run happened to load the class.
  - The EHLO test (`tests/test_ehlo_name.php`) loads `MailService` itself and reads the senders only as text, so it
    could not see this.

  On Uganda the sender now loads the class it calls. Whether production's Monday runs failed this way is **not
  known**; `overdue_email_log` answers it (§G).
- **The record (row 23, D-5).**
  - For stages 1–8 the log row is written with `INSERT OR IGNORE` under a unique (invoice, stage). When the first
    attempt fails, its row says `success = 0`.
  - The attempt that later goes is ignored, so the "already sent" check never finds a success. The stage then goes
    again at every weekly run until its window closes; stage 1's window (days 14–30) holds up to three Mondays.
  - On Uganda a success now updates that row: `success = 1`, no error, and the time and amount of the send.
  - A failure never does (the update requires the send to have gone). Stage 9, which is monthly, uses both channels
    and keeps its own rows, is untouched.
- **Measured, not changed:**
  - A WhatsApp stage (3, 5) that fails is not retried: its claim is taken before the send, so every later run skips
    it (`wa_dedup`). Which failures may be retried is row 30's (M4).
  - The manual send from the overdue workbench runs inside `public.php`, which loads the class, so row 40 does not
    affect it.
  - The 07:00 cashbook report has the same missing class (§D, N-12).
- **Tests:** `tests/test_notify_ladder_record.php`, **12**. The real cron runs three times, against the fake uCRM and
  the fake relay: with the relay down, with it up, and again.
  - Uganda, the cron on its own: run 1 fails and its row says so. Run 2 relays one message, and its row says it
    went. Run 3 sends nothing and skips the stage as sent.
  - South Sudan on its own: run 2 fails on the missing class, as in 5.18.53.
  - South Sudan with the class loaded first, as from `public.php` (`tests/fixtures/run_with_mail_class.php`): run 3
    sends the stage again, as in 5.18.53.
  - Three weakened copies, each caught: the success not recorded, a failure recorded as a success, and the class
    not loaded.

  The fake relay (`tests/fixtures/fake_smtp_server.php`) now accepts `AUTH LOGIN`, which the ladder's sender always
  sends. It still does not offer it, so a client that authenticates only when offered behaves as before. It records
  the user name, never the password.

### Rows 30 and 42: bounded automatic retries, and a safe manual retry (M4, N-15)

- **What is retried** (`lib/NotificationRetry.php`, `EVENTS`), and only these:
  - receipts: `ops_payment_received`, `ops_invoice_auto_paid`;
  - welcomes: `event_client_add`, `ops_kyc_customer_welcome`;
  - quotations: `ops_quote_created` (uCRM's `quote.add`), `ops_quote_wa` (the quote cron), `ops_quote_text` (the WhatsApp
    tab), `quote_kyc`, `quote_lead`, `quote_cash`, `quote_manual` (the quotation screens).

  They are still true hours later. Not retried, and waiting for a person as before:
  - invoice notices, reminders and balances, which a payment can overtake;
  - service-status and installation messages;
  - staff messages, documents, and every event the list does not name.

  No other path sends a failed receipt again: the payment's claim (`PAY<id>`) is taken before the send and kept (rows
  2–4). The quotation claim is kept in the same way (rows 17, 38).
- **Only when WhatsApp certainly did not take it:**
  - an answer that refused it (an HTTP 4xx, read from the error the client writes, or WASender's status), or
  - a request that never left (row 31's `Not sent — `).

  Never retried automatically, and left for a person:
  - `May have been sent — ` and a gateway's 502 or 504;
  - anything the class cannot read, such as a 500 from Evolution or a WASender timeout.
- **How often.**
  - The job `notify_retry` runs at every master cycle, about every five minutes, gated to Uganda
    (`'gate' => 'retries'`). Its interval is 240 s: an interval of exactly 300 s can skip a cycle of the ~300 s
    heartbeat.
  - It makes three tries at most, 10, 30 and 120 minutes after the attempt before.
  - It makes none later than six hours after the message was queued.
  - It makes at most five tries a run, and starts none after 30 s, inside master's 60 s limit for a job.
  - `attempts` counts every send of the row, including the first send and a person's retries, and bounds the tries.
- **Then `exhausted`.** A failed automatic try that leaves no further try sets the row to `exhausted`.
  - It stays in the Failed Queue, where a person can still retry or dismiss it.
  - The navigation badge and the inbox banner count it with the failed rows.
  - Nothing is tried automatically again.
- **A try that never finished.** A row still `retrying` 15 minutes on (the process stopped during the send) is set to
  `failed` with `May have been sent — its last try did not finish…`, for a person. Before, it stayed `retrying`, which no
  list shows.
- **The Failed Queue screen (Uganda).**
  - A filter, *Retries used up*.
  - Under each status, what happens next: *automatic retry at HH:MM*, *no automatic try left*, or *may have been sent:
    check the chat first*.
  - Retry and Dismiss on exhausted rows.
  - **Retry All leaves out every row that may have been sent**, and says how many; those are retried one at a time.
  - The empty queue no longer says every message is "delivering successfully" (J7: sent is not delivered).
  - The FAQ answer says what is retried automatically.
- **Consent.** A retry goes through the same opt-out check as the first send, in the same class. A customer who has since
  opted out of everything is not sent it, and the row says *no attempt was made*. The automatic retry does not try it again.
- **Row 42 (N-15, new): a person's retry, made safe beside the automatic one.** Two defects are measured on 5.18.53 (South
  Sudan in the test):
  - A retry whose send stopped with an error left the notifier in retry mode. Every later failed send in that process
    went unqueued. The row stayed `retrying`.
  - A retry that sent nothing read the previous send's success. A document row for a number that had opted out of
    everything read *sent*, and no request was made.

  On Uganda:
  - retry mode ends in a `finally`;
  - the last result is cleared before the send;
  - a retry that sent nothing records `Not sent — no attempt was made…`;
  - one that stopped with an error is recorded as one that may have been sent.

  The row is also claimed in one statement (`UPDATE … WHERE status IN ('failed', 'exhausted')`). Before, it was read, then
  marked, which left a window in which two retries could both send it. **That window is microseconds wide and was not
  measured.** The claim closes it by construction; the test proves only that a row being retried is refused.
- **The six statuses the brief names.** Provider acceptance is kept apart from delivery.

  | Status | Where it is | Means |
  |---|---|---|
  | queued | a `failed` row with an automatic try pending (the screen shows its time) | the retry will send it |
  | attempted | a row whose error begins `May have been sent — ` (row 31), or one still `retrying` | it may have reached the customer; only a person may send it again |
  | sent | Message Log `success = 1`; a queue row `sent` | WhatsApp accepted it. **Not** delivered |
  | delivered | — | **Not measured** (M5, §D). Never claimed |
  | failed | a `failed` row | refused, or never left; waits for the retry or a person |
  | retry-exhausted | an `exhausted` row | the automatic tries are spent; waits for a person |
- **Not covered.**
  - A message that a person sent again by hand, outside the Failed Queue, is not seen by the retry: its row should be
    dismissed.
  - The retry does not re-read uCRM: the listed messages do not depend on later state.
  - E-mails are not retried (§D, M4).
- **Time zone.** `created_at` is SQLite's UTC; `last_attempt_at` is written with `date()`, in the install's zone.
  - The schedule reads `last_attempt_at` in that zone. Every entry point that writes it applies the zone: `public.php`
    and `master.php` (`dn_tz_apply()`).
  - Measured in the harness: a row written in another zone looked three hours old. The seed now writes in the plugin's
    zone.
  - A writer in another zone would only move a try earlier or later; it cannot make one that may have been sent eligible.
- **Tests:** `tests/test_notify_retries.php`, **57**. They use the real notifier and the real job, from a copy of the plugin
  (`tests/fixtures/notify_retry_probe.php`), against the socket-level fake Evolution. Cases:
  - refused, refused, taken: 3 requests, *sent*;
  - a timeout: 1 request, never tried, left out of Retry All;
  - refused every time: 4 requests, *exhausted*, a person can still retry it, no automatic try follows;
  - never left, then back: sent;
  - a reminder and a staff message untouched, a welcome and a quotation tried;
  - a row being retried refused; an unfinished try shown to a person;
  - both row 42 defects, measured on South Sudan and fixed on Uganda;
  - South Sudan: no retry;
  - the classifier and the schedule;
  - the screen, the badge and a person's retry of an exhausted row, in both countries.

  Ten weakened copies, each caught.
- **South Sudan, byte for byte.** Its page comparison (`tests/test_staff_jobs_south_sudan.php`) now also draws the
  Failed Queue list, with two failed rows, and the WA Inbox, against the baseline from Git.
  - Its first run caught what the gate had missed: the `exhausted` style line printed on every South Sudan Failed Queue
    page (57 bytes, found on WA Events).
  - Reading the page's other changes for the same fault found three more, each also outside the gate: a line break in
    each row's status cell, four spaces before the filter buttons, and a full stop added to the Retry All message.
  - All four are now behind the gate, with the control tags at column 0. Two more weakened copies prove that the
    comparison catches the first two. **51** assertions, 0 failed.

### Rows 31 and 41: a WhatsApp that may have gone is not sent again (N-1, N-13)

- **The client (row 31, N-1).** `EvolutionApiService::request()` retried every failed transfer up to three times, a
  POST included, under the comment *"Connection never completed — safe to retry regardless of method."*
  - curl fails in the same way when the request left and no answer came back. So a timeout after Evolution had taken a
    message sent it twice more.
  - **Measured** with a fake Evolution that takes the request and never answers: on South Sudan (5.18.53) the same
    message arrives three times.
  - On Uganda a POST is retried only while nothing has left: before the connection and the TLS handshake are done
    (curl's pre-transfer time 0, nothing uploaded). Such a failure begins `Not sent — `.
  - After that the POST fails at once, and begins `May have been sent — `. A 502 or 504 from a gateway in front of
    Evolution counts the same way, since it may have forwarded the request. An HTML answer now names its status, so
    that the two can be told apart. Reads are retried as before.
- **The two callers that sent again by themselves (row 31).**
  - *The AI reply worker* threw on a failed send. Its event queue then asked the AI again and sent again, after 10 s,
    then 30 s. **Measured** on South Sudan: two runs, the AI asked twice, the reply sent six times. On Uganda, after a
    send that may have gone, the event ends and the conversation goes to a person: marked for a human, the team
    alerted, no holding line sent on top.
  - *The follow-up sender* left a failed draft approved, so it went again at its next run, five minutes later, without
    limit. **Measured** on South Sudan: six sends over two runs. On Uganda a send that may have gone sets the draft
    aside as `uncertain` and logs why. The follow-up stays open, and its next draft, if any, still needs a person.
- **The read that died (row 41, N-13, new).** A read that met a 500 with a body that is neither JSON nor HTML retried
  with the response where its own request body belongs, and died of a TypeError (**measured**). On Uganda a read's
  retry keeps its own body.
- **Found by reading, not changed:**
  - AlertService releases its cooldown after a failed alert, so an alert that may have gone can go again. Those are
    staff, not customers.
  - An e-mail whose relay timed out after the body was sent is reported "Message body rejected" by MailService: the
    same doubt. No e-mail is retried automatically, and row 30 retries none.
  - The lead pages' own WhatsApp client falls back to the notifier after any error (§D, N-14).
- **Tests:** `tests/test_notify_evo_retry.php`, **23**.
  - One call at a time (`tests/fixtures/evo_retry_probe.php`) against a fake Evolution at socket level
    (`tests/fixtures/fake_evo_raw.php`), which records every connection and every request that arrives:
    - no answer after the request arrived, or the connection closed: one request, "may have been sent";
    - a TLS handshake that fails: three connections and no request, "not sent";
    - a refusal and a success: one request each;
    - a read meeting a 500 with a plain-text body: three reads and an error.
  - The error texts, as the retry job and the workers read them.
  - The real AI reply worker (the AI a stand-in) and the real follow-up cron, from a copy of the plugin
    (`tests/fixtures/no_resend_probe.php`), each run twice against the fake.
  - South Sudan as in 5.18.53 in each, the TypeError included. Five weakened copies, each caught.

### Row 32: a watchdog for stopped jobs and piling failures, and the silent send (docs/45 §2.3)

- **What it watches** (`lib/NotifyWatchdog.php`), from master.php's own record and the failure queue:
  - **overdue**: a notification job whose last run is older than its limit. The limits are at least three of the job's
    own intervals (30 minutes at the least), 26 hours for a daily job and 8 days for the weekly ladder, so a healthy
    job is never named. A test checks every limit against master's registrations:

    | Job | Limit |
    |---|---|
    | `event_processor`, `ai_reply` | 30 min |
    | `quote_wa`, `followup_send`, `notify_retry` | 1 h |
    | `inv_notify`, `wa_watchdog` | 3 h |
    | `staff_jobs`, `customer_reminders`, `maintenance` | 26 h |
    | `overdue_email` | 8 days |

  - **unfinished**: a job whose record still reads `duration_ms = -1`. master writes -1 before a job and its time
    after, and the watchdog runs inside master under its lock, so a -1 on any other job is a process that died in it,
    taking every job after it in that cycle with it. The watchdog's own -1 is skipped;
  - **piling up**: ten or more failed or exhausted WhatsApps queued in the last 24 hours;
  - **no transport** (docs/45 §2.3): the database copy of the settings, which most scheduled jobs read alone, has no
    WhatsApp connection, while the settings files have one.
- **How it says so.** One WhatsApp to the administrator (`sendAdmin`, event `ops_watchdog_<condition>`), beginning
  *"⚠️ DishNet plugin watchdog:"*, and one line in uCRM's log for the plugin (`[watchdog]`).
  - The log line is written first and whatever happens to the WhatsApp: when the queue piles up, the WhatsApp is the
    thing most likely to be failing.
  - Each condition is alerted at most once in a six-hour window, marked in `notification_dedup`.
  - **The WhatsApp needs `whatsapp_admin_phone`**, which was not set on 27 September (docs/45 M10). Until it is, the
    alert reaches nobody by WhatsApp, and the log line is the whole of it (row 26 records the missing number too).
- **Where it runs.** Its own job, `notify_watchdog`, registered second, straight after the Starlink keep-alive, so a
  job that spends master's time budget cannot starve it. Every third cycle: 840 s, not 900, for the keep-alive's
  reason.
  - It cannot report master itself stopping, because it runs inside master. uCRM's tick (`main.php`) and an
    administrator's visit to the dashboard both start master.
- **The silent send.** `sendVia`, `sendDocument` and `sendImage` returned without a word when neither WASender nor
  Evolution was set up for the sender. On Uganda they now leave one line in uCRM's log for the plugin, once a day per
  sender: *"a WhatsApp (event) was not sent: no WhatsApp connection is set up for the … sender in the settings this
  process read (docs/45 §2.3)"*.
- **What this does not do, deliberately.**
  - It does not make the scheduled jobs read the settings files. Which copy is right is a production fact (§0.2), and
    a job that sends nothing today would start sending to customers. The watchdog says which copy lacks what; a person
    saves the settings.
  - It restarts nothing, and it does not measure delivery.
- **Tests:** `tests/test_notify_watchdog.php`, **34**. The real `cron/notify_watchdog.php` runs from a copy of the plugin
  (`tests/fixtures/notify_watchdog_probe.php`), with master's record written as master writes it, against the
  socket-level fake Evolution. It proves:
  - each condition raises one alert and one log line;
  - a job within its limit, a job never run and the watchdog's own record are not named;
  - nine waiting raise nothing; two runs raise one alert, and six hours on, a second;
  - a refused alert still leaves its log line;
  - the settings copies are compared, and a send with no connection leaves one line for two sends;
  - South Sudan: nothing;
  - master's registration: second, gated, every watched job one master runs.

  Ten weakened copies, each caught. One proves the no-connection line's daily limit: without it, two sends leave two
  lines, so both sends reached it.

### Row 35 (N-6): guards that never read back

`SqliteStore::save()` stores a keyed document as one row; `load()` gives it back as a list holding that object
unless the table is on `$FLAT_TABLES` (the store's own comment says two caches were lost this way before). Measured
by saving and reloading each file, then through the real jobs:

| File | What its guard was for | What happened | Now |
|---|---|---|---|
| `winback_log.json` | one win-back per ended service | **sent on each of the four days of its 7–10-day window** | Uganda: `WB<id>` in `notification_dedup`, once; the old log still honoured, read flattened. South Sudan unchanged, recorded by the test (§E) |
| `invoice_notify_log.json` | the admin invoice scan's "already sent" | every run with `send=1` re-announced every recent invoice | Uganda: the shared `INV<number>` guard (row 11) |
| `wa_templates.json` | the Event Map's saved texts | the page never showed a saved text (and no sender read it anyway) | row 9 |
| `renewal_remind_log.json` | the renewal pass: once a day, once per customer per renewal month | its `_last_run` never reads back, so **every pass looks like the first, which is a dry run**: with `renewal_reminders_enabled` on, it logs a dry run every 15 minutes and **never sends** | **not changed** — making it work would start a customer message that has never gone out (§E-5) |
| `quote_wa_state.json` | the quotes already sent (`sent_ids`), and the PDFs waiting for a retry (`pdf_pending`) | the sent list never reads back, so a quote made in uCRM went twice (row 38); the retry list never reads back, so a quote PDF that failed is not retried | Uganda: the claim of rows 17 and 38; `pdf_pending` not changed (§E-9) |
| `handover_nudge_log.json`, `cashbook_summary_log.json` | staff nudges and the evening summary, once a day | the 02:00 job runs once a day, so no repeat was measured from it | recorded, not changed |

**Why not add these tables to `$FLAT_TABLES`?** It is one line, and it would fix every one of them — in South Sudan as
well, where it would change what customers receive, and it would switch on the renewal reminders wherever they are
enabled. That is §E's to decide; the fixes above are Uganda's, at each sender.

### Found by the first full run of the suite, and fixed

The first full run on this work failed four of its files. Two were faults of this work:

- **South Sudan's pages were no longer byte for byte (rows 9, 27, 29).** Each Uganda-only branch in a page was
  written with its `<?php if … ?>` tags indented. PHP prints the spaces before a tag and drops the newline after it,
  so the South Sudan branch — the 5.18.53 text — came out with its whitespace changed: **32 bytes more on the Message
  Log** (commit `b482832`, measured by `tests/test_staff_jobs_south_sudan.php`). The AI setup page and the help page
  (`5c5ce42`) had the same pattern, on pages that test did not yet open. A browser folds the spaces, so nothing
  visible changed, but §0.1 promises the 5.18.53 page and the test holds it to the byte. The tags now start in
  column 0; on South Sudan each page renders as 5.18.53 did. The test now opens the Event Map, the AI setup page and
  the help page too, and three weakened copies — one indented tag in each file — are each caught: **43**.
- **The notification test harness chose the timezone itself.** `test_timezone` allows a zone name such as
  `Africa/Kampala` in code only inside a `test_*` file, so that no shared file decides a clock. The harness written
  for rows 1–8 (`tests/fixtures/notify_harness.php`, `notify_units_side.php`) chose Kampala or Juba by tenant. The
  three suites that use it now name the zone, and the harness refuses to start without one. No plugin file changed;
  the three still pass: **20**, **23**, **94**.

The other two were not faults of the code: `test_currency_sweep` flagged a comment of row 12 that quoted a dollar
sign (reworded, `0f72e2a`), and `test_cli_data_dir` could not read the copy under test — it runs part of itself as
the unprivileged `nobody` user, and that copy sat in a private directory. The final runs use a copy it can read.

## §C Ownership: who sends what, and what this work leaves where it is

The rule of 15 September still holds, and this work follows it: **one owner per event and channel, and the plugin's
sender is proven before uCRM's is switched off**. In that record's words: *"Turning uCRM off first creates silence,
which is worse than duplication"* (UGANDA-EMAIL-OWNERSHIP.md:40-42; docs/45 §5).

**No uCRM notification was switched on or off, and no plugin e-mail switch was changed.** Where uCRM may send its own
copy of a plugin message, both stay until you decide (O1–O6), after uCRM's own settings are read (V1–V3).

### §C.1 What stays where

| Messages | Owner after this work | What this work changed |
|---|---|---|
| Customer WhatsApp, every event | the plugin (uCRM has none) | once per event and per payment (rows 2–4, 12, 15, 17, 19, 38); one reminder path, in the daytime (5–7); wording that was wrong (8, 13, 21); numbers in international form (14) |
| Portal login codes | the plugin | nothing |
| Staff job messages and the morning brief | the plugin | rows 24–29, 36 |
| Admin alerts | the plugin, to numbers that are not set (M10) | an alert with no number now leaves a plugin-log line (row 26); the watchdog is row 32 |
| Transactional e-mail: invoice, receipt, welcome, paused, resumed, installation, support, quotation | the plugin since 15 September; uCRM possibly too (N1, N4, N5, N6, none verified) | nothing: the duplicates D1, D5 and D9 wait for O1–O3 and O6 (§D) |
| The overdue e-mail ladder (postpaid only) | the plugin | it sends from its own run, and records what went (rows 23, 40) |
| uCRM's reminder e-mails (N2, N3) | uCRM, if switched on (not verified) | nothing: O4, O5 |
| A KYC quote's e-mail | uCRM, asked by the plugin (N6); the plugin's `quote.add` e-mail may send a second (D2a) | a prepared switch, unset = as today (row 18); the decision is E-10 |
| Client-zone invitation, forgotten password, tickets, uCRM's staff notices, Mailing (N7–N11) | uCRM | nothing; the activation WhatsApp no longer promises an e-mail nobody sends (row 13) |
| uCRM's notification settings | uCRM's own screens | the plugin's link that could change two of them is read-only on Uganda (row 11) |

### §C.2 Duplicates removed without changing uCRM

Every duplicate this work removes lies inside the plugin: two plugin paths, or one path run twice:

- the receipt (rows 2–4);
- the reminders (5–7);
- the credit note (12);
- redelivered events (15);
- the quotes (17, 38);
- the welcome and "Request Confirmed!" (19);
- the ladder's weekly repeat (23);
- the leaders' copy (24).

**Duplicates between the plugin and uCRM are not touched** (D1, D5, D9, D2a): removing one would mean switching one
sender off, which is O1–O6.

### §C.3 Consent and opt-outs (row 20)

- **"STOP" now stops what is promotional:** the AI follow-ups, as before, and win-back (row 20).
- **It does not stop service messages:** invoices, receipts, reminders, notices of a pause, suspension or
  resumption, job and installation messages, and login codes. They are about the service the customer pays for. This
  was already the plugin's rule (docs/45 §1, the opt-out row), and this work keeps it.
- **The outage notice stays a service message. The reason:** it is one fixed text telling a customer that their own
  internet will be down, on a date and within a time window (`outageAlert`, sent by `notify_outage`,
  `includes/notify_actions.php:49-84`).
  - Like an invoice, it is about the service they pay for, and a customer who has stopped marketing still needs it.
  - Nothing can send it today, because it has no screen (S-7, G10).
  - If G10 gives it a screen with free text, that screen must choose per message: maintenance is a service message,
    while announcements and news are promotional and honour STOP.
- **E-mail** has no opt-out check and no unsubscribe header. The plugin sends transactional e-mail only; promotional
  e-mail belongs to uCRM Mailing (O7), which has its own. Consent for anything promotional is M8 (§D).

## §D Deferred, with the reason

| # | Finding | Why not now |
|---|---|---|
| M1 | No e-mail for a customer with no phone | Adds e-mails that do not exist today on the invoice, receipt and service paths. Whether uCRM already e-mails those customers is not known (V1), so this could create duplicates. It also restructures live handlers (docs/45 G2) |
| M2 | No e-mail for draft or scanner invoices | Same: a new e-mail whose uCRM twin may exist (V1, V4) |
| M3 | No notice when an installation moves | A new customer message: wording needs your approval first (G4) |
| M4 (e-mail) | A failed customer e-mail is never retried (the WhatsApp half is row 30) | There is no failure queue for e-mail: the ladder records its failures in `overdue_email_log`, the other e-mails in `customer_email_log`. A retry needs one, and a decision on which e-mails stay true. And the SMTP sender cannot yet tell *not sent* from *may have been sent*: a relay that times out after the body is reported `Message body rejected` (§B, rows 31 and 41). **Recommended:** that distinction first, then a retry of the same three kinds as row 30 |
| M5 | Delivery receipts | Operator position since docs/44 (J7, V5): not captured until one real `messages.update` event has been recorded, and linking needs a column (a schema change). "Sent" keeps meaning *handed to WhatsApp* |
| M6 | SMS | No provider for this plugin (G9) |
| M7 | Bulk e-mail | uCRM Mailing is the candidate (O7), after V2 and V5 |
| M8 | Consent and unsubscribe for promotional messages | Beyond win-back (row 20): a design of its own, needed before any marketing (G5) |
| M12 | No production proof | A controlled test with test accounts after deployment (§G). Tests here prove the code, not delivery |
| D1, D5 (uCRM's e-mail), D9 (uCRM's notice) | uCRM may send its own copy | Ownership decisions O1–O5, after V1–V3. **uCRM's notifications are not switched off by this work** |
| G10, S-7's screen | A WhatsApp broadcast screen | A new feature and a decision |
| G6 | Uganda-branded uCRM templates | Only if uCRM keeps any client e-mail after O1–O3 |
| N-14 | The lead pages (Sales → WhatsApp leads, Engage → WhatsApp) send with the older client, `EvolutionApiClient`, and after **any** error, a timeout included, send the same text again through the notifier. So a lead can get it twice. When the notifier is used, the page reports a failure whatever happened, because it reads a result the notifier does not return (`sendVia` returns nothing) | They are staff actions on leads, not automatic notifications, and a fix means changing that older client, which cannot tell "not sent" from "may have been sent". **Recommended:** fall back only when nothing was sent, and read the notifier's real result |
| N-12 | The 07:00 cashbook report (`lib/DailyReportService.php`, sent from `main.php`) makes the same `MailService::ehloName()` call without loading the class, and catches only `Exception`, which a missing class is not. Measured by reading, and by loading exactly what `main.php` loads before it: the class is absent. So from `main.php` the report connects, fails, and — failing before its day is marked — tries again at every tick until midnight | It is the accounts team's cashbook report, not a customer notification, and this work changes no reporting. **Recommended as its own one-line change** (the same class load), for both tenants; its log line `Daily report ERROR` says whether it has been failing |

## §E Decisions for you (none needed to review this work)

Collected as the build goes; completed with the final report.

| # | Decision | Recommendation | Until you decide |
|---|---|---|---|
| E-1 | Apply the payment fix (row 1, D-1) to South Sudan: its `payment.add` dies the same way after the first receipt | **Yes** — the Starlink restore and the app refresh do not run there either | South Sudan keeps 5.18.53 |
| E-2 | The prepaid pause WhatsApp (row 8), word for word as in §B | Confirm, or give the words you want | It is built with these words, and sent only with `billing_model = prepaid` |
| E-3 | The postpaid day-5 text promises suspension "tonight … at midnight". True only if uCRM suspends that night | Check uCRM → Settings → Suspension (the grace period) against it; if they differ, the text should follow uCRM, not the reverse | Unchanged |
| E-4 | The other Uganda fixes for South Sudan (rows 2–42 so far; row 18's switch is already read there, unset) | One at a time, each after its Uganda deployment has been watched. S-1 (row 10) first: any signed-in account there can list, resend and dismiss failed customer messages. **The brief (row 25) must not be fixed there alone**: its query would hand everyone the whole job list (row 36) | South Sudan keeps 5.18.53 |
| E-5 | The renewal reminders (row 35): with `renewal_reminders_enabled` on, they have never been sent — every pass is a dry run. Make them work, or leave them off? | First read the setting on the server. If it is off, leave it off; if it is on, decide whether customers should now start receiving a renewal reminder 4–6 days before each renewal, which they never have | Unchanged: nothing is sent |
| E-7 | The morning jobs brief (row 25) starts: every morning at 07:00, each active account that takes jobs and has a verified uCRM link gets its jobs, or "no jobs today"; the administrator gets a daily list of such accounts with no link | **Keep it**: it is the fix of a message that was meant to go. If the daily list is noise until every link is verified, hold the brief back with `staff_jobs_brief = 0` | It goes after deployment |
| E-8 | The activation sentence (row 13), word for word as in §B | Confirm, or give the words you want | It is built with these words |
| E-9 | The quote PDF retry list (rows 17, 35): a quote PDF that could not be fetched when its text went is queued for a retry that never reads back. Make it work, or leave it | Leave it until a quote has gone out text-only in production: the retry would send a PDF up to an hour after its text | Unchanged: text-only |
| E-6 | Add the keyed files of row 35 to `SqliteStore::$FLAT_TABLES`, for every tenant | After E-5, and with South Sudan's approval: it fixes their win-back repeat too, and it would switch on renewal reminders wherever enabled | Uganda is fixed at each sender |
| E-10 | Who e-mails a KYC quote (docs/45 O6, F4)? Today uCRM is asked to send it, and the plugin's own quotation e-mail may send a second (D2a) | **The plugin, for every quote, as docs/45 recommends**, after uCRM's quotation notice is read (V1) and one real plugin quotation e-mail has been seen. Then, with the plugin's quotation e-mail on (`quote_email_via_plugin`), `kyc_quote_send_via_crm = 0` (row 18) is the whole change. **With it off, that setting would leave a KYC quote with no e-mail at all**, so the two go together | uCRM is asked to send it, as today |
