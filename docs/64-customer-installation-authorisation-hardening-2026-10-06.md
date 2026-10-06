# 64 — Customer Installation Authorisation — production hardening (plugin 5.18.83, 2026-10-06)

**Status: LIVE in production and SWITCHED ON (Uganda). Deployed 06 Oct, 11:38 UTC (plugin 5.18.83: release `2de810c` on
live 5.18.74, PASSED 29/0/0); switched on by the operator by 11:51 UTC — activation #1 exempted job 20, the one Starlink
installation then in progress; `--after-only` PASSED 23/0/1 (`docs/07`). Scope: jobs titled `Starlink Installation`.
Channel: WhatsApp only; this feature's e-mails are off. As of 11:51 UTC no request had been sent. Domain B (`dishnet-mikrotik-control-plane/`) untouched. The Installation Terms are
unchanged — `INSTALLATION-TERMS-v1.0`, the same SHA-256 — and still DRAFT — SUBJECT TO LEGAL REVIEW (`docs/62`) — a line every customer who opens a link sees.**

This is the work the final pre-release review of 5.18.82 called for. That review found four code blockers and a list of
should-fix items. The operator's instruction was to implement them and make the feature ready for production. Each finding
below is closed in code, proved by `tests/test_install_authorisation.php`, and guarded by a weakened copy of the code that
the suite must catch. `docs/63` stays the record of 5.18.82; its §0.1, added now, lists the statements of that record which
were not true and points here.

**Reading rule.** VERIFIED means read from the code at this commit or proved by a test named here. DECIDED means a choice
taken while building, with its reason. OPEN means a question nobody has answered yet.

## 0. The answer in one paragraph

Every path in the plugin that starts or completes a Starlink installation now asks one question of one function,
`InstallAuth::decision()`: Accept Job, a status update, GPS check-in, **GPS check-out** (unguarded in 5.18.82) and Complete
Job. The answer is yes only for an acceptance given by the client the job belongs to now, or for a job recorded as
**exempt when the feature was switched on** (D3, now an explicit snapshot rather than "uCRM says it is in progress").
Starts and closes made in uCRM's own screen cannot be refused from the plugin, so they are recorded in the job's trail and
the leaders are alerted. Scope is the job's **type** — the title before " — <customer>" — matched against a configurable
list, so repairs and relocations are out and a customer's name changes nothing. The secure link is withheld from every copy
the plugin keeps of a message, and a request is never queued for retry. The database itself refuses any change to an
accepted record. The customer is told a technician was notified only when one was. Every channel is off until someone
switches it on, and a request no channel can carry is refused before anything is recorded.

## A. Enforcement — every path that starts or completes a Starlink installation

### A.1 GPS check-out is guarded (review finding 2) — VERIFIED

`install_checkout` sets uCRM status 2, so it is a completion. It now calls `InstallAuth::guard(…, 2, 'checkout', …)` before
anything is stored, exactly as Complete Job does, and records `INSTALLATION_COMPLETED[checkout]` after the write. A refused
check-out stores no check-out record and leaves uCRM untouched. On Uganda the job page now shows a refused check-out as
refused; it used to print "Checked out" whatever the server answered. South Sudan's page is byte for byte unchanged. The
retailer app already showed the server's error.

Proved: a check-out on a Starlink installation nobody accepted answers 422 and uCRM stays at 0, for a technician and for a
support leader; after acceptance the check-out closes the job and records the event; a job out of scope checks out as
before and leaves no trail (H2).

### A.2 Starts and closes made in uCRM are recorded and alerted (review finding 3) — VERIFIED

uCRM's own screen, app and API can set a job in progress or closed; the plugin cannot refuse that. The job notifier already
compares uCRM's job with the status it last held, inside one transaction. After that transaction, `InstallAuth::observeUcrm()`
now checks a Starlink installation that has moved to 1 or 2:

| Seen | Recorded |
|---|---|
| 0 → 1, 0 → 2, 1 → 2, 2 → 1, and `decision()` would refuse | `INSTALLATION_STARTED_WITHOUT_ACCEPTANCE` or `INSTALLATION_COMPLETED_WITHOUT_ACCEPTANCE`, once per job and event, actor `system`, detail `ucrm;status:<why>;job:<was>><now>` |
| first seen already in progress | recorded the same way — every job in progress at activation is exempt, so this one started after |
| first seen already closed | nothing — it may have closed long before the feature existed |
| an accepted (same client) or exempt job | nothing |

The leaders — every active support leader and admin — are then alerted once per job and kind: WhatsApp while
`install_auth_whatsapp` is on, and an e-mail to each account's own address. The alert names the job and its title; it
carries no customer number, no address and no link. The webhook log gets one line, worded so WA Events does not file it as
a WhatsApp outcome. A second delivery of the same change records and alerts nothing.

Proved (H3): a job started in uCRM is recorded, both leaders are alerted by WhatsApp and e-mail, a repeat delivery is silent,
and the job still cannot be completed, checked out or closed by status through the plugin. A job closed in uCRM is recorded
and alerted; a job first seen in progress is recorded; a job first seen closed is not.

### A.3 Scope is the installation type, not every title that mentions Starlink (review finding 1) — VERIFIED

5.18.82's `inScope()` was "an installation by the photo rules' keywords AND the title names Starlink". Because "starlink" is
itself one of those keywords, it meant "the title contains Starlink". A cable repair, a relocation and a power-issue visit
were all bound, and so was a Fiber installation for a customer named "Starlink Cafe", because the job screens append the
customer's name to the title.

`InstallAuth::inScope($job, $config)` now takes the job's **type**: the title up to the first " — " (an en dash or a spaced
hyphen also counts), or the whole title when there is none. Case and spacing are ignored. The type must equal one of
`install_auth_job_titles`, a comma-separated list whose default is `Starlink Installation`. The customer's name can no longer
bring a job into scope or take it out. A title the list does not name is out of scope, so the operator must list the exact
uCRM job title templates used for Starlink installations (§I.3).

Proved (H1): a Starlink cable repair, a dish relocation, a power-issue visit and the Fiber job for "Starlink Cafe" are out of
scope and work as before; a relocation cannot carry a request; a Starlink Installation for "Starlink Hub Ltd" is bound like
any other; "Starlink Kit Installation" is out of scope until it is listed, and bound once it is.

### A.4 D3 is an explicit activation snapshot (review finding 3) — VERIFIED

The approved D3 exempts the jobs **in progress when the feature is switched on**. 5.18.82 implemented it as "status 1 and no
record", so a start made in uCRM after activation opened the completion guard and left no trace.

Switching the feature on is now its activation. `php tools/set_config.php --key install_auth_enabled --value 1` first reads
uCRM's jobs in progress, then records the Starlink installation jobs among them in `install_auth_exempt`, with one
`INSTALLATION_EXEMPTED` event each and one row in `install_auth_activations`, all in one transaction. Only then is the flag
saved.

- **Refusals.** If uCRM is not configured or does not answer, nothing is saved. If uCRM returns a full page of 500 jobs, some
  could be missing, so it is refused the same way. If the plugin's database does not exist yet, it is refused.
- **Repeats.** Switching it on while it is already on and activated changes nothing. Off and on again is a new activation:
  jobs started while the feature was off become exempt, and an existing exemption keeps its first activation.
- **The tables are append-only by trigger.** An exemption is a fact about the moment of activation, not a setting.
- **The flag set by hand, without the tool,** means no exemption exists: the guard fails closed for every job without an
  accepted record. The panel shows when the feature was activated.

Proved (0, 30, H3): the main test sandbox is switched on through the tool and exempts exactly job 906; 906 is completed with
`INSTALLATION_EXEMPTED` and `INSTALLATION_COMPLETED[complete;exempt]` in its trail; a second switch-on does not exempt jobs
in progress later; an unreachable uCRM switches nothing on; off and on again exempts only the job started in between; a full
page is refused.

### A.5 The one rule — VERIFIED

`InstallAuth::decision()` is the rule every guard, the panel, the list badge and the uCRM-side check read. Whatever status
uCRM reports:

| The job has | May it start or be completed? | `why` |
|---|---|---|
| an accepted record, given for the client uCRM names on the job now | yes | `accepted` |
| an accepted record given for another client | no — the brief's refusal and *"The acceptance on record was given for another customer; this job now belongs to a different customer in uCRM."* | `other_client` |
| a declined record | no, exempt or not | `declined` |
| an exemption from activation | yes | `exempt` |
| no record, pending, cancelled, expired | no | that word |

## B. The secure link is kept nowhere but in the message itself (review finding 4) — VERIFIED

5.18.82's own record said "the raw token is never stored". The review found the whole link in the WA Inbox's conversation
store after every successful send, and in the failure queue after every failed one. Either copy let a staff member open the
page and accept for the customer, in a record that could not tell the difference.

`NotificationService::storable()` now replaces an installation authorisation link with `[secure authorisation link —
withheld]` in everything the plugin keeps of a send: the conversation store the Inbox reads, the Message Log preview, the
dry-run log, the unusable-number log and the failure queue. `NotificationService::NEVER_QUEUED` names `app_otp` and
`ops_install_auth_request`: a failed request is not queued for a retry, because staff send the link again instead and each new
link replaces the last. Evolution's echo of the sent message is still claimed by its message id, so no unredacted copy
arrives through the webhook either. The e-mail log already kept only dedupe keys.

Proved (H4): after the whole run had minted every link it uses, not one of them is in any table of the plugin database, nor
in any file of the data directory with the database and its journal read as raw bytes. Both scans first find a planted value,
as controls. The Inbox still shows each request as sent, with its charges. A request whose WhatsApp failed is not queued.

What remains outside the plugin, and is a fact for legal review (§I.2): the customer's own WhatsApp and mailbox hold the link,
as intended, and so does the chat history of the business WhatsApp account on the phone linked to Evolution.

## C. People are told the truth (review finding 6, and the reassignment edge) — VERIFIED

- **The customer's confirmation names the technician only when the technician was told** — by WhatsApp, by the e-mail copy,
  or earlier for the same acceptance. Otherwise it carries the brief's own line, *"Your installation has been authorised.
  DishNet will assign/confirm the technician separately."* The acceptance page says the team was notified, and that a
  confirmation was sent, only when each is true.
- **When the technician could not be told,** the leaders are alerted once with the reason and the acceptance reference.
  The reasons are: nobody assigned, no verified staff account, two accounts on one uCRM user, uCRM unreadable, or both
  channels failed.
- **A job the notifier first sees after a reassignment** reads as an assignment, and the new engineer still gets "🟢 CUSTOMER
  ALREADY CONFIRMED INSTALLATION". 5.18.82 hooked only the `reassigned` event. The engineer told at the acceptance is not
  told twice, because there is one dedupe mark per acceptance and engineer.

Proved (H6, H12, 7, 27).

## D. The evidence keeps itself (review findings 7 and 9) — VERIFIED

- **An acceptance binds the client who gave it.** If uCRM moves an accepted job to another client, every start and completion
  is refused with the reason, and the panel says so. The record itself is untouched, still naming the client who accepted.
  The remedy is a new job for the new client, or restoring the client in uCRM.
- **The database keeps an accepted record final.** These are triggers in migration 086:
  - `install_auth_accepted_is_final` refuses any UPDATE of an accepted row;
  - `install_auth_never_deleted` refuses any DELETE;
  - `install_auth_status_moves` allows only the lifecycle — pending to accepted, declined, cancelled or expired, and those
    three back to pending only as a new request at a higher sequence;
  - `install_auth_request_is_fixed` keeps a sent request's terms, charges, scope, customer and job fixed.
- **The acceptance and its `INSTALLATION_ACCEPTED` event commit in one transaction.** An accepted row without its event can
  no longer be left behind.
- **A dispute is still an event beside the record,** never an edit of it.

Proved (H5, H8, H9; and in the weakened copies, each floor stands alone).

## E. Channels — each a deliberate choice (review finding 10) — DECIDED and VERIFIED

| Key | Absent | Governs |
|---|---|---|
| `install_auth_enabled` | off | the feature; switching it on is the activation (§A.4) |
| `install_auth_whatsapp` | **off** (new) | every WhatsApp message of the feature: the customer's request and confirmation, the technician's, the leaders' alerts |
| `customer_emails_enabled` | off | the master switch for every customer e-mail, unchanged |
| `customer_email_install_auth_request` | **off** (was on) | the request e-mail |
| `customer_email_install_auth_confirmed` | **off** (was on) | the confirmation e-mail |
| `install_auth_job_titles` | `Starlink Installation` (new) | which job types are in scope (§A.3) |
| `install_auth_link_days` | 14 | unchanged |

**Why absent means off.** 5.18.82 made the two e-mail keys absent-means-on, so that turning the feature on would not leave
the e-mail silently off. On an install whose customer e-mails were already on for receipts, that sent the authorisation
e-mails without anyone having chosen to. Now each channel is chosen. "Silently absent" cannot happen either: a request that
no switched-on channel can carry is refused before any record exists, the request form shows which channels are on, and it
shows why a request would be refused. The technician's e-mail copy (D9) follows the plugin's mail server, as every job
message does.

## F. The public page, the webhook and an outage (review finding 8, and three found while building) — VERIFIED

- **The page writes nothing for a link that names nothing.** A malformed token never reaches the database. A well-formed one
  is looked up by its hash, read-only. Only a link that names a record reaches the rate ledger, whose bucket is that link's
  own token hash. No address is read and no header a client controls chooses the bucket, so the X-Forwarded-For spoof the
  review measured has nothing to act on. Proved (H7): thirty unknown or malformed links write nothing; twenty POSTs on one
  link are answered and the twenty-first is 429, whatever address each claims; another link is unaffected.
- **Resend cooldown, 120 seconds** (`InstallAuth::RESEND_COOLDOWN`, DECIDED). Each resend replaces the link before it, so a
  burst of resends left the customer holding dead links. The cooldown counts from the pending record's last write, which is
  its request or its last resend. A refused resend answers 429 and says how long to wait.
- **A posted `job.delete` is a doorbell.** Found while building: the webhook endpoint carries no key, and 5.18.82 withdrew a
  pending request on the posted body alone. The request is now withdrawn only when uCRM itself answers 404 for the job.
  Otherwise it stays pending and the webhook log says why. Proved (H13).
- **A uCRM outage no longer refuses every GPS check-in.** Found in the review: with the flag on, an unreadable uCRM refused
  check-ins for Fiber jobs too. The check-in and check-out guards now fall back to the plugin's own last record of the job,
  `job_notify_state`'s title and status. A job the plugin knows is out of scope checks in as it always did. A job known
  nowhere is still refused, never let through unread. Proved (H14).

## G. Tests — VERIFIED

| | Result |
|---|---|
| `tests/test_install_authorisation.php`, run 1 | 352 passed, 0 failed (21 weakened copies, each caught) |
| `tests/test_install_authorisation.php`, run 2 | 352 passed, 0 failed (21 weakened copies, each caught) |
| full suite (`tests/run.sh`), run 1 | 278 files, 13,344 passed, 0 failed, 0 skipped |
| full suite (`tests/run.sh`), run 2 | 278 files, 13,344 passed, 0 failed, 0 skipped |
| South Sudan suite (`test_staff_jobs_south_sudan.php`) | 51 passed, 0 failed, 0 skipped, in each full run |

The feature suite is the brief's 32 cases, the lifecycle, the H block (fourteen parts, one per finding) and
21 weakened copies. Each copy removes one guard and must be caught:

- the Accept Job, check-in, completion and check-out guards;
- the guard answering "allowed";
- the acceptance statement's pending condition;
- the scope widened back to "any Starlink title";
- "in progress" counted as exempt again;
- the activation snapshot ignored;
- the link kept in the Inbox, and a failed request queued;
- the client binding removed;
- the page writing a ledger row before the lookup;
- the uCRM-side check removed;
- a posted `job.delete` believed;
- the accepted-is-final trigger dropped, and both floors dropped together, which is the control on the controls;
- the confirmation naming a technician nobody told;
- a request made with no channel;
- the cooldown removed;
- the e-mail keys back to absent-means-on.

Two other suites anchored on lines this work changed. Both were amended, not deleted:
- `test_customer_otp_transport` counted two `$event !== 'app_otp'` conditions in the send layer. The queue's condition is now
  the `NEVER_QUEUED` list, so the pin checks both halves.
- `test_notify_customer_fixes` weakens the unusable-number guard by its exact line, and that line now logs
  `storable($message)`. The anchor follows the line; the weakened copy is unchanged and is caught again. The first full run
  found this one: the anchor was no longer there. The test was amended before the two full runs recorded above.

**Measured while building — a harness lesson, not a product defect.** The first version of the raw-file scan read the
database with this test process's own file handles. That process also holds SQLite connections to the database. Closing any
descriptor on a file drops every POSIX lock the process holds on it (sqlite.org, "How To Corrupt An SQLite Database File",
§2.2). The next read in the test process reported "database disk image is malformed", while a copy of the same file read by
another process checked clean. The scan now runs in a separate `grep` process. The product never reads its database as a
file.

## H. What did not change — VERIFIED

- The Installation Terms: `INSTALLATION-TERMS-v1.0`, the same text, the same SHA-256 (`9308…d2e1`), still DRAFT — SUBJECT TO
  LEGAL REVIEW. No legal wording was changed.
- South Sudan: the page is a 404, the actions do not exist, and the job workflow is as before. Every scheduling-page change is
  inside a Uganda-only branch.
- Domain B: untouched.
- The flag is off by default. With it off, the workflow is byte for byte what it was.
- **Migration 086 was amended, not followed by an 087.** It has never run anywhere but in test sandboxes: 5.18.82 was never
  deployed or pushed. The amendments are additive: two tables, triggers, events, and one CHECK on accepted rows. The tests
  that pin "no migration 087–089" stand.

## I. Production readiness

### I.1 Ready — VERIFIED

Every blocker and should-fix item of the pre-release review is closed in code and proved above. The design decisions D1–D11
stand, with D3 now implemented as approved.

### I.2 Still needed before the feature is switched on — the state when it was switched on, 06 Oct

On 06 Oct the operator switched the feature on with item 1 still open. Of item 2: the titles are `Starlink Installation`,
the channel is WhatsApp only, who may request is unchanged (the assignee may, J6), and the leaders' contact details were not
checked here. Item 3 is done.

1. **Legal approval of `docs/62`.** Its seven questions are unanswered. Four implementation facts belong with them. These are
   facts, not a legal opinion:
   - Section 1's promise holds for every plugin path. A start or close made in uCRM itself is not prevented; it is recorded
     and the leaders are alerted (§A.2).
   - Sections 11 to 13 turn on "dispatch" and "commenced works", which the system does not record as such.
     `INSTALLATION_STARTED[accept]` marks the technician accepting the job, possibly days before arrival. The GPS check-in
     and the photos are the nearest evidence of work on site.
   - Section 22's record proves possession of the link. The plugin keeps no copy of it any more. The customer's messages,
     and the business WhatsApp account's chat history, do.
   - Section 15's sign-off is recorded separately as `CUSTOMER_SIGNED_OFF`.
2. **The operator's decisions:**
   - the exact uCRM job title templates for Starlink installations (`install_auth_job_titles`);
   - whether only leaders and admins should make requests and set charges (today the assignee may, under J6);
   - which channels to switch on;
   - that each leader's staff account has a usable number and e-mail address for the alerts.
3. **A release decision and a push** (§I.4).

### I.3 Production configuration, in order — when the above is done

Each command runs as root on the server and reaches the plugin through the uCRM container, the form 5.18.74's handover
used (`docs/07`, 04 Oct). The first four set the scope and the channels:

```
docker exec ucrm php /data/ucrm/data/plugins/dishnet-hybrid-sudan/tools/set_config.php --key install_auth_job_titles --value "Starlink Installation"
docker exec ucrm php /data/ucrm/data/plugins/dishnet-hybrid-sudan/tools/set_config.php --key install_auth_whatsapp --value 1
docker exec ucrm php /data/ucrm/data/plugins/dishnet-hybrid-sudan/tools/set_config.php --key customer_email_install_auth_request --value 1
docker exec ucrm php /data/ucrm/data/plugins/dishnet-hybrid-sudan/tools/set_config.php --key customer_email_install_auth_confirmed --value 1
```

The two e-mails also need the customer e-mails master switch, `customer_emails_enabled`. That switch belongs to another tool,
`tools/set_customer_emails.php`; `set_config.php`'s listing does not show it. Read it, beside every lifecycle e-mail's own
switch, with:

```
docker exec ucrm php /data/ucrm/data/plugins/dishnet-hybrid-sudan/tools/set_customer_emails.php --show
```

If it is off, `--master on` turns it on — and then every lifecycle e-mail whose own switch is on goes out, not only these
two. Read the listing before deciding. Then switch the feature on. The tool reads uCRM's jobs in progress and prints which it
recorded as exempt:

```
docker exec ucrm php /data/ucrm/data/plugins/dishnet-hybrid-sudan/tools/set_config.php --key install_auth_enabled --value 1
```

Then check it took, in both places the plugin keeps the switch: the configuration files, which the tools and the webhook
read, and the store row, which the pages, the staff actions and the guards read. `set_config.php` writes both. If the
second write ever failed, the webhook would act as if the feature were on while the guards act as if it were off. The
deploy script's own check reads both copies and fails if they disagree:

```
cd /opt/dishnet && bash scripts/deploy-5.18.83.sh --after-only
```

**Switching it off** is a separate act, kept apart from the commands above on purpose. With the flag off the workflow is as
before, and the records and their trail stay as history:

```
docker exec ucrm php /data/ucrm/data/plugins/dishnet-hybrid-sudan/tools/set_config.php --key install_auth_enabled --clear
```

Tell staff not to start or close Starlink installations from uCRM's own screen: it will be recorded and the leaders alerted.
Pilot the first request on a test uCRM client whose contact details belong to a staff member.

### I.4 Deployment — DEPLOYED 06 Oct, 11:38 UTC, switched off; see `docs/07`, the 5.18.83 release entry

Live was 5.18.74 (`db18ad9`). Between it and this commit sat undeployed work (the distributor portal, the AI communication
layer batches), so the deployment is a release commit cut on live that carries this feature alone, with a pinned deploy
script, as 5.18.66–5.18.74 were:
- **`release/5.18.83` = `2de810c`**, parent `db18ad9`: 31 files — this feature's, and two test files the branch had already
  aligned with 5.18.70 (`8cde7ca`);
- **`scripts/deploy-5.18.83.sh`**, pinned to it, with its rehearsal `scripts/harness/deploy-5.18.83/rehearse.sh`.

The script switches nothing on. It refuses to run if `install_auth_enabled` is already on in either place the plugin keeps
it, and checks afterwards that it is still off. Deploying therefore does not wait for the legal review; switching the
feature on does (§I.2, §I.3). The operator ran it on 06 Oct at 11:38 UTC: PASSED, 29 ok / 0 failed / 0 notes. 086 was
applied and complete, its tables empty; the switch was absent from both copies before and after; the customer page answers
404 *"This page is not available."*

## J. Observations — recorded, not changed

- **The staff portal's check-in store nests its records under list-like keys.** This shape predates this feature. The H tests
  search it by job id rather than trust an index.
- **The native app's `job_checkin` / `job_checkout`** (`api/index.php`) record arrival and departure locally and change no
  uCRM status. They are not guarded and need not be.
- **The notifications after an acceptance still run in the customer's request.** The page now ignores a closed browser, so
  they finish, and says only what happened. A slow mail server still slows the page.
- **The assigned technician may set the charges the customer accepts** (J6). Whether that should be leaders and admins only is
  the operator's decision (§I.2).
- **`tests/test_customer_pwa.php` leaves its `php -S` server running** after the suite ends; each full run here left one,
  stopped by hand afterwards. The test predates this work.
