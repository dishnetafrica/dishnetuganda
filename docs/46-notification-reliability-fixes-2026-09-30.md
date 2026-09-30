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
| 33 | **New, N-4** (found while building row 6): the 15-minute invoice scanner's log helper reads `$dataDir`, which is not in its scope, so every line goes to `/invoice_notify_cron.log` — two PHP warnings a line, or a file at the root of the filesystem | P3 | `cron_invoice_notify.php` | The helper reads the data directory the script resolved | the log lands in the data directory, with no warning |
| 35 | **New, N-6** (found while building rows 9–11): **a guard kept in a keyed JSON document does not work.** The store reads a keyed document back as a list holding the object unless its table is on `SqliteStore::$FLAT_TABLES`, so `isset($log[$key])` is never true — and each save nests the old data a level deeper. Measured on `winback_log.json`, `invoice_notify_log.json`, `wa_templates.json` and `renewal_remind_log.json` | P1 | `lib/WinBack.php`; `includes/api/api_notifications.php` (row 11) | Win-back: once per ended service, guarded in `notification_dedup` (it went on each of the four days of its window); the invoice scan: row 11. The other effects are recorded in §B and decided in §E | two runs, and a run the next day, send one win-back; the old log, nested, is still honoured |
| 36 | **New, N-8** (found while building row 25): the morning brief asks uCRM for each person's jobs with `assigneeId`, a filter uCRM ignores (`cron/jobs_cache.php` records it). Unnoticed only because the brief never ran: fixed as it stood, it would have sent every technician the whole company's job list, customers' names included | P1 | `cron/staff_jobs_summary.php` | The jobs are read once and each person gets those assigned to them, as My Jobs does, through a verified uCRM link only | a technician's brief holds their jobs and nobody else's; an id typed without the picker matches nobody |
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
| `quote_wa_state.json` | the quote PDFs waiting for a retry (`pdf_pending`) | the retry list never reads back: a quote PDF that failed is not retried | recorded for row 17 |
| `handover_nudge_log.json`, `cashbook_summary_log.json` | staff nudges and the evening summary, once a day | the 02:00 job runs once a day, so no repeat was measured from it | recorded, not changed |

**Why not add these tables to `$FLAT_TABLES`?** It is one line, and it would fix every one of them — in South Sudan as
well, where it would change what customers receive, and it would switch on the renewal reminders wherever they are
enabled. That is §E's to decide; the fixes above are Uganda's, at each sender.

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

Collected as the build goes; completed with the final report.

| # | Decision | Recommendation | Until you decide |
|---|---|---|---|
| E-1 | Apply the payment fix (row 1, D-1) to South Sudan: its `payment.add` dies the same way after the first receipt | **Yes** — the Starlink restore and the app refresh do not run there either | South Sudan keeps 5.18.53 |
| E-2 | The prepaid pause WhatsApp (row 8), word for word as in §B | Confirm, or give the words you want | It is built with these words, and sent only with `billing_model = prepaid` |
| E-3 | The postpaid day-5 text promises suspension "tonight … at midnight". True only if uCRM suspends that night | Check uCRM → Settings → Suspension (the grace period) against it; if they differ, the text should follow uCRM, not the reverse | Unchanged |
| E-4 | The other Uganda fixes for South Sudan (rows 2–11, 20, 24–29, 35, 36 so far) | One at a time, each after its Uganda deployment has been watched. S-1 (row 10) first: any signed-in account there can list, resend and dismiss failed customer messages. **The brief (row 25) must not be fixed there alone**: its query would hand everyone the whole job list (row 36) | South Sudan keeps 5.18.53 |
| E-5 | The renewal reminders (row 35): with `renewal_reminders_enabled` on, they have never been sent — every pass is a dry run. Make them work, or leave them off? | First read the setting on the server. If it is off, leave it off; if it is on, decide whether customers should now start receiving a renewal reminder 4–6 days before each renewal, which they never have | Unchanged: nothing is sent |
| E-7 | The morning jobs brief (row 25) starts: every morning at 07:00, each active account that takes jobs and has a verified uCRM link gets its jobs, or "no jobs today"; the administrator gets a daily list of such accounts with no link | **Keep it**: it is the fix of a message that was meant to go. If the daily list is noise until every link is verified, hold the brief back with `staff_jobs_brief = 0` | It goes after deployment |
| E-6 | Add the keyed files of row 35 to `SqliteStore::$FLAT_TABLES`, for every tenant | After E-5, and with South Sudan's approval: it fixes their win-back repeat too, and it would switch on renewal reminders wherever enabled | Uganda is fixed at each sender |
