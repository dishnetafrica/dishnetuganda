# 43 — Staff and retailer job assignment, uCRM and WhatsApp notifications: audit

27 September 2026. **Read-only.** Nothing was implemented, deployed, migrated or configured. No job was created, no
record was changed and no message was sent to anyone. **Nothing below is authorised to build; each change in §9
waits for your approval.**

**Why this is docs/43.** You asked for `docs/41-staff-job-assignment-whatsapp-audit-2026-09-27.md`. Number 41 is
already the quotation-summary and price-check record, and 42 is the kit-taxes record. So this is 43.

**Where the facts come from.** Every claim below carries one of three kinds of evidence:

- **Code**: read in plugin **5.18.49** (installed commit `e076632`) at the file and line given.
- **Trace**: proved by running the real code on this machine against a fake uCRM and the repository's fake WhatsApp
  (Evolution) server, with made-up people and numbers.
  - Run it with `php scripts/harness/jobs-trace/trace.php`.
  - **46 of 46 observations matched the code reading, on two runs.** Nothing left the machine.
- **Production**: measured on 27 September at 15:54 UTC by the read-only command in §10.4. The results are in
  §11. Each line that said "production — to measure" now gives the measured answer.
- **Reported by the code-reading pass**: three screens in §2.4 are marked so. They were read once and not
  re-checked line by line here; none of them bears on the answers.

Staff are never named here. The five accounts on your screenshot are described by role and badge:

| | Role on the card | Badges |
|---|---|---|
| the admin account | admin | UCRM #1, No CRM link |
| the accountant | accountant | No CRM link |
| the agent | support | AGENT, Set UCRM ID, No CRM link |
| support #81 | support | UCRM #81, No CRM link |
| support #1581 | support | UCRM #1581, PWD, No CRM link |

---

## In one paragraph

Jobs live in **uCRM Scheduling**. The plugin can create a uCRM job for a technician and send them a WhatsApp from
**My Jobs → ＋ New Job**. The technician can accept, tick tasks, reschedule, comment and complete it in the plugin's
dashboard.

What breaks the chain on Uganda today:

1. **The mapping is overwritten.** Which uCRM user a staff account is gets rewritten by hard-coded **South Sudan**
   lists, on every deploy and every page load (§4.3).
2. **Jobs made in uCRM never reach anyone.** A job typed straight into uCRM reaches the technician only if uCRM's
   own user record carries a phone. On Uganda that record has no phone field at all (measured, §11), and the
   fallback to the staff account's number is broken (§5.1, path B).
3. **Changes are never announced.** Reassignment, rescheduling in uCRM and cancellation send nothing (§5.1).
4. **Replies go to the AI.** A technician who answers on WhatsApp is treated as a customer and queued for the AI
   (§6.3).
5. **Anyone signed in can act on any job.** Any staff account, a retailer included, can read, close or complete any
   job (§6.4).
6. **Failures read as success.** A failed WhatsApp can show as "Delivered" (§7).
7. **Nobody on Uganda can be assigned correctly today** (measured, §11).
   - Every uCRM id the plugin holds names a uCRM user that does not exist.
   - Uganda's uCRM has one user, the admin (id 1000), and the technicians have none.

Retailers cannot hold uCRM jobs at all: they are not uCRM users (§4.5).

---

## 1. Architecture

### 1.1 The pieces

| Piece | What it is | Where |
|---|---|---|
| The plugin | `dishnet-hybrid-sudan` **5.18.49**, a uCRM plugin (UISP 3.0.159 / uCRM 4.5.33 on this host) | `/data/ucrm/data/plugins/dishnet-hybrid-sudan` in the `ucrm` container |
| Staff, retailer and agent accounts | one JSON row per account in the **`retailers`** table of `plugin.sqlite3` | `lib/SqliteStore.php`, the "Staff & Retailers" page `tabs/admin/retailers.php` |
| Jobs | **uCRM Scheduling jobs**, reached through the uCRM API: `scheduling/jobs`, `…/job-tasks`, `…/job-comments` | **no local jobs table** |
| Local side-records of jobs | `scheduling_jobs_cache` (a list for My Jobs), `job_completions`, `job_invoice_queue`, `job_signatures`, `site_surveys` | the plugin store |
| Outbound WhatsApp | `NotificationService` → `EvolutionApiService` → Evolution API, on linked-device (Baileys) numbers | `lib/NotificationService.php:2002`, `lib/EvolutionApiService.php:402` |
| Inbound WhatsApp | Evolution → `public.php?page=evo_webhook` → conversation store + an `ai.reply` event for the AI worker | `evo_webhook.php` |
| uCRM → plugin events | uCRM webhook → `public.php?page=crm_webhook` → `webhook.php` | `webhook.php:2371` (`job.add`) |
| Background jobs | uCRM runs `main.php` → `cron/master.php` → one script per job on its own schedule | `cron/master.php` |

A staff row carries these fields among others: `role`, `is_admin`, `is_active`, `is_employee`, `must_change_pwd`,
`phone` (free text), `email`, **`ucrm_user_id`**, **`ftth_crm_client_id`**, `api_token` / `token_issued_at`,
`modules`, `projects` and `wallet`. Roles in use include `admin`, `accountant`, `support`, `support_leader`,
`support_engineer`, `sales`, `field_agent` and `retailer`. **There is no "technician" role:** technicians are the
three support roles.

### 1.2 How the parts talk

```
                 uCRM (clients · services · Scheduling jobs · staff users)
                    ▲  API (GET/POST/PATCH)            │ webhook: job.add (and every other event)
                    │                                  ▼
  staff browser ── plugin dashboard ── api_scheduling.php        webhook.php (job.add)
  (My Jobs)           │                                   │
                      └──► NotificationService ◄──────────┘
                                 │  Evolution API (support / account / sales numbers)
                                 ▼
                        technician's WhatsApp ──reply──► evo_webhook.php ──► AI assistant queue
```

### 1.3 Files, API actions and tables that matter

| What | Where |
|---|---|
| Menu: My Jobs (needs the `scheduling` module, or admin) | `includes/navigation.php:10-14` |
| Menu: Staff & Retailers | `includes/navigation.php:388-389` |
| My Jobs page and the New Job wizard | `tabs/support/scheduling.php` (wizard from line 1547; "Notify via WhatsApp" ticked by default, line 1650) |
| Staff page, tiles and badges | `tabs/admin/retailers.php:320-335` (tiles), `:572-632` (cards) |
| Job API, `public.php?page=api&action=…` (a signed-in staff account) | `includes/api/api_scheduling.php`: `scheduling_clear_cache` :8 · `scheduling_jobs` :68 · `scheduling_job_detail` :152 · `scheduling_job_update` :237 · `scheduling_task_update` :300 · `scheduling_complete` :354 · `create_job` :555 · `scheduling_reschedule` :663 · `scheduling_add_comment` :703 · `get_support_staff` :716 · `get_ucrm_users` :734 · `auto_map_ucrm_users` :806 · `set_ucrm_user_id` :885 · `bulk_create_jobs` :898 |
| Engineer list for the wizard | `includes/api/api_support.php:352` (`support_engineers`) |
| Customer search for the wizard | `includes/api/api_retailer.php:356` (`crm_search_customer`, live uCRM `clients?search=`) |
| Message on a job made in uCRM | `webhook.php:2371-2488` (`job.add`) |
| Dispatch cron (switched off) | `cron/job_assignment_notify.php`; commented out in `cron/master.php:166-169` |
| Daily jobs summary | `cron/staff_jobs_summary.php`, scheduled daily at 07:00 (`cron/master.php:243`) |
| Message Log | table `notification_audit_log` (`lib/NotificationService.php:2610`) |
| Failure queue | table `notification_queue` (`lib/NotificationService.php:2156`) |
| WA Events screen | `tabs/engage/failed_queue.php:223-297`, fed by `webhook_log.json` (last 300 events) |

---

## 2. The workflow today

### 2.1 Creating and assigning a job in the plugin — the exact steps

1. Sign in to the plugin dashboard.
2. Open **My Jobs** (menu, top). You need the `scheduling` module, or to be an admin.
3. Press **＋ New Job**. A three-step wizard opens:
   - **Step 1.** A title (typed, or chosen from templates), a date, and a time (09:00 by default).
   - **Step 2.**
     - The duration.
     - A description.
     - The customer: a search against uCRM clients, which shows up to eight. A company client shows a blank name,
       because the name is built from first and last name only.
     - A task checklist.
   - **Step 3.**
     - The engineers, one chip per staff account that is active, has the role `support`, `support_leader`,
       `support_engineer` or `admin`, and has a uCRM user id.
     - **"Notify via WhatsApp"**, ticked by default.
4. Press **Create**. The plugin creates **one uCRM job per engineer chosen**. uCRM holds one assignee per job, so
   three engineers make three separate jobs. Each engineer gets a WhatsApp if their staff row has a phone
   (`api_scheduling.php:555-661`).

**Against what you asked an admin to be able to do:**

| Need | Today | Evidence |
|---|---|---|
| Create a job | **Yes**, from My Jobs → ＋ New Job, or in uCRM's own Scheduling screen | code, trace B1 |
| Select the CRM customer | **Yes**: a live uCRM client search. The job carries `clientId`, and the client's address and GPS | code, trace B1 |
| Select the service or order | **No.** A uCRM job has no service field, and the wizard offers none | code |
| Assign | **Yes in code**, to support-role staff with a uCRM user id. **Not to a retailer or field agent** (§4.5). **On Uganda every stored id names a uCRM user that does not exist** (§11) | code, production |
| Due date / time | **Yes, but the time is wrong:** 09:00 is sent to uCRM as **09:00 UTC, which is 12:00 in Kampala**, while the WhatsApp says 09:00 | trace B1 |
| Priority | **No field** in the wizard or in uCRM's job | code |
| Location | Taken from the customer's uCRM address and GPS. **It cannot be typed** | code |
| Instructions | **Yes**: description plus task checklist | code, trace B1 |
| Reassign | **Not in the plugin.** Possible in uCRM's Scheduling screen, but **nobody is told** (§5.1) | trace A7 |
| See accepted / started / completed | Status 0 → 1 → 2 in uCRM. In the plugin **each person sees only their own jobs**. An admin with a uCRM user id (yours is 1) sees only jobs assigned to that id. uCRM's own Scheduling screen shows everyone's | code (`api_scheduling.php:68-150`) |
| Review history | **No history in the plugin.** uCRM shows the current state and the job's comments. No record of who assigned, reassigned, accepted or when | code |

### 2.2 Creating a job in uCRM directly

An admin can create and assign a job in uCRM → Scheduling. uCRM then calls the plugin's webhook with `job.add`. The
plugin's live endpoint takes **every** event (docs/09).

- The plugin reads the job, the client and the uCRM user.
- It sends a WhatsApp only if **the uCRM user's own record carries a phone** (§5.1 path B).
- For an installation job with a date and a client, it also e-mails the customer "installation scheduled", when that
  e-mail is switched on.
- **Measured: they do not.** Uganda's uCRM user record has no phone field at all (§11), so this path never sends.

### 2.3 Jobs the plugin creates by itself

| Trigger | Assigned to | Code |
|---|---|---|
| A KYC form for a **second site** of an existing customer | the first `support_leader` with a uCRM user id, else a setting | `lib/KycService.php:325`, `:1854` |
| The older WASender auto-reply escalating a customer ("customer needs help") | the staff account whose **name contains a first name written into the code** (a South Sudan engineer), else a setting | `lib/WaAutoReplyService.php:1477`, `:1488` |
| Fibre installation flow | Splynx-based; not the Uganda path | `lib/FiberInstallService.php:261` |

**No job is created automatically** when a new customer signs up, a service is activated or an order is placed.

### 2.4 The rest of the staff system, as far as jobs are concerned

| Screen / action | State for Uganda | Note |
|---|---|---|
| My Jobs (list, detail, Accept, tasks, reschedule, comment, complete) | **working, with defects** | §6 |
| ＋ New Job wizard | **working, with defects** | §2.1 |
| Bulk Dispatch | hidden from the menu (`navigation.php:183`, `if(false && …)`) but still opens at `?tab=bulk_dispatch` for a leader or admin | its jobs also go to uCRM at 09:00 UTC |
| Add Customer (KYC form) | working | the Uganda uCRM client path was fixed in 5.18.28–5.18.30 |
| Collect Payment | working, but outside jobs | debits the staff wallet, posts to uCRM (`api_retailer.php`) |
| Import from CRM (any organization) | **broken for this purpose** | It makes a staff account from a uCRM *client*, not a uCRM user, and sets **neither** link (§4.2). The temporary password is `DishNet` + the client id + `!` (`includes/post/post_admin.php:52`), which is predictable |
| My Install Jobs, NOC, Fiber Pipeline | Splynx-only | not used in Uganda |
| Tickets (local) | local only; they never reach uCRM | reported by the code-reading pass |
| Route Manager, Live Map | not usable in Uganda (Juba-centred map, no location pings) | reported by the code-reading pass |
| Customer Lifecycle | a snapshot with inactive buttons | reported by the code-reading pass |
| WhatsApp → Settings → Message Log | **working** | §7 |
| WhatsApp → WA Events | **misleading** | §7 |
| Staff app (`retailer/index.html`) | **not served**: the route reads `includes/retailer/index.html`, which does not exist | `includes/routes.php:7`, trace F |

### 2.5 The chain you asked to trace, link by link

`CRM customer → service/order/ticket → job → assigned staff account → recipient phone → WhatsApp event → delivery
status → staff acknowledgement → job status → CRM record`

| Link | Today | Evidence |
|---|---|---|
| CRM customer → job | **works.** The job carries the uCRM `clientId`. No duplicate customer is created | trace B1 |
| service / order / ticket → job | **missing.** Nothing links a job to a service, an order or a ticket | code |
| job → assigned staff account | **works**, through `ucrm_user_id`. But that id is overwritten by the South Sudan lists (§4.3) | trace C (Clear Cache) |
| staff account → recipient phone | **partial.** Plugin-created jobs use the staff row's phone. uCRM-created jobs use the uCRM user's phone, and the fallback fails | trace A1, A2 |
| phone → WhatsApp event | **partial.** Digits only, no country code added: a number stored as `07…` goes out wrong | trace A3, B |
| WhatsApp event → delivery status | **missing.** Evolution's delivery and read receipts arrive and are dropped. The Message Log says "sent" or "failed" at hand-over only | code (`evo_webhook.php:115-123`) |
| delivery → staff acknowledgement | **app only.** A WhatsApp reply is filed as a customer conversation and queued for the AI | trace D |
| acknowledgement → job status | **app only.** Accept sets 1, Complete sets 2 | trace C |
| job status → CRM record | **works** for status, comments and tasks. **Photos never reach uCRM**: only their first 200 characters are kept locally | trace C3 |

**What cannot be proved without a live action:** whether a real technician's phone receives the message. The
controlled test in §10.5 settles it. **It waits for your approval.** Whether uCRM user records carry phones is now
measured: they do not (§11).

---

## 3. What "0/5 CRM linked" means

It is **not** about jobs.

- The tile counts staff accounts with **`ftth_crm_client_id`** set (`tabs/admin/retailers.php:326`, via
  `FtthCrmService::getSyncStatus()`).
- That field links a staff account to a uCRM **client** in **organisation 7**: the South Sudan FTTH reseller
  organisation, used there for reseller wallets.
- The orange **"⚠ No CRM link"** badge on every card is the same field (`retailers.php:579`, `:618-623`).
- **How the link is made, and why it is 0 here.** "Add New Staff Account" and a wallet top-up both call
  `FtthCrmService::ensureRetailerClient` (`includes/post/post_admin.php:34`, `:559`, `includes/post/post_sales.php:159`).
  It searches organisation 7 for the staff member's e-mail and, if nothing is found, **asks uCRM to create a
  client in organisation 7 for that staff member** (`lib/FtthCrmService.php:86`, `:113`). On Uganda every attempt has
  evidently failed, so 0 of 5. Were one to succeed, a staff member would appear in uCRM as a customer.
  - **Measured: organisation 7 does not exist on Uganda's uCRM**; it has only organisation 1 (§11).
  - So every attempt fails and nothing is created.

The field that matters for jobs is **`ucrm_user_id`**: the id of a uCRM **staff user**, the person a uCRM job is
assigned to.

- It shows as **"🔗 UCRM #N"**, or **"⚠ Set UCRM ID"** when empty.
- These badges appear only on cards with the role `support`, `support_leader` or `admin` (`retailers.php:625-631`).
  So the accountant's card shows neither.

So on your screenshot:

- **"0/5 CRM linked"** — correct, and irrelevant to jobs.
- **"UCRM #1", "#81", "#1581"** — the job links.
- **"Set UCRM ID"** — the agent account has no job link.

The other badges do not affect jobs:

- **Fiber&SL** — the account's project is "DishNet Fiber & Starlink".
- **AGENT** — `is_employee` is false, a commission agent.
- **PWD** — must change password at next sign-in.

---

## 4. Identity mapping: staff account ↔ uCRM

### 4.1 Two different links, one screen

| Field | Points to | Used by | Can be set on Uganda? |
|---|---|---|---|
| `ftth_crm_client_id` | a uCRM **client** in organisation 7 | the "CRM LINKED" tile and "No CRM link" badge; South Sudan reseller wallets | no |
| `ucrm_user_id` | a uCRM **staff user** | every job function: assign, My Jobs, notifications, completion | yes, by typing a number |

### 4.2 How `ucrm_user_id` is set

- **By hand.** "Set UCRM ID" opens the Edit form, which has a plain number field (`retailers.php:138`). **Nothing
  checks the number against uCRM.** An id that belongs to nobody, or to someone else, is saved as typed.
- **By API, admin only:** `set_ucrm_user_id` (`api_scheduling.php:885`). No screen calls it.
- The "list uCRM users" action (`get_ucrm_users`, `:734`) returns a **hard-coded South Sudan staff list**, not
  Uganda's uCRM users. No screen calls it either.
- **"Import from CRM" sets neither link** (§2.4).

### 4.3 Hard-coded South Sudan lists overwrite it — five places

Each list pairs a South Sudan staff e-mail with a South Sudan uCRM user id: about thirty pairs each. When a staff
account's e-mail is on a list, its `ucrm_user_id` is **replaced** with the list's id.

| Where | When it runs | Code |
|---|---|---|
| deploy list | the first page load after **every plugin version change**, for all staff | `public.php:1565` |
| page-load list | **every dashboard page load**, for the signed-in person | `public.php:1613` |
| My Jobs list | every time My Jobs is opened | `tabs/support/scheduling.php:64` |
| Clear Cache list | the **🗑 Clear Cache** button in My Jobs. **Any signed-in account may press it**: the handler says "admin only" and checks nothing | `api_scheduling.php:8-65`, trace C |
| auto-map list | an admin-only API action no screen calls | `api_scheduling.php:806-881` |

On your five accounts, checked against the lists (e-mails compared locally, never printed):

- **The admin account** is on all five lists, which force id **1**.
- **Support #1581** is on all five, which force **1581**.
- **The accountant, the agent and support #81** are on none. Their ids are whatever was typed.

So "UCRM #1" and "UCRM #1581" are **South Sudan ids**. Uganda's uCRM is a separate installation with its own users.

**Measured (§11):**

- Neither 1 nor 1581 exists on Uganda's uCRM, and neither do 4 or 81.
- Uganda's uCRM has one user, id 1000, with the admin account's e-mail.

The trace proved the overwrite: a retailer account pressed Clear Cache, and a staff member's id changed from the
sandbox's Uganda id 18 to the list's South Sudan id.

### 4.4 When a staff account is disabled or deleted

**Disabled** (Active switched off):

- **Dashboard pages log them out** at the next page load after at most 5 minutes (`lib/RetailerAuth.php:132-165`).
- **Their app token stops working** at once (`RetailerAuth::findByToken`).
- **An already-open page keeps working.** API calls from it are served from the session's cached copy, which is not
  re-checked (`includes/api_handlers.php`, the session fallback). So they can still create, close or complete jobs
  until they load another page.
- **The engineer picker drops them.** They no longer appear in New Job.
- **Their uCRM jobs stay assigned** in uCRM.
- **A job made in uCRM for their uCRM user is still messaged** if that uCRM record has a phone.

**Deleted:**

- The row is removed outright (`includes/post/post_sync.php:777-795`), with **no CSRF token check**.
- Their uCRM jobs stay assigned to the uCRM user.
- Local records that name their id stay behind: completions, the invoice queue, wallet and ledger.
- **New accounts take the highest id + 1** (`SqliteStore::appendWithId`). Deleting the newest account and then
  adding one makes the new person inherit the old one's records.

### 4.5 Retailers and field agents cannot hold jobs

A uCRM job can be assigned only to a uCRM staff user. Retailers and field agents are plugin accounts, not uCRM users,
and the wizard offers only support roles. For a retailer or agent to be assigned a payment collection or a
delivery, they would need their own uCRM user (§9.1).

---

## 5. The WhatsApp notification pipeline

### 5.1 Five paths, one of them switched off

| Path | Trigger | Automatic? | Recipient number from | Channel | State on Uganda |
|---|---|---|---|---|---|
| **A** dispatch cron | poll uCRM every 5 min: new, **reassigned**, accepted | automatic | staff row (`ucrm_user_id` → `phone`) | support, as staff class | **switched off** (`cron/master.php:166-169`) |
| **B** uCRM `job.add` webhook | a job created in uCRM, or by the plugin | automatic | **the uCRM user record's phone**; the fallback to the staff row fails | support | **never sends**: Uganda's uCRM user records have no phone field (measured, §11) |
| **C** My Jobs → ＋ New Job | an admin or staff member presses Create | automatic when "Notify via WhatsApp" is ticked (the default) | staff row `phone` | support | **works** (trace B1) |
| **D** Bulk Dispatch | a leader or admin sends a batch | automatic | staff row `phone` | support | hidden, still reachable |
| **E** Splynx NOC assignment | Splynx | — | — | — | not Uganda |

**Path A was fixed but never switched on.**

- On 12 September commit `132de6d` moved it onto Evolution, on the support number.
- Its line in `cron/master.php` stayed commented out, with the note *"UCRM sends its own job assignment WhatsApp
  natively"*.
- **uCRM has no WhatsApp channel of its own.** The message that comment means is path B, this plugin's own `job.add`
  handler.
- Path A is the only one that notices a **reassignment** or an **acceptance**. B handles `job.add` only; a uCRM
  "edit" is logged "no action configured" (trace A7).

**Path A must not simply be switched back on.**

- On its first run it would message every open job in the next 30 days at once: its memory
  `job_assignments_seen` is empty (trace E2: 8 messages).
- For a job whose uCRM record carries a client object with a phone, it would also WhatsApp **the customer**,
  "installation scheduled", whatever the job is (`cron/job_assignment_notify.php`, `installationScheduled`).

**The broken fallback in B** (`webhook.php:2418`):

- It runs `SELECT phone FROM retailers WHERE email = ?`. The `retailers` table holds one JSON document per row and
  has no `phone` column.
- The query fails with *"no such column: phone"*, the error is swallowed, and the log says "No phone found", even
  though the staff row has a number (trace A2).

**B and C together send the same person two messages for one job.** Creating a job in My Jobs sends C's message.
uCRM then fires `job.add` for that job, and B sends its own, differently worded one (trace B1). Neither checks for
the other. B also sends again every time uCRM redelivers the event (trace A6).

### 5.2 Transport

- **Evolution API** on linked-device (Baileys, QR-paired) WhatsApp numbers. Instances are named per channel:
  `evo_instance_support`, `_account`, `_sales` (`EvolutionApiService` constructor).
  - Job messages leave on the **support** number (`NotificationService::sendRaw` → `sendVia('support')`).
  - Completions for accountants leave on the **account** number.
  - `wa_force_accounts` moves the support traffic to the account number when the support number is blocked.
- **No Meta templates and no 24-hour window.** These are not Cloud API numbers, so any text can go at any time. The
  limits are WhatsApp's own anti-spam rules for linked devices.
- **The older WASender transport** is used only when no Evolution instance exists for the channel. South Sudan uses
  it.
- **Dry-run mode** (`dry_run_mode`) writes messages to `dry_run_notification_log.json` instead of sending.

### 5.3 The recipient number, and Uganda / South Sudan

- **The staff phone is free text**, typed on the staff form, and never checked.
- **Sending strips it to digits** (`NotificationService.php:2011`, `EvolutionApiService` `normalisePhone`). **No
  country code is added.**
  - `+256 7…` becomes `2567…` and works.
  - `0 7…` becomes `07…`, which is not a WhatsApp number. The send fails and goes to the failure queue (trace A3, B:
    `0700000113`, `0700000112`).
  - `+211 9…`, a South Sudan number, becomes `2119…`, and works from the Uganda number if that phone has WhatsApp.
- **A tenant-aware normaliser already exists for customers:** `PhoneNumber::international($raw, TenantProfile)`
  (`lib/PhoneNumber.php:35`), with Uganda's +256 and South Sudan's +211. Staff numbers do not use it.
- **The admin alert number** (`whatsapp_admin_phone`) is also digits only.
- **Every message sent to a staff member is also filed in the WhatsApp inbox** as an outbound message in a
  conversation with that number (`NotificationService.php:2111-2150`). Staff numbers therefore sit in the inbox
  beside customers, which is also where their replies land (§6.3).

### 5.4 What the technician receives (from the trace; made-up people)

Path C, from My Jobs:

```
Hi *Tech*,

🔧 *New Job Assigned to You*

*Payment collection*
Customer: Test Client
Location: Plot 1 Test Road Kampala
Date: *Tue 06 Oct* at 09:00          ← uCRM holds 12:00 Kampala time for this job
Tasks: Collect payment, Issue receipt

Assigned by: Sandbox Accountant
*My Jobs tab → Job #950*

— DishNET Africa Team
```

Path B, from uCRM's `job.add`:

```
🔧 *New Job Assigned*
Hi Tech One,
A new job has been assigned to you:
📋 *Router replacement*
👤 Customer: Test Client
📍 Address: Plot 1 Test Road, Kampala
📅 Date: Mon, Oct 5 2026
⏰ Time: 9:00 AM - 11:00 AM
🔗 View in CRM: <the CRM home page, not the job>
— DishNet Support
```

- The day and hour in path B are printed in the plugin's timezone.
  - Uganda gets Kampala: from the `timezone` setting, or from the Uganda tenant profile.
  - An install with neither runs on Africa/Juba, one hour behind. It prints a date-only job on the **previous day**
    (trace E3: *Sun, Oct 4 2026 8:00 AM* instead of *Mon, Oct 5 2026 9:00 AM*).
  - Measured: Uganda runs on Africa/Kampala (§11).
- Neither message has a button or a link to the job itself.

### 5.5 Opt-out

- Job messages from paths B, C and D go as **transactional** messages. A staff number that once sent "STOP" to the
  support number is recorded as a **proactive** opt-out (`evo_webhook.php`, step 8b), which does not block
  transactional messages. An opt-out recorded with scope **all** would block them
  (`lib/ContactOptOut.php:88-105`).
- Path A used the **staff** class, which no customer opt-out can silence.
- **A suppression is logged without the number or the text.** `optedOut()` hands `writeLog()` keys it does not read
  (`NotificationService.php:1970-1981`), so the row names only the event, e.g. `job_assigned_suppressed_optout`.

---

## 6. Acknowledgement and completion

### 6.1 What a technician can do, and where

Only in the plugin dashboard's **My Jobs**. The staff app is not served (§2.4), and the support PWA is an admin-only
WhatsApp inbox with no job screens.

| Action | How | What happens | Evidence |
|---|---|---|---|
| Accept | the **✔ Accept Job** button — **shown only for status 0** | status → 1; WhatsApp "Job Accepted" to the technician and to every active `support_leader` | trace C2 |
| Jobs made in My Jobs | created with **status 1** | **the Accept button never appears for them** | trace B1 |
| Tick a task | a checkbox | the uCRM task is closed; WhatsApp "next task" to **whoever ticked** | code |
| Reschedule | a date plus a reason | new date in uCRM plus a comment; WhatsApp to **whoever pressed it**, not the assignee | code (`:663-700`) |
| Comment | text | a uCRM job comment | code |
| Complete | a comment, GPS and up to five photos, once every task is ticked | see below | trace C3 |

**Complete does all of this** (`:354-553`):

- the uCRM job is set to status 2 and the comment added;
- **photos are kept locally as their first 200 characters and never sent to uCRM**;
- WhatsApp "Job Completed" to **whoever pressed Complete**;
- an admin alert;
- and, for a title containing *install, fiber, fibre, starlink, ftth* or *lte activation*:
  - an **Invoice Queue** entry;
  - a WhatsApp to **every active accountant** on the account number;
  - an **automatic stock deduction** from that customer's KYC hardware cart.

### 6.2 Missing

- **Reject**, or ask for reassignment.
- **"On my way" / arrival / check-in.** The check-in code (`schCheckIn`, the `install_checkin` action) exists, but no
  button in My Jobs calls it.
- **Failed visit** (customer absent, no access, missing equipment), as its own outcome.
- **Photo upload** to the uCRM job.
- **Any acknowledgement by WhatsApp.**

### 6.3 A technician who answers on WhatsApp

The reply goes to `evo_webhook.php`, the same as any customer message:

- It is stored as a **customer conversation** on the support channel.
- It is queued for the **AI assistant** (`ai.reply`). Nothing checks whether the number belongs to staff (trace D).
- The job in uCRM does not change.

Measured (§11): six staff conversations are filed as customer ones, and the AI has been queued **109** times for a
staff number.

### 6.4 Who can act on a job

**Nobody's permission is checked beyond being signed in.** Proved with a retailer account and an accountant account
in the trace:

| Action | Check today | Trace |
|---|---|---|
| `create_job` (assign anyone) | none — any signed-in account | B (an accountant and a retailer both created jobs) |
| `scheduling_job_detail` (job, address, GPS, customer contact, open invoices) | none — "ownership" is an empty `if` block | C1 |
| `scheduling_job_update` (change status, e.g. close) | none — empty `if` block | C (a retailer closed a job) |
| `scheduling_complete` (close, invoice queue, stock deduction) | none — empty `if` block | C3 (an accountant completed a technician's job) |
| `scheduling_reschedule`, `scheduling_add_comment` | none | code |
| `scheduling_clear_cache` (rewrites staff mappings) | none, though labelled admin-only | C |
| `support_engineers` (engineer names and **phones**) | none | code |
| `bulk_create_jobs` | leader or admin ✓ | code |
| `set_ucrm_user_id`, `auto_map_ucrm_users` | admin ✓ | code |

---

## 7. Failure handling and visibility

| What | Where an admin sees it | Truthful? |
|---|---|---|
| Every send and its result | **WhatsApp → Settings → Message Log** (`notification_audit_log`): event, number, first 70 characters, success, HTTP code, error | **yes** at hand-over to Evolution. **A success is not a delivery**: receipts are not kept |
| A failed send | **Failed queue** (`notification_queue`). **Retried only by hand.** The menu badge shows the count | yes |
| Job events from uCRM | **WA Events** (`webhook_log.json`, last 300 events of every kind) | **no.** A failed job message is logged "notification sent" (`webhook.php:2444`, written whatever the send returned), and WA Events files it as **✅ Delivered** (`failed_queue.php:94-107`). Trace A5: Evolution refused, the Message Log said failed, the webhook log said sent |
| WA Events menu badge | the count of today's events | **never shows:** it reads `timestamp`/`created_at`, but the log writes `received_at` (`navigation.php:122-135`) |
| `create_job`'s answer | `notified: true` | means **attempted**, not sent (trace B1) |
| The daily 07:00 jobs summary | the plugin log | **fails every day:** `new CrmApiClient($config)` passes an array where the constructor wants a URL string (`cron/staff_jobs_summary.php:47` vs `lib/CrmApiClient.php:36`), a TypeError caught by `master.php` (trace E1). Mended, it would send each person everyone's jobs: it filters by `assigneeId`, which uCRM ignores (`:91`) |
| Delivery / read receipts | nowhere | Evolution's `messages.update` events are accepted and dropped |
| A suppressed (opted-out) send | Message Log | row has no number or text (§5.5) |

---

## 8. Feature matrix

| Feature | State |
|---|---|
| Create a uCRM job from the plugin, for a uCRM customer, with tasks | **WORKING** |
| Assign to support-role staff with a uCRM user id | **WORKING in code; BROKEN on Uganda**: no stored id is a real uCRM user (§11) |
| Assign to a retailer or field agent | **NOT IMPLEMENTED** |
| Choose service / order / ticket on the job | **NOT IMPLEMENTED** |
| Priority | **NOT IMPLEMENTED** |
| Typed location | **NOT IMPLEMENTED** (customer address only) |
| Due date/time | **BROKEN**: 3 hours late in uCRM |
| Reassign from the plugin | **NOT IMPLEMENTED** |
| Admin board of everyone's jobs in the plugin | **NOT IMPLEMENTED** (uCRM's screen has one) |
| Job history / audit trail | **NOT IMPLEMENTED** |
| Staff ↔ uCRM user mapping | **BROKEN**: overwritten by South Sudan lists; no picker; no check |
| "CRM LINKED" tile | **WORKING as built**, but it means organisation 7, not jobs |
| WhatsApp on a job made in My Jobs | **WORKING in the sandbox** (numbers in international form). On Uganda no job has ever been made (§11) |
| WhatsApp on a job made in uCRM | **BROKEN**: uCRM user records have no phone field (measured, §11); fallback broken |
| WhatsApp on reassignment / reschedule in uCRM / cancel | **NOT IMPLEMENTED** (the only path that did it is off) |
| One message per job (no duplicates) | **BROKEN**: B + C both send; B has no dedupe |
| Staff number normalisation (+256 / +211) | **PARTIAL**: international form works; national form fails |
| Delivery receipts | **NOT IMPLEMENTED** |
| Failure queue with manual retry | **WORKING** |
| Honest failure display (webhook log, WA Events) | **BROKEN** |
| Accept in the app | **PARTIAL**: not for jobs made in My Jobs (status 1) |
| Tasks, reschedule, comment in the app | **WORKING** (reschedule messages the wrong person) |
| Complete in the app | **PARTIAL**: photos lost; confirmation to the wrong person |
| Reject / arrival / failed visit | **NOT IMPLEMENTED** |
| Acknowledge or complete by WhatsApp | **NOT IMPLEMENTED**; replies go to the AI |
| Permission checks on job actions | **BROKEN** |
| Staff app page (`retailer/index.html`) | **BROKEN** (not served) |
| Daily jobs summary | **BROKEN** (TypeError every day) |

---

## 9. What to build: the workflow, then the smallest set of changes

### 9.1 The workflow to aim for

**The uCRM customer is the source of truth.** A job always starts from an existing uCRM client, and from the service
it concerns where there is one. No customer record is ever created to hold a job.

1. **Create** — in My Jobs, or in uCRM's Scheduling screen; either works.
   - Choose the uCRM client, then the job type, then the service if it concerns one.
   - Set the date and time in Kampala time, the assignee and the instructions.
2. **Notify** — one WhatsApp to the assignee's number, on the support number, per job and per assignee. A
   reassignment or a new date sends one new message.
3. **Acknowledge** — the technician taps Accept in My Jobs, or replies **1** on WhatsApp if you choose §9.2 J9.
4. **Work** — tasks, "on my way", arrival, photos, and complete or failed visit, all in My Jobs.
5. **See the result** — status and comments in uCRM, an admin list of every job, and the Message Log showing each
   message's real outcome.

**Job types.** The title starts with the type, so reports and rules can read it without a new field:

| Type | Assign to | Default tasks | On completion |
|---|---|---|---|
| Starlink installation | support | mount, align, power, speed test, customer shown the app | invoice queue + stock deduction (as today) |
| Fibre installation | support | (Splynx-era flow is not Uganda's) | invoice queue |
| Router replacement | support | swap, configure, test, old unit returned | stock movement (new; later) |
| Troubleshooting | support | diagnose, fix or escalate | — |
| Site survey | support | line of sight, mounting point, power, photos | — |
| Equipment delivery | support or a field agent **with a uCRM user** | hand over, signature | — |
| Payment collection | a field agent **with a uCRM user** | collect, receipt | the existing Collect Payment, from the job |
| Follow-up | support or sales | call or visit, note | — |

- **Retailers stay outside jobs.** They keep Collect Payment and their orders.
- **An agent who does jobs** gets a uCRM user of their own, created in uCRM by you, and is then mapped as in J2.

### 9.2 The changes

Each can be reviewed, tested and deployed on its own. **"Required"** means the workflow in §9.1 does not work
without it. Everything is **Uganda-gated or switched off by default**, so the South Sudan install behaves exactly as
it does now.

**Required:**

| # | Change | Why | Main files |
|---|---|---|---|
| **J1** | **Stop South Sudan code acting on Uganda staff.** The five lists stop overwriting `ucrm_user_id` when the tenant profile is not South Sudan. Clear Cache gets an admin check and clears only the cache. Adding a staff member or topping up a wallet stops asking uCRM to create an organisation-7 client for staff there | Nothing else holds while ids are rewritten on every page load; and staff must never become uCRM customers | `public.php:1550-1651`, `tabs/support/scheduling.php:60-105`, `includes/api/api_scheduling.php:8-65, 806-881`, `lib/FtthCrmService.php` callers |
| **J2** | **Map staff to Uganda's uCRM users properly.** "Set UCRM ID" becomes a picker listing uCRM's own staff users (`GET users/admins`), marks the one with the same e-mail, and refuses an id uCRM does not know | Today it is a free number field, and the list action returns South Sudan's people | `tabs/admin/retailers.php`, `api_scheduling.php:734` |
| **J3** | **Staff phone numbers in international form.** Normalise with `PhoneNumber::international` and the tenant's country code on save, and when sending to staff. Show a warning on a card whose number cannot be made international | `07…` numbers fail silently today | staff save handler, `NotificationService` staff sends |
| **J4** | **One message per job, from one place, including reassignment.** `job.add` and `job.edit` from uCRM become the single trigger for jobs made anywhere. The number comes from the staff row found by `ucrm_user_id`: this replaces the broken e-mail fallback, and the uCRM user record is no longer needed. The plugin's own Create stops sending a second message. A small record of (job, assignee, date) makes a repeat event send nothing, and a new assignee or date send one message. Unassignment and cancellation are logged, not messaged | Duplicates today, and no reassignment notice | `webhook.php:2371-2488`, `api_scheduling.php:555-661`, one new table |
| **J5** | **Kampala time to uCRM.** Send the date and time with the install's own offset, not `Z`, in Create and Bulk Dispatch | Jobs sit 3 hours late in uCRM | `api_scheduling.php:602, 948` |
| **J6** | **Permission checks on job actions.** Create: admin, `support_leader`, `support`. Detail, update, reschedule, comment, complete: the assignee, a leader or an admin. The engineer list keeps its names, and phones only for admins | Today any signed-in account can read, close or complete any job | `api_scheduling.php`, `api_support.php:352` |
| **J7** | **Say what really happened.** The webhook logs sent or failed from the send's result. WA Events classifies by that result. The menu badge reads `received_at` | A failed message shows "Delivered" today | `webhook.php:2444`, `tabs/engage/failed_queue.php:94`, `includes/navigation.php:122` |
| **J8** | **A colleague's WhatsApp reply is not answered by the AI.** A message on the support number from a number that belongs to an active staff account is stored, flagged "staff", shown in the inbox, and not queued for the AI | Today the AI answers technicians as customers | `evo_webhook.php` (before step 9) |

**Optional**, each independent:

| # | Change | Note |
|---|---|---|
| **J9** | **WhatsApp replies as commands.** After J8, a staff reply to a job message, e.g. `1` accept, `2` on my way, `3` arrived, `4` done, `5` could not complete + reason, updates that job's status or comments in uCRM and confirms by WhatsApp | Needs J4's record, to know which job a reply is about |
| **J10** | **Accept for jobs made in My Jobs** (create them at status 0), plus **Reject**, **On my way**, **Arrived** and **Failed visit** buttons in My Jobs, each as a status change and a uCRM comment | Small UI change |
| **J11** | **Completion photos to the uCRM job** as attachments, instead of 200 characters locally | Check uCRM's attachment endpoint first |
| **J12** | **An admin "All jobs" list** in the plugin: all assignees, filters for status, type and date | uCRM's own Scheduling screen already covers this |
| **J13** | **Service on the job**: choose one of the client's uCRM services in the wizard, recorded in the description as `Service #N` | uCRM jobs have no service field |
| **J14** | **Mend the daily summary**: the right client constructor, and a client-side filter by assignee | Otherwise leave it switched off |
| **J15** | **Confirmations to the right person**: Reschedule and Complete message the assignee, not whoever pressed the button | Small |
| **J16** | **Retire path A** (the switched-off cron) once J4 covers reassignment. **Never switch it back on as it stands**: burst on first run, possible customer messages | Deletion, not a switch |

**Security points found on the way.** Not needed for jobs, but recommended:

- **App sign-in** (`public.php?page=api&action=login`) has **no attempt limit**, and returns the stored token instead
  of issuing a new one (`includes/api/api_public.php:6-19`).
- **Import from CRM** sets a **predictable temporary password** (§2.4). It should generate a random one and require
  a change at first sign-in.
- **Deleting a staff account** has **no CSRF check** and **reuses ids** (§4.4).
- **`public.php:578-590` carries default Splynx address, key and secret values** in the source (values not repeated
  here). **Rotate that key and secret with Splynx**, and remove the defaults. Uganda does not use Splynx, but the
  values are in the repository.

**Order:** J1 → J2 → J3 → J5 → J4 → J6 → J7 → J8. Then the optional ones, in any order.

J1 comes first because every later check depends on the ids staying put. J4 depends on J2 and J3. J9 depends on J4
and J8.

---

## 10. Tests, security, deploy and rollback

### 10.1 Tests (per change)

- **Each change gets a test that runs the real handler** against a fake uCRM and the fake Evolution server, as
  `scripts/harness/jobs-trace/trace.php` does now. That trace becomes the baseline: each fixed defect flips its line
  from "observed" to "prevented".
- **Weakened copies** of each fix must be caught, as in every recent release.
- **South Sudan unchanged**: the same scenarios with the South Sudan tenant profile produce byte-identical messages
  and mappings.
- Specific tests:
  - **J1**: a Uganda page load or deploy leaves the id alone; South Sudan still maps.
  - **J4**: one `job.add` gives one message; a repeat gives none; a new assignee gives one; Create plus `job.add` give
    one.
  - **J6**: each action refused for a retailer, and allowed for the assignee.
  - **J8**: a staff number is not queued, a customer number still is.

### 10.2 Security properties to keep

- No customer is messaged by any new path. Job messages go to staff numbers only.
- No phone, e-mail, token or message text goes into a log beyond what the Message Log already holds.
- Every job action is checked on the server, not only by hiding a button.
- Nothing trusts a number typed into a request as a staff identity. The sender's number is matched against active
  staff rows on the server.

### 10.3 Deploy and rollback

- The same pattern as 5.18.49:
  - one release per approved group of changes;
  - a deploy script pinned to the reviewed commit;
  - a backup before copying;
  - read-only checks after;
  - a log file sent back.
- **The deploy never changes a setting.** Where a change needs a switch, you set it with your own command, as with
  the price fact in docs/42 §10.1.
- **Rollback** is the previous plugin release from that backup.
  - J4 adds one table. An older release ignores it.
  - Nothing is migrated in uCRM.

### 10.4 The read-only facts command

**It ran on 27 September at 15:54 UTC; the results are in §11.** It answers the "production — to measure" points
above:

- whether uCRM's staff users have a phone field;
- whether ids 1, 81 and 1581 are real Uganda uCRM users with the same e-mail as the staff account;
- whether organisation 7 exists on Uganda's uCRM (§3);
- how many job messages were sent and how many failed;
- whether the daily summary fails;
- how many staff numbers the inbox holds as customers.

**It changes nothing and sends nothing.**

- It reads a copy of the plugin's store, made inside the container and removed afterwards.
- It asks uCRM with GET only, and Evolution only for its connection state.
- It prints no name, e-mail, phone number, job title, token or key.
- It was rehearsed against a fake install full of canary values: **334/334 on two runs** since the §11.4
  correction (277/277 before it). Eight deliberately weakened copies are each caught.

Run as root on the server:

```
cd /opt/dishnet && git pull origin claude/study-this-jhe2eg && mkdir -p /root/dnb-jobs && bash scripts/dnb-jobs-facts.sh 2>&1 | tee /root/dnb-jobs/facts-$(date -u +%Y%m%dT%H%M%SZ).log
```

Then send back **the log file** from `/root/dnb-jobs/`, not a copy of the terminal. The `git pull` updates only this
checkout of the repository; the installed plugin is not touched.

### 10.5 The controlled live test (waits for your approval)

This proves, on the real system, the links the trace cannot: a real phone receiving the message, and uCRM firing
`job.add` here. It is designed so that **no customer is involved**:

- **No customer on the job.** No client means no customer e-mail and no customer name in any message.
- **A neutral title**, e.g. `TEST internal — please ignore`. Without install, fiber, starlink, ftth or "lte
  activation", completing it creates **no invoice-queue entry, no accountant message and no stock deduction**.
- **Assigned to you**, on your own number. Two things from §10.4 must hold first:
  - your staff row's number is in international form;
  - your staff row's uCRM user id is **you** in uCRM ("same e-mail as this row: yes"). Otherwise uCRM would assign
    the test job to whoever holds that id, and path B could message them.
- **Measured on 27 September (§11): neither holds yet.**
  - Your staff row has no number.
  - Its uCRM id is 1, which is no uCRM user; you are uCRM user 1000.
  - So this test waits for J1. Without it, the South Sudan lists put 1 back on the next page load. After J1, your
    row can be linked to 1000 and given a number.

Steps:

1. Run §10.4 first.
2. **In My Jobs, create the test job** with no customer and "Notify via WhatsApp" ticked.
   - Expected: one WhatsApp from path C on your phone, a Message Log row `ops_scheduling_job_assigned`.
   - Then a **second** message from path B, if uCRM's user record for you has a phone. **That second message is the
     duplicate this audit reports.**
3. **In uCRM, change the test job's time.** Expected: **no message**, the gap in §5.1.
4. **In My Jobs, add a comment, then Complete** (no photo). Expected:
   - status 2 in uCRM;
   - "Job Completed" to you;
   - one admin alert, to the admin alert number;
   - nothing to accountants.
5. **Delete the test job in uCRM.**
6. Send back the Message Log rows for those minutes: event, success and time only.

Nothing here is run until you say so.

---

## 11. Production facts, measured 27 September 15:54 UTC

**How they were measured.** The operator ran `scripts/dnb-jobs-facts.sh` on the server:

- installed plugin 5.18.49 (`e076632`), repository checkout `9595d4a`;
- read-only: it changed nothing and sent nothing, and the log was sent back.

Staff appear as S1–S5, the same five accounts as on the screenshot:

| | Role | Badges on the screenshot |
|---|---|---|
| S1 | admin (the operator) | UCRM #1 |
| S2 | accountant | — |
| S3 | support | AGENT, Set UCRM ID |
| S4 | support | UCRM #81 |
| S5 | support | UCRM #1581, PWD |

### 11.1 What was measured

| Fact | Measured |
|---|---|
| Timezone | Africa/Kampala, UTC+03:00 |
| Tenant profile | **uganda**. The first version of the command printed "not set", which described the `tenant_profile` setting, not the profile. With no such setting, the currency UGX selects uganda (`TenantProfile::resolveId`). The command now prints the profile and why (§11.4) |
| uCRM staff users (`GET users/admins`) | **One**: id **1000**, with the same e-mail as S1. Its record has the fields id, unmsId, email, firstName, lastName, username, avatarColor and isActive. **There is no phone field** |
| The plugin's stored uCRM ids | S1 → 1, S3 → 4, S4 → 81, S5 → 1581; S2 has none. **None of the four is a uCRM user**: `users/admins/{id}` and `users/{id}` both answer 404 |
| The South Sudan lists | They force S1 → 1 and S5 → 1581, in all five places. Four lists hold 29 pairs; auto-map holds 30 |
| S3's id | **4, typed since the screenshot**, which still showed "Set UCRM ID". No list forces it, and uCRM has no user 4. This is the unchecked field of §4.2, live |
| Phones | S1 none; S2 +211; S3, S4 and S5 +256. All are in international form |
| App tokens | All five accounts hold one, 1–32 days old |
| Organisations | Only id 1. **Organisation 7 does not exist**, so the staff → organisation-7 client creation of §3 fails every time and creates nothing |
| uCRM scheduling jobs | **0** |
| Job messages ever sent | **0** in the Message Log. The log itself holds 367 rows since 12 September: the positive control |
| Failure queue | No job message |
| Webhook log | 300 events since 24 September, **none of them `job.add`, `job.edit` or `job.delete`** |
| `webhooks/endpoints` | 404 through the plugin's v2.1 client. This is the same 404 that `tools/webhook_setup.php` and `tools/quote_email_doctor.php` note; it says nothing about the endpoint itself |
| WhatsApp | Evolution runs on the account, sales and support numbers, and all three are connected. Support is not forced to accounts. Dry run is off, and there are no WASender keys |
| Admin alert number | **Not set**, so `sendAdmin()` alerts (on completion, for example) go nowhere |
| Dispatch cron (path A) | Not scheduled; its memory holds 0 entries |
| Daily jobs summary | Last run 07:00:38 local time, taking 9 ms. That fits the TypeError of §7, which ends the run at once. **The plugin log was not found**, so the error text itself is not confirmed |
| My Jobs cache | 0 jobs |
| Staff numbers in the WhatsApp inbox | **6 conversations** filed as customer ones: S2 on support; S3 on accounts, sales and support; S4 on accounts and support |
| AI replies queued for staff numbers | **109**, the last one on 27 September |
| Local job records | 0 completions, 0 invoice-queue entries, 0 signatures, 0 site surveys |

### 11.2 What this changes

1. **Nobody can be assigned a uCRM job correctly today.**
   - My Jobs → ＋ New Job offers S1, S3, S4 and S5.
   - Every one of their stored ids names a uCRM user that does not exist.
   - What uCRM does with such a job (refuse it, or hold it for no one) was not tested, because no job was created.
2. **The technicians have no uCRM user at all.**
   - Only the operator exists in uCRM, as user 1000.
   - S3, S4 and S5 each need a uCRM user before any job can be theirs. That is configuration, not code (§11.3).
3. **Path B can never send.**
   - uCRM user records have no phone field, and the fallback is broken (§5.1).
   - So J4's rule, that the number comes from the staff account, is not one option among several. It is the only way
     a job made in uCRM can reach anyone.
   - The uCRM users check at 16:37 UTC adds: path B's lookup address, `users/{id}`, answers 404 even for the real
     user 1000 (docs/44 §13.1).
4. **The job system has never run on Uganda.**
   - Zero jobs, zero job messages and zero job webhooks.
   - Nothing has to be migrated or cleaned up for J1–J8.
   - The duplicate-message and reassignment findings come from the code and the sandbox trace, not from production
     history.
5. **J8 matters now.** The AI has already been queued to answer colleagues 109 times.
6. **The admin alert number is unset,** so the "Job Completed" admin alert of §6.1 reaches no one. Setting it is
   your configuration and is not part of J1–J8.
7. **The §10.5 live test cannot run yet.**
   - S1 has no number.
   - S1's stored id (1) is not the operator in uCRM, who is user 1000.
   - It needs J1 deployed first, because otherwise the South Sudan lists put 1 back on the next page load. Then S1
     can be linked to 1000 and given a number.

### 11.3 A step that is yours, not code

For each person who will do jobs (installations, repairs, surveys, deliveries), create a user in UISP/uCRM with the
same e-mail as their staff account. J2's picker can then propose the link, and you confirm it.

**Retailers stay retailers**: no uCRM user and no jobs, as you decided on 27 September.

### 11.4 The facts command, corrected

- **The tenant-profile line now prints the profile the plugin resolves, and why**, e.g. "uganda (selected by the
  currency UGX; no tenant_profile setting)". The timezone line says where the zone came from.
- **Rehearsed: 334 of 334 checks on two runs.** A new scenario has production's shape (no setting, currency UGX).
  A new weakened copy, which prints the raw setting again, is caught; eight weakened copies are caught in all.
- **Re-running it is not needed.** The uCRM users check in docs/44 §13 prints the same two lines.

---

## Direct answers

**1. Can I assign a job to an existing technician or retailer today?**

- **A technician: not correctly, today (measured, §11).**
  - The screens exist: My Jobs → ＋ New Job, and uCRM's Scheduling screen.
  - But every uCRM id the plugin holds names a uCRM user that does not exist.
  - The technicians have no uCRM user at all. Only you exist in uCRM (id 1000), and your account is linked to 1.
  - The time would also land 3 hours late in uCRM (§2.1).
- **A retailer: no.** A retailer is not a uCRM user, and the wizard offers support roles only (§4.5).

**2. Will that person automatically receive a WhatsApp message?**

- **From My Jobs: only once a technician is correctly linked** (see 1). The plugin sends when uCRM accepts the job.
  Whether uCRM accepts a job for a user that does not exist was not tested: no job was created.
- **From uCRM's own screen: never.** Uganda's uCRM user records have no phone field (measured), and the fallback to
  the staff row is broken.
- **Reassignment, a new date in uCRM, or cancellation: no message at all.**

**3. Can they acknowledge and complete it through WhatsApp or the app?**

- **In the plugin dashboard's My Jobs, mostly:**
  - **Accept**, shown only for status 0: jobs made in uCRM normally start there, jobs made in My Jobs never do;
  - **tasks**, **reschedule**, **comment** and **complete**.
  - Photos are lost; there is no reject, arrival or failed-visit outcome.
- **Not through WhatsApp.** A reply is treated as a customer message and handed to the AI.
- **The separate staff app page is not served.**

**4. If not, what exact components are missing?**

- **J1** — stop the South Sudan lists overwriting `ucrm_user_id` on Uganda.
- **J2** — a staff ↔ Uganda-uCRM-user picker that checks the id.
- **J3** — staff numbers in international form.
- **J4** — one notification path for new jobs and reassignments, with the number taken from the staff row and one
  message per event.
- **J5** — Kampala time sent to uCRM.
- **J6** — permission checks on every job action.
- **J7** — honest sent/failed display.
- **J8** — staff replies kept away from the AI.
- For acknowledging **by WhatsApp**: J9 (reply commands), and J10 for Accept, Reject, Arrival and Failed-visit
  buttons in the app.
- **Configuration, not code:** a uCRM user for each person who will do jobs, created by you in UISP/uCRM with the
  same e-mail as their staff account (§11.3).

---

**J1–J8 are approved for planning only (27 September).** Their implementation specification is
`docs/44-j1-j8-implementation-specification-2026-09-27.md`. Nothing will be coded until you approve it; J9–J16 are
out of scope for now.
