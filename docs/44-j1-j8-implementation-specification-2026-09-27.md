# 44 — J1–J8: staff identity, job notifications and permissions — implementation specification

27 September 2026. **A specification, not an implementation.** Nothing here has been coded, deployed, migrated or
configured. On 27 September you approved J1–J8 for **planning** (docs/43 §9.2), and asked for this document before
any code. **Coding waits for your approval of it.** J9–J16 are out of scope.

**Sources.**

- docs/43, the read-only audit, and its §11: the production facts measured 27 September at 15:54 UTC.
- The plugin as installed: **5.18.49, commit `e076632`**. Every file and line below is from that commit. This
  branch has not changed a plugin file since (`git diff e076632 HEAD -- dishnet-hybrid-sudan` is empty).
- The read-only uCRM users check in §13, run by the operator on 27 September at 16:37 UTC (§13.1).

**The goal, in your words.** A reliable Uganda workflow where an authorised manager can assign a real uCRM job to
the correct technician, the technician receives exactly one WhatsApp, and the job can then be safely managed in My
Jobs without affecting customers or the AI:

```
uCRM customer/service → job → correctly mapped Uganda staff member → one WhatsApp
      → staff receives it → staff uses My Jobs → status / comments / photos → uCRM
```

---

## In one paragraph

Eight changes, all behind one Uganda switch that South Sudan never turns on.

- **J1** stops the South Sudan staff lists from touching Uganda.
- **J2** replaces the typed uCRM number with a picker of Uganda's real uCRM users, checked on the server.
- **J3** stores staff numbers as +256 (a +211 number stays +211).
- **J4** routes every job message through one new component. It remembers, per job, who was last told what, so each
  change produces exactly one WhatsApp, whichever path sees it first.
- **J5** sends Kampala time with its offset.
- **J6** checks on the server who may create and act on a job.
- **J7** says "sent" or "failed", never "delivered".
- **J8** keeps a colleague's WhatsApp away from the AI.

There is **one schema change**: two small tables for J4 (§5.4).

Two things are yours, not code: **a uCRM user for each technician** (docs/43 §11.3), and **linking each staff
account to it** with the J2 picker.

---

## 0. Decisions for you

Everything else in this document follows from your instruction. These eight points need your answer. The
recommendation is first.

| # | Question | Recommended | Why |
|---|---|---|---|
| **D1** | One release or two? | **Two.** Release A: J1, J2, J3, J5, J6, J7 (display), J8. Release B: J4, and J7's webhook lines | A sends nothing new. B starts sending only after you have created the uCRM users and linked them (§12) |
| **D2** | South Sudan: byte-identical for all eight? | **Yes.** Every change is Uganda-only | Your rule. J6 and the Clear Cache check fix real holes on South Sudan too; turning them on there is a separate, later decision |
| **D3** | When a job is reassigned away from someone, or deleted in uCRM, tell that person? | **Yes, one short message.** The default in this document follows your wording instead: **recorded, not messaged** | Otherwise a technician who was told "tomorrow 09:00" still goes to the customer |
| **D4** | Keep "Notify via WhatsApp" in My Jobs → ＋ New Job? | **Remove it on Uganda.** Every assignment is announced once | With one notification path the box cannot be honoured reliably: uCRM's `job.add` may be handled before the box is read (§5.7) |
| **D5** | The message wording in §5.6 | **Approve, or edit** | It is what every technician will read |
| **D6** | The "CRM LINKED" tile and "⚠ No CRM link" badges on the Staff page | **Hide them on Uganda** | They count organisation 7, which does not exist on Uganda (measured). They are the "0/5" that looked like a job problem |
| **D7** | May `support_engineer` create jobs like `support`? | **Yes** | It is the third technician role. No Uganda account holds it today |
| **D8** | Set `tenant_profile = uganda` explicitly, with your own settings command? | **Yes, optional** | Uganda is recognised today by its currency, UGX, measured working. An explicit setting makes the switch independent of the currency setting. Deploys never change a setting |

---

## 1. Rules that bind all eight changes

- **R1 — South Sudan is unchanged.**
  - One switch decides: `StaffJobsGate::applies($config, $dataDir)` (new, §2.3).
  - It is true only when the plugin's tenant profile resolves to **uganda**. Any error means **false**, which is South
    Sudan's behaviour.
  - Every changed place keeps today's code, verbatim, in its South Sudan branch.
  - South Sudan golden tests prove that messages, mappings, answers and screens are byte-identical (§11).
- **R2 — uCRM is the source of truth for jobs.** The plugin keeps only what it needs to avoid repeating itself: who
  was last told what, per job (§5.4). It never creates a customer to hold a job.
- **R3 — Never act on a posted body.** Webhook handlers re-read the job from uCRM by id, as every handler does since
  5.18.37. A job's "before" state comes from the plugin's own record, never from the webhook.
- **R4 — No phone number, e-mail, token or message text** goes into a log beyond what the Message Log already holds.
- **R5 — Deploys change no setting.** Every configuration step is yours, and is listed in §12.
- **R6 — Every rule is enforced on the server,** not by hiding a button.
- **R7 — Every change gets a test that runs the real handler** against a fake uCRM and the fake WhatsApp server. A
  weakened copy of each change must be caught.

---

## 2. J1 — Uganda staff identity

### 2.1 Today

- **Five hard-coded South Sudan lists** (e-mail → uCRM user id) rewrite `ucrm_user_id`:
  - `public.php:1550-1609` on every version change, for every staff row;
  - `public.php:1611-1651` on every page load, for the signed-in person;
  - `tabs/support/scheduling.php:61-104` when My Jobs opens;
  - `includes/api/api_scheduling.php:8-65` on Clear Cache;
  - `includes/api/api_scheduling.php:806-881` on auto-map.
- **Measured on Uganda (docs/43 §11):** they force S1 → 1 and S5 → 1581. Neither is a uCRM user; Uganda's only uCRM
  user is 1000.
- **Clear Cache has no admin check** and rewrites every staff row (`api_scheduling.php:8-65`).
- **`get_ucrm_users`** (`api_scheduling.php:734-803`) returns a hard-coded South Sudan staff list, names and e-mails
  included, to any signed-in account.
- **`FtthCrmService::ensureRetailerClient`** (`lib/FtthCrmService.php:86`) asks uCRM to find or create an
  organisation-7 client for a staff member, on staff creation and on wallet top-ups. On Uganda organisation 7 does
  not exist, so it fails every time (measured).

### 2.2 Wanted, on Uganda

- **Nothing writes `ucrm_user_id` except an admin's verified link** (J2).
  - Not a page load, a version change, My Jobs, Clear Cache or auto-map.
- **Clear Cache is admin-only.** It empties the jobs cache and writes no staff row.
- **`get_ucrm_users` returns Uganda's real uCRM users** (J2), to admins only.
- **Staff never become uCRM customers.** `ensureRetailerClient` makes no request on Uganda.
- **My Jobs' "not linked" box** says: *"Your account is not linked to a uCRM user yet. An admin links it on the
  Staff & Retailers page."* Today's text says the e-mail "was not found in the staff directory", meaning South
  Sudan's list.
- **South Sudan: all of the above exactly as today.**

### 2.3 Changes

| # | Where (5.18.49) | Change |
|---|---|---|
| 1.1 | **new** `lib/StaffJobsGate.php` | `final class StaffJobsGate { public static function applies(array $config, ?string $dataDir): bool }`. True when `TenantProfile::current($config, $dataDir)->id() === 'uganda'`; any throwable → false; memoised per request. Same shape as `QuoteTaxLine::applies` (5.18.49) |
| 1.2 | `public.php:1564-1607` (the `$_seedMapUpgrade` block inside the version-change block) | Runs only `if (!StaffJobsGate::applies($config, $dataDir))`. The cache wipe above it (1557-1563) stays for both |
| 1.3 | `public.php:1612` | `if (!empty($retailer))` becomes `if (!empty($retailer) && !StaffJobsGate::applies($config, $dataDir))` |
| 1.4 | `tabs/support/scheduling.php:96-103` | The correction runs only when the gate is false. The box at 106-118 gets the Uganda wording on Uganda |
| 1.5 | `includes/api/api_scheduling.php:8-65` `scheduling_clear_cache` | On Uganda: `if (!$isAdmin) $er2('Admin only.', 403);`, then the two cache writes only, and the answer "Cache cleared." South Sudan: the block verbatim |
| 1.6 | `includes/api/api_scheduling.php:806` `auto_map_ucrm_users` | On Uganda: 409, "Link each person on the Staff & Retailers page." Nothing is written. South Sudan verbatim |
| 1.7 | `includes/api/api_scheduling.php:734` `get_ucrm_users` | On Uganda: J2's live list (§3.3). South Sudan verbatim |
| 1.8 | `lib/FtthCrmService.php:63` (constructor) and `:86` | A new constructor argument, `bool $enabled = true`. When false, `ensureRetailerClient()` returns `null` without a request. Its callers already handle `null`: it is what they get today when organisation 7 refuses |
| 1.9 | `lib/Services.php:37`, `cron_wallet_sync.php:80` | Pass `!StaffJobsGate::applies($config, $dataDir)` |
| 1.10 | `includes/post/post_admin.php:35` | Flash on Uganda: "Staff account created." Today it says "…synced to FTTH CRM (Org 7)" |

**A trap the tests pin.** `public.php` builds `$config` from the store alone, and `currency_code`, which is what
selects Uganda there, is a vault key (`lib/ConfigVault.php:63`; `TenantProfile::current` says the same). Called
without the data directory, the gate could read Uganda as South Sudan. Every call passes `$dataDir`, and a test fails
if one does not (§11, T1.6).

### 2.4 Schema

None.

### 2.5 Tests

- **T1.1** On Uganda, a row whose e-mail is on the lists keeps its id after:
  - a page load;
  - a version change;
  - opening My Jobs;
  - Clear Cache;
  - auto-map.

  The row's e-mail is taken from the installed list, as the facts harness does.
- **T1.2** On South Sudan, each of the five still forces the list's id, byte-identical to 5.18.49.
- **T1.3** Clear Cache on Uganda:
  - 403 for a retailer, an accountant and a support account;
  - 200 for an admin;
  - the cache is emptied;
  - the staff table is byte-identical afterwards.
- **T1.4** `get_ucrm_users` on Uganda: 403 for a non-admin, and no South Sudan name or e-mail in any answer (canary
  check).
- **T1.5** `ensureRetailerClient` on Uganda: the fake uCRM's request log is empty. On South Sudan: the same requests
  as today.
- **T1.6** The gate: UGX in the vault and no `tenant_profile` gives Uganda, with the data directory.
  - A static check: every `StaffJobsGate::applies(` call in the plugin passes a second argument.
- **Weakened copies:**
  - one list left ungated;
  - the gate called without the data directory;
  - Clear Cache without its admin check;
  - auto-map still writing on Uganda.

### 2.6 Rollback

The previous release. J1 writes nothing, so there is nothing to undo.

**One consequence to know:** a rollback re-arms the lists. On the first page load afterwards, S1 and S5 are forced
back to 1 and 1581, and their J2 links are lost. Re-link them after deploying again.

---

## 3. J2 — staff ↔ uCRM user, verified

### 3.1 Today

- "Set UCRM ID" opens the Edit form. Its CRM Integrations pane has a plain number field
  (`tabs/admin/retailers.php:138`).
- `edit_retailer` saves the number as typed (`includes/post/post_sync.php:699`).
- **Measured:** S3's id was typed as 4 after the screenshot, and uCRM has no user 4.
- `set_ucrm_user_id` (`api_scheduling.php:885`) is admin-only and checks nothing.
- The New Job engineer list (`includes/api/api_support.php:352-371`) offers any active support-role row with any id.
  **Measured:** it offers S1, S3, S4 and S5, and none of their ids is a uCRM user.

### 3.2 Wanted, on Uganda

- **The number field becomes a picker of Uganda's uCRM users** (`GET users/admins`), each shown as `#id First L.`
  with active or inactive:
  - the user with **the same e-mail** as the staff account (ignoring case) is marked, and pre-selected when the
    account has no link;
  - a user already linked to another active staff account is shown but cannot be chosen ("linked to …");
  - "— not linked —" clears the link.
- **Verified on the server at save** (`edit_retailer`, and `set_ucrm_user_id`). The id must be empty, or all of:
  - a uCRM user that exists (`GET users/admins/{id}` answers 200);
  - active;
  - not linked to another **active** staff account;
  - on a role that takes jobs: `support`, `support_leader`, `support_engineer` or `admin`.

  Otherwise the save is refused with the reason, and nothing is written.
- **uCRM unreachable at save:** the link keeps its old value, the other fields save, and the page says so. A link
  is never set unverified.
- **Card badges on Uganda**, from one live list per Staff-page view:
  - "🔗 uCRM #N" when N is a real uCRM user;
  - "⚠ uCRM #N is not a uCRM user" when it is not;
  - "⚠ Not linked to uCRM" when empty.
- **The New Job engineer list** offers only rows whose id is a real, active uCRM user.
- **`ftth_crm_client_id` is not read or written** by anything here. D6 hides its tile and badges.
- **Nothing is cleared automatically.** The wrong ids (1, 4, 81, 1581) stay until an admin re-links each row. The
  page shows them as "not a uCRM user", and J4 cannot message anyone through them.

### 3.3 Changes

| # | Where | Change |
|---|---|---|
| 2.1 | **new** `lib/UcrmUsers.php` | `list(CrmApiClient $crm): ?array` (`GET users/admins`; null on error), `find(CrmApiClient $crm, int $id): ?array` (`GET users/admins/{id}`), `byEmail(array $users, string $email): ?int` (trimmed, lower-case, exactly one match or null) |
| 2.2 | `includes/api/api_scheduling.php:734` `get_ucrm_users` | Uganda: admin-only. Answers `[{id, name, active, same_email: bool, linked_to: {staff_id, staff_name}\|null}]` for an optional `retailer_id`. No e-mail in the answer |
| 2.3 | `tabs/admin/retailers.php:136-140` (the pane) and `:792` (the form fill) | Uganda: a `<select name="ucrm_user_id">` filled from 2.2 when the modal opens. South Sudan: the number field, unchanged |
| 2.4 | `includes/post/post_sync.php:679-714` `edit_retailer` | Uganda: the verification of §3.2 before `updateOne`. On refusal: flash the reason and leave `ucrm_user_id` out of `$updates`, so the old value stays and the other fields save |
| 2.5 | `includes/api/api_scheduling.php:885` `set_ucrm_user_id` | Uganda: the same verification. 422 with the reason on refusal |
| 2.6 | `tabs/admin/retailers.php:624-632` (badges) | Uganda: the three badges of §3.2, from one `UcrmUsers::list()` per page view |
| 2.7 | `includes/api/api_support.php:352-371` `support_engineers` | Uganda: keep only rows whose id is in the live list and active. See also J6 |
| 2.8 | `tabs/admin/retailers.php:320-335, 579, 618-623` | D6: on Uganda, the "CRM LINKED" tile and the "No CRM link" badges are not drawn |

### 3.4 Schema

None. `ucrm_user_id` already exists on every staff row.

### 3.5 Tests

- **T2.1** The picker lists exactly the fake uCRM's users. The same-e-mail user is pre-selected, and an upper-case
  e-mail in uCRM still matches.
- **T2.2** Saves that are refused, each leaving the row's id unchanged while the other fields save:
  - an id uCRM does not know;
  - an inactive uCRM user;
  - an id already linked to another active row;
  - a retailer or accountant role.
- **T2.3** uCRM unreachable: the link is unchanged, the other fields save, and the flash says so.
- **T2.4** `set_ucrm_user_id` follows the same rules.
- **T2.5** The engineer list offers only verified rows. In the §11 shape of production it offers nobody until S1 is
  linked to 1000.
- **T2.6** South Sudan: the form's HTML and the save are byte-identical.
- **Weakened copies:**
  - the verification skipped;
  - the "already linked" check skipped;
  - a case-sensitive e-mail match;
  - the badge trusting the stored id.

### 3.6 Rollback

The previous release. Links made with the picker are plain ids, which the old release reads the same way. Mind
the J1 caveat (§2.6).

---

## 4. J3 — staff phone numbers

### 4.1 Today

- The staff phone is saved as typed:
  - `includes/post/post_admin.php:21` on create;
  - `:56` on import;
  - `includes/post/post_sync.php:693` on edit.
- Sending strips it to digits (`lib/NotificationService.php:2011`), so `07…` goes out without a country code and
  fails.
- **Measured:** all four stored numbers are already international (one +211, three +256). S1 has none.

### 4.2 Wanted, on Uganda

- **On save** (create, edit, import), the number is stored as `PhoneNumber::international($raw, $profile)` with
  the Uganda profile. That is the existing rule used for customers since Phase 2 (`lib/PhoneNumber.php:35`):

  | Typed | Stored |
  |---|---|
  | `0772 XXX XXX`, `772XXXXXX` | `+256772XXXXXX` |
  | `+256 772 XXX XXX`, `00256772XXXXXX` | `+256772XXXXXX` |
  | `+211 912 XXX XXX` | `+211912XXXXXX` — **a +211 number stays +211; nothing is ever re-prefixed** |
  | `12345`, `07721` | kept as typed; the save succeeds and says: *"This number cannot receive WhatsApp. Write it as +256 7XX XXX XXX."* |
  | empty | empty |

- **On use** — J4's recipient and J8's sender match — the same rule is applied again, so a row saved before J3 or
  by another path still works (`StaffDirectory::phoneOf()`, §5.3).
- **The Staff page card** on Uganda, for a role that takes jobs:
  - "⚠ Number cannot receive WhatsApp" when the number does not normalise;
  - "⚠ No number: job messages cannot reach this person" when it is empty.
- **Nothing is migrated.** The measured numbers already normalise. A row takes the canonical form at its next save.
- **South Sudan: unchanged.** Its profile carries +211 and could use the same rule later; that is not part of this.

### 4.3 Changes

| # | Where | Change |
|---|---|---|
| 3.1 | `includes/post/post_admin.php:21` (create), `:56` (import); `includes/post/post_sync.php:693` (edit) | Uganda: the rule above. A number that does not normalise is kept as typed, and the flash carries the warning |
| 3.2 | `tabs/admin/retailers.php` card (near `:598`, where the number is shown) | Uganda: the two warnings |
| 3.3 | **new** `lib/StaffDirectory.php` → `phoneOf(array $row): ?string` | The same rule on use, returning `+256…` / `+211…` or null. Used by J4 and J8 |

### 4.4 Schema

None.

### 4.5 Tests

- **T3.1** The table above, row by row, through the real save handlers.
- **T3.2** The card warnings.
- **T3.3** `phoneOf()` gives the same answer as the save.
- **T3.4** South Sudan: saves byte-identical.
- **Weakened copies:**
  - the rule bypassed on edit;
  - a +211 number re-prefixed;
  - an invalid number silently emptied.

### 4.6 Rollback

The previous release. A stored `+256772XXXXXX` becomes `256772XXXXXX` when the old release strips it to digits,
which is exactly what works today.

---

## 5. J4 — one message per job change, from one place

### 5.1 Today

| Path | Where | What it does on Uganda |
|---|---|---|
| **A** dispatch cron | `cron/job_assignment_notify.php`, off (`cron/master.php:166-169`) | nothing, and **stays off** |
| **B** uCRM `job.add` | `webhook.php:2371-2488` | Looks for a phone on the uCRM user record, which has **no phone field** (measured). Its lookup address, `users/{id}`, answers 404 even for the real user 1000 (measured, §13.1). The fallback at `:2418` is broken. **Never sends** |
| **C** My Jobs → ＋ New Job | `includes/api/api_scheduling.php:555-661` | Sends at once to the staff row's number, if "Notify via WhatsApp" is ticked |
| **D** Bulk Dispatch | `api_scheduling.php:898-1062` | Sends at once |
| Reschedule | `api_scheduling.php:663-700` | Messages **whoever pressed the button** |
| `job.edit`, `job.delete` | `webhook.php:3519` (default) | logged only |

Nothing records what was sent for a job. If B could send, B and C would message the same person twice; uCRM
redelivering an event would add a third. **Measured: no job and no job message has ever existed on Uganda**, so
nothing needs cleaning up.

### 5.2 Wanted, on Uganda

- **One component decides and sends every job message:** `lib/JobNotifier.php`. Five callers hand it a job id:
  - `create_job`;
  - `bulk_create_jobs`;
  - `scheduling_reschedule`;
  - `webhook.php` `job.add`;
  - `webhook.php` `job.edit` and `job.delete`.

  A caller also passes uCRM's own answer to its POST or PATCH when it holds one. The webhook never passes the
  posted body (R3).
- **It keeps, per job, who was last told what** (`job_notify_state`, §5.4), and compares uCRM's current job with it:

  | uCRM now vs last told | Event | Message |
  |---|---|---|
  | never told, and an assignee | `assigned` | **one** assignment message to the assignee |
  | another assignee than last told | `reassigned` | **one** assignment message to the new assignee. D3: one "no longer yours" to the previous one |
  | same assignee, another day or time | `rescheduled` | **one** "new time" message to the assignee |
  | the assignee removed | `unassigned` | recorded. D3: one "no longer yours" |
  | the job deleted in uCRM (`GET` answers 404) | `cancelled` | recorded. D3: one "cancelled" |
  | closed (status 2) | `closed` | recorded only. Today's completion messages are unchanged |
  | anything else: title, tasks, comments, accept, a repeated event | — | nothing |

- **Exactly one message per change**, whichever path sees it first and however often uCRM redelivers. The claim and
  the comparison happen in one database transaction (§5.5).
- **It heals itself.** The comparison is against the last state told, not against an event. A change whose webhook
  was lost is caught at the job's next change of any kind.
- **On Uganda the old job-message sends are removed.** Removed:
  - path C's block (`api_scheduling.php:625-643`);
  - Bulk Dispatch's block (`:990-1014`);
  - Reschedule's message to the presser (`:686-697`);
  - path B's uCRM-user lookup and send (`webhook.php:2408-2457`).

  Unchanged:
  - path B's **customer** e-mail, "installation scheduled" (`webhook.php:2459-2486`);
  - Accept, task, completion and admin messages;
  - the dispatch cron, which stays off.
- **`create_job` answers the truth.** `notified: true` becomes `outcome: sent | failed | not_sent`, with the reason.

### 5.3 Who receives it (your question 5)

1. **The assignee is uCRM's.** `assignedUserId` is read from uCRM's job, never from the browser or the webhook body.
2. **The staff account** is the one **active** row whose `ucrm_user_id` equals it (`StaffDirectory::byUcrmUser()`).
   - None → no message. Outcome `no_staff_account`, recorded in `job_notify_events` and shown in WA Events (J7).
   - Two or more → no message (`ambiguous_staff_account`). J2 prevents this, and the notifier refuses to guess.
3. **The number** is `StaffDirectory::phoneOf(row)` (J3). None → no message (`no_usable_number`).
   - **The uCRM user record's phone is never used.** It has no phone field (measured).
4. **It is sent on the support number**, `sendVia('support', …, ContactOptOut::CLASS_STAFF)`. That is the class
   path A used: a colleague's old "STOP" never silences job dispatch. To stop messages to someone, deactivate the
   account or change its number.
5. **The Message Log records the transport's answer** as today. `job_notify_events` records the event and its
   outcome (§5.4).

### 5.4 Schema — the one change (your questions 3 and 4)

**Yes, J4 needs a new table: two, in one new migration, `migrations/075_job_notifications.sql`.**

```sql
-- One row per uCRM job the notifier has seen: who was last told what.
CREATE TABLE IF NOT EXISTS job_notify_state (
    job_id       INTEGER PRIMARY KEY,          -- uCRM scheduling job id
    assignee_id  INTEGER,                      -- uCRM user id last told; NULL = nobody assigned
    job_time     TEXT    NOT NULL DEFAULT '',  -- the job's time as last told, UTC 'Y-m-d H:i'; '' = unscheduled
    job_status   INTEGER,                      -- uCRM status at the last observation
    gone         INTEGER NOT NULL DEFAULT 0,   -- 1 once uCRM answered 404 for the job
    version      INTEGER NOT NULL DEFAULT 1,
    updated_at   TEXT    NOT NULL DEFAULT (datetime('now'))
);

-- Every change the notifier observed, messaged or not: the job's history as far as notifications go.
CREATE TABLE IF NOT EXISTS job_notify_events (
    id                INTEGER PRIMARY KEY AUTOINCREMENT,
    job_id            INTEGER NOT NULL,
    event             TEXT    NOT NULL,  -- assigned | reassigned | rescheduled | unassigned | cancelled | closed
    from_assignee_id  INTEGER,
    to_assignee_id    INTEGER,
    from_time         TEXT,
    to_time           TEXT,
    source            TEXT    NOT NULL,  -- my_jobs | bulk | reschedule | ucrm_webhook
    staff_id          INTEGER,           -- the staff account messaged (retailers.id), if any
    outcome           TEXT    NOT NULL,  -- sent | failed | recorded | no_staff_account | ambiguous_staff_account | no_usable_number
    detail            TEXT,              -- a short reason; never a phone number or a message text
    created_at        TEXT    NOT NULL DEFAULT (datetime('now'))
);
CREATE INDEX IF NOT EXISTS idx_job_notify_events_job ON job_notify_events(job_id, id);
```

**Why not an existing mechanism:**

- **`job_assignments_seen`**, the disabled cron's memory, is a JSON document rewritten whole. Two processes can
  write it at once: a `job.add` webhook arrives while `create_job` is still running. That cannot give "exactly one".
- **`EvoWebhookGuard`'s claim table** deduplicates inbound WhatsApp message ids, and is pruned. A pruned claim would
  let a redelivered event send again.
- **A plain "already sent" key** of (job, assignee, time) would block a legitimate A → B → A reassignment for ever.
  The state row compares with the **last** state instead.

**On South Sudan** the migration creates the two tables empty, and nothing there reads or writes them. Migrations
run on every install. That empty schema is the only difference South Sudan can observe (§10, question 8).

### 5.5 How it works, step by step

`JobNotifier::observe(int $jobId, string $source, ?array $job = null): array`

1. **Read the job.** Without `$job`, it GETs `scheduling/jobs/{id}`:
   - **404** means deleted;
   - **any other failure** changes nothing and returns `unverified`, which the webhook logs.
2. **The new state:** `assignedUserId` (0 → NULL), the job time converted to UTC `Y-m-d H:i` (J5), and the status.
3. **`BEGIN IMMEDIATE`**, which serialises writers across processes. Read the state row, and decide the event by
   §5.2's table.
4. **Write the new state** (version + 1) in the same transaction, then **`COMMIT`**. This is the claim.
   - A second process observing the same change waits for the lock, reads the new state, finds no difference and
     sends nothing.
5. **Send outside the transaction**, so the lock is not held during an HTTP call. The outcome comes from
   `NotificationService::lastSendResult()`.
6. **Append the event** to `job_notify_events`.

**A failed send is not retried automatically.** The state has moved on, so a redelivered event does not send twice.
The failure stands in:

- the Message Log (failed);
- the failure queue, retried by hand as today;
- `job_notify_events` (`failed`);
- WA Events (J7).

**A job the plugin was never told about** — created before J4, or while uCRM could not be read — gets its first
message at its next change. Nothing polls, so nothing can burst. The only thing that ever polled is path A, which
stays off.

### 5.6 The messages (D5 — approve or edit)

Built only from uCRM's job and the staff directory, never from what a browser sent. The text is the same whichever
path triggered it. Made-up people:

```
Hi *Grace*,

🔧 *New job for you*

*Starlink installation — Test Client*
📍 Plot 1 Test Road, Kampala
📅 *Tue 06 Oct* at *09:00*

Open *My Jobs → Job #950* for the details and the tasks.

— DishNet Africa
```

```
Hi *Grace*,

📅 *Job #950 has a new time*

*Starlink installation — Test Client*
Now: *Wed 07 Oct* at *11:00*
Was: Tue 06 Oct at 09:00

— DishNet Africa
```

**How they are built:**

- **Day and hour** are Kampala's: the job time from uCRM, converted with `dn_tz()`. A job with no time says
  "📅 Not scheduled yet".
- **The first name** comes from the staff account.
- **The signature** is the Uganda profile's trading name ("DishNet Africa"), not typed into the code.
- **Left out on purpose:**
  - **"Assigned by"** — uCRM's job does not say who assigned it, so it could appear or not depending on which
    path ran first;
  - **the task list** — `create_job` adds tasks after creating the job, so they may not exist yet.

  Both would make the message differ by path. Tasks are in My Jobs.
- **D3, if chosen:** *"Job #950 (Starlink installation — Test Client) is no longer assigned to you. Please do not
  go."*

### 5.7 How a reassignment is detected (your question 6)

**Not from the webhook's "before" copy.** It comes from comparing uCRM's current `assignedUserId` with
`job_notify_state.assignee_id`, the last person the plugin told. The triggers:

- **A change in uCRM's own Scheduling screen** → uCRM's `job.edit` webhook. The plugin's endpoint receives every
  event (docs/09).
- **A change made through the plugin** (today only Reschedule; there is no reassign button in the plugin) → the
  handler calls the notifier directly after its PATCH.

The notifier only acts on what uCRM answers. So:

- a forged `job.edit` changes nothing;
- a forged `job.delete` for a job that still exists changes nothing;
- a `job.delete` for a job that is really gone records the truth.

**Evidence still needed (V2, §12.4):** that uCRM sends `job.edit` and `job.delete` at all. None has ever been seen,
because no job has existed. The first live test settles it. Until then, a change made in uCRM's screen may produce
no message, while every change made through the plugin still does.

**Why D4 matters here.** `create_job` cannot know uCRM's job id before uCRM answers, and uCRM may already be
delivering `job.add`. An unticked box could therefore be overtaken by the webhook, which does not know about the
box. A box that works only sometimes is worse than no box.

### 5.8 Changes

| # | Where | Change |
|---|---|---|
| 4.1 | **new** `migrations/075_job_notifications.sql` | §5.4 |
| 4.2 | **new** `lib/JobNotifier.php` | §5.5–§5.6 |
| 4.3 | **new** `lib/StaffDirectory.php` | `byUcrmUser(int $id): array` (active rows), `phoneOf(array $row): ?string` (J3), `activeByPhone(string $digits): ?array` (J8) |
| 4.4 | `includes/api/api_scheduling.php:625-650` (`create_job`) | Uganda: after the job and its tasks, `$outcome = $notifier->observe($newJobId, 'my_jobs', $newJob)`. The block that sends is not reached |
| 4.5 | `api_scheduling.php:990-1014` (`bulk_create_jobs`) | The same, `source = bulk` |
| 4.6 | `api_scheduling.php:678-697` (`scheduling_reschedule`) | Uganda: the J5 date, the PATCH, the comment, then `observe($jobId, 'reschedule', $patchResult)`. The message to the presser is not sent |
| 4.7 | `webhook.php:2408-2457` (`job.add`) | Uganda: replaced by `observe($jobId, 'ucrm_webhook', $job)` and one webhook-log line with the outcome (J7). The customer e-mail block after it, `:2459-2486`, stays byte for byte |
| 4.8 | `webhook.php` before `default:` (`:3519`) | `case 'job.edit': case 'job.delete':`. Uganda: `observe($entityId, 'ucrm_webhook')`, log, 200. South Sudan: the default's exact log line and answer |
| 4.9 | `tabs/support/scheduling.php:1650, 1761, 1908` | D4: on Uganda the box is not drawn and `notify_wa` is not sent. The server ignores it on Uganda anyway |

### 5.9 Tests

- **T4.1** A job created in My Jobs, then uCRM's `job.add` for it:
  - one message;
  - one `job_assigned` row in the Message Log;
  - one `assigned` event;
  - in both orders (webhook first, then create; create first, then webhook).
- **T4.2** `job.add` delivered three times: one message.
- **T4.3** Two processes observing the same change at once, as a real race with two PHP processes: one message.
- **T4.4** Reassignment A → B: one message to B. B → A: one message to A.
- **T4.5** A new time: one `job_rescheduled` message to the assignee, not to whoever pressed the button.
- **T4.6** Unassignment, deletion (404) and closing: recorded, no message. With D3: exactly one notice.
- **T4.7** The assignee has no staff account, or two, or no usable number: no message, and the right outcome.
- **T4.8** A forged `job.edit` whose body says the assignee changed while uCRM says not: nothing.
- **T4.9** uCRM unreachable: no state change, `unverified` logged. The next event catches up.
- **T4.10** A failed transport: the Message Log says failed, the failure queue holds it, `outcome = failed`, and a
  redelivered event does not send again.
- **T4.11** The customer "installation scheduled" e-mail on `job.add`: byte-identical to 5.18.49.
- **T4.12** South Sudan:
  - `create_job`, bulk, reschedule and `job.add` send exactly today's messages;
  - `job.edit` and `job.delete` log exactly today's line;
  - the two tables stay empty.
- **Trace:** `scripts/harness/jobs-trace/trace.php` re-run. Its lines for B1 (the duplicate), A6 (redelivery) and A7
  (no reassignment notice) flip from "observed" to "prevented".
- **Weakened copies:**
  - claim after send;
  - no transaction;
  - the state compared with the webhook body;
  - the uCRM record's phone used;
  - `CLASS_TRANSACTIONAL`;
  - the old path C send left in.

### 5.10 Rollback

The previous release. The two tables stay and the old release ignores them. Messages go back to paths B and C. B
never sends on Uganda (measured), so there are still no duplicates.

---

## 6. J5 — Kampala time to uCRM

### 6.1 Today

- `create_job` (`api_scheduling.php:602`) and Bulk Dispatch (`:948`) send the typed time as UTC:
  `$date . 'T' . $time . ':00.000Z'`. So 09:00 is stored as 12:00 in Kampala.
- Reschedule (`:679`) sends `"2026-10-06 11:00"` with no zone at all.
- The second-site KYC job (`lib/KycService.php:1937`) sends `T09:00:00.000Z`.

### 6.2 Wanted, on Uganda

- **Every time sent to uCRM carries Kampala's offset,** in uCRM's own format: `2026-10-06T09:00:00+0300`. That is
  `Y-m-d\TH:i:sO` in `dn_tz()`, the format `cron_maintenance.php:605` already parses from uCRM.
- **A malformed date or time is refused** with 422. Today it is passed through.
- **J4's messages print uCRM's job time converted to Kampala,** so a job typed in uCRM's screen reads right too.
- **What is not changed:** My Jobs' own display converts in the phone's browser (`tabs/support/scheduling.php:191,
  592, 711`), which is already right on a Kampala phone.
- **South Sudan: the `.000Z` strings unchanged.**
- **uCRM's own screen** shows the time in uCRM's configured zone. **Measured: uCRM writes +0300** (§13.1), so its
  screen shows Kampala time too.

### 6.3 Changes

| # | Where | Change |
|---|---|---|
| 5.1 | **new** `lib/JobTime.php` | `toUcrm(string $date, string $time, array $config): ?string` (null when malformed) and `toLocal(?string $ucrmIso, array $config): ?DateTimeImmutable` |
| 5.2 | `api_scheduling.php:602` (`create_job`), `:948` (bulk), `:679` (reschedule) | Uganda: `JobTime::toUcrm()`, and 422 on null |
| 5.3 | `lib/KycService.php:1937` | Uganda: tomorrow at 09:00 through `JobTime::toUcrm()` |

### 6.4 Tests

- **T5.1** 09:00 on 6 October → `2026-10-06T09:00:00+0300`, through the fake uCRM and back. The message says 09:00.
- **T5.2** Near midnight: 00:30 stays on the same day.
- **T5.3** Reschedule, and the KYC job.
- **T5.4** Malformed input → 422, and nothing is sent to uCRM.
- **T5.5** South Sudan: the three `.000Z` strings are byte-identical.
- **Weakened copy:** a `Z` suffix left in place.

### 6.5 Rollback

The previous release. Jobs already created keep the time they were given.

---

## 7. J6 — who may do what, checked on the server

### 7.1 Today (docs/43 §6.4, proved in the trace)

- `create_job` checks nothing.
- `scheduling_job_detail`, `_job_update`, `_complete` and `_reschedule` have "ownership" checks that are empty `if`
  blocks.
- `scheduling_task_update` and `_add_comment` check nothing.
- `save_job_signature`, `save_survey_result` and `get_survey` check nothing (`includes/api/api_crm_misc.php:17, 58,
  154`).
- `support_engineers` returns the engineers' **phone numbers** to any signed-in account
  (`includes/api/api_support.php:352`).
- An API call from an already-open page uses the session's cached copy of the account, which is not re-checked
  (`includes/api_handlers.php:76-85`). So a deactivated account keeps acting until it loads a page.

### 7.2 Wanted, on Uganda

| Action | Allowed |
|---|---|
| Create a job (`create_job`) | admin, `support_leader`, `support`; D7: `support_engineer` |
| … and every engineer chosen | an active staff account of a job-taking role, linked to a real, active uCRM user (J2) |
| Bulk Dispatch | admin, `support_leader`, as today, plus the same engineer rule |
| Detail, update status, tick a task, reschedule, comment, complete, signature, survey | **the assignee** (the caller's `ucrm_user_id` equals the job's `assignedUserId`, both non-zero), a `support_leader`, or an admin |
| The engineer list (`support_engineers`) | whoever may create a job. **Never a phone number** — the New Job screen does not use them |

- **The caller is re-read from the store** for every job action: the row by id, active or refused. A deactivated
  account is stopped at its next click, not its next page.
- **The job is read from uCRM** for the check. A job with no `assignedUserId` belongs to no one, so only a leader or
  an admin may act on it.
- **Ticking a task** requires the `job_id`, and the task must be one of that job's tasks. Today a task id alone is
  accepted.
- **A refusal** is 403, *"This job is not assigned to you."*, and returns no job data.
- **South Sudan: unchanged** (D2). The same holes exist there. Turning this on there is a one-line gate change,
  once you choose it.

### 7.3 Changes

| # | Where | Change |
|---|---|---|
| 6.1 | **new** `lib/JobAccess.php` | `caller(array $me2, $store): ?array`, `canCreate(array $row): bool`, `canActOn(array $row, array $job): bool`, `assignable(array $row, array $ucrmUsers): bool` |
| 6.2 | `includes/api/api_scheduling.php`: `:152` detail, `:237` update, `:300` task, `:354` complete, `:555` create, `:663` reschedule, `:703` comment, `:898` bulk | Uganda: the check first. The empty blocks at `:253-255`, `:366-368` and `:674-676` become the real check |
| 6.3 | `includes/api/api_crm_misc.php:17, 58, 154` | Uganda: the same check against the job |
| 6.4 | `includes/api/api_support.php:352` | Uganda: `canCreate`, and no `phone` in the answer |

### 7.4 Tests

**T6.1 — a matrix.** Each account type is tried against each action:

- **Account types:** admin; `support_leader`; `support` as the assignee; `support` on someone else's job;
  `support_engineer`; accountant; retailer; field agent; sales; and a deactivated account with an open session.
- **Actions:** create; detail; update; task; complete; reschedule; comment; signature; survey; the engineer list.

Each pairing has its expected answer, with the job's data absent from every refusal.

- **T6.2** A task of another job, sent with this job's id: refused.
- **T6.3** South Sudan: every action answers as in 5.18.49.
- **Weakened copies:**
  - the cached session used;
  - `ucrm_user_id` 0 treated as a match;
  - the check placed after the uCRM write.

### 7.5 Rollback

The previous release.

---

## 8. J7 — say what really happened

### 8.1 Today

- The webhook logs "notification sent" whatever the send returned (`webhook.php:2444`).
- WA Events classifies log lines by their wording and labels the sent ones **"Delivered"**
  (`tabs/engage/failed_queue.php:94-107, 244, 253, 272`).
- The WA Events menu badge reads `timestamp` or `created_at`, but the log writes `received_at`. So it never counts
  (`includes/navigation.php:131`).
- The Message Log shows "✓ sent" or "✗ fail" at hand-over, which is truthful. But a message suppressed by an
  opt-out shows as "✗ fail", with no number (`lib/NotificationService.php:1970-1981`).
- Evolution's delivery and read receipts (`messages.update`) are accepted and dropped (`evo_webhook.php`, step 5).

### 8.2 Wanted, on Uganda

- **The webhook log's job lines state the notifier's outcome:**
  - "Job #950: assignment message sent (staff #4)";
  - "… failed: WhatsApp refused";
  - "… not sent: uCRM user 1000 has no staff account";
  - "… recorded: cancelled".

  A staff id, never a number (R4).
- **WA Events** says **"Sent (handed to WhatsApp)"** instead of "Delivered". Job lines are classified by their
  explicit outcome word.
- **The menu badge** reads `received_at`.
- **The Message Log** gets one line above the table: *"Sent = handed to WhatsApp. Whether it reached the phone is not
  measured."* A suppressed row shows "— suppressed (opted out)", not "✗ fail".
- **Delivery and read receipts: marked unavailable, not captured.**
  - What is missing is evidence, not code. The field names of one `messages.update` event from this Evolution
    version have never been recorded.
  - Tying a receipt to its message would need Evolution's message id stored on each Message Log row: a column, and
    a second schema change.
  - Capturing them without that evidence would be inventing a delivery status, which you ruled out.
  - **Measured: receipts are subscribed on all three numbers** (§13.1). They reach the plugin today and are
    dropped. Capturing them can be a later, separate change, once one real event's shape is recorded.
- **South Sudan: unchanged.**

### 8.3 Changes

| # | Where | Change | Release (D1) |
|---|---|---|---|
| 7.1 | `webhook.php` job cases (4.7, 4.8) | the outcome line | B |
| 7.2 | `tabs/engage/failed_queue.php:94-107, 244, 253, 272` | Uganda: the wording and the outcome-word classification | A |
| 7.3 | `includes/navigation.php:131` | Uganda: `received_at` | A |
| 7.4 | `tabs/engage/whatsapp.php:1279-1320` | Uganda: the heading line and "suppressed" | A |

### 8.4 Tests

- **T7.1** A send refused by the fake WhatsApp server:
  - the Message Log says failed;
  - the webhook log says failed;
  - WA Events files it under Failed.

  This is the trace's A5, flipped.
- **T7.2** The badge counts today's events.
- **T7.3** An opted-out send shows "suppressed".
- **T7.4** No phone number in any new log line.
- **T7.5** South Sudan: byte-identical screens.

### 8.5 Rollback

The previous release.

---

## 9. J8 — a colleague's WhatsApp is not answered by the AI

### 9.1 Today

- Every inbound WhatsApp is stored (`evo_webhook.php:254`, step 8), checked for "STOP" (step 8b) and queued for the
  AI (step 9, `:294`), whoever sent it.
- **Measured:** 6 staff conversations are filed as customer ones, and the AI has been queued **109** times for staff
  numbers.

### 9.2 Wanted, on Uganda (your question 7)

**How a staff member is recognised from a WhatsApp number:**

- **The sender's number** is read as today: `senderPn`, else the chat id; an `@lid` id carries no number and is
  skipped already.
- **It is compared, exactly,** with the international numbers of **active** staff accounts (J3's rule).
  - All digits, not the last nine: a +211 number and a +256 number must never be confused.
  - Nothing typed in the message, and no name, counts.

**What happens to a staff member's message:**

1. It is **stored as today**, in the same inbox and history.
2. The conversation's category is set to **`staff`**, using the existing column (`ConversationService::categorise`,
   `lib/ConversationService.php:695`).
   - The inbox shows the badge, and its category filter lists staff conversations.
3. It is **not queued for the AI**, and its words are **not recorded as an opt-out**. A colleague writing "stop the
   install" is not unsubscribing.
4. One log line: "staff message kept for the team". No number.

**Belt and braces:**

- **The AI worker checks again** before doing anything (`workers/AiReplyWorker.php:92`). An event queued before the
  deploy, or by any other route, is dropped with a log line.
- **The follow-up engine** never follows up a `staff` conversation (`lib/FollowUpPolicy.php:391`). **It is on
  here** (measured, §13.1), so this is needed, not a precaution.

**Edge cases:**

- **A deactivated account's number** is treated as a customer again. Your instruction says "an active staff member".
- **A staff member who is also a customer** is treated as staff: a person answers them, not the AI.
- **The six existing staff conversations** become `staff` at their next message. Nothing is migrated.

**South Sudan: unchanged.**

### 9.3 Changes

| # | Where | Change |
|---|---|---|
| 8.1 | `lib/StaffDirectory.php` → `activeByPhone(string $digits): ?array` | Built once per request from the active staff rows |
| 8.2 | `evo_webhook.php`, between step 8 (`:254-262`) and step 8b (`:264`) | Uganda: the check, `categorise`, the log line, then `continue` past 8b and 9 |
| 8.3 | `workers/AiReplyWorker.php:92-103` | Uganda: the same check after the required-fields check |
| 8.4 | `lib/FollowUpPolicy.php:391` `isFollowable` | Uganda: `category === 'staff'` → not followable |

### 9.4 Tests

- **T8.1** A staff number writes:
  - stored;
  - category `staff`;
  - no `ai.reply` event;
  - no opt-out, even for "STOP".
- **T8.2** A customer number writes: exactly as today. This is the control.
- **T8.3** A deactivated staff number: treated as a customer.
- **T8.4** A +211 staff number is not matched by a +256 number ending in the same nine digits.
- **T8.5** A queued `ai.reply` for a staff number is dropped by the worker.
- **T8.6** A `staff` conversation is not followable.
- **T8.7** South Sudan: byte-identical.
- **Weakened copies:**
  - a last-nine-digit match;
  - inactive rows included;
  - the worker check removed.

### 9.5 Rollback

The previous release. Conversations keep the `staff` label, which the old release shows as a category and ignores.

---

## 10. Your ten questions, answered

1. **Exact files and functions.** Each change's table (§2.3, §3.3, §4.3, §5.8, §6.3, §7.3, §8.3, §9.3).
   - **New files:**
     - `lib/StaffJobsGate.php`, `lib/UcrmUsers.php`, `lib/StaffDirectory.php`, `lib/JobNotifier.php`,
       `lib/JobTime.php`, `lib/JobAccess.php`;
     - `migrations/075_job_notifications.sql`.
   - **Changed files:**
     - `public.php`, `webhook.php`, `evo_webhook.php`;
     - `includes/api/api_scheduling.php`, `api_support.php`, `api_crm_misc.php`;
     - `includes/post/post_admin.php`, `post_sync.php`;
     - `tabs/support/scheduling.php`, `tabs/admin/retailers.php`;
     - `tabs/engage/failed_queue.php`, `whatsapp.php`, `includes/navigation.php`;
     - `lib/FtthCrmService.php`, `lib/Services.php`, `cron_wallet_sync.php`, `lib/KycService.php`;
     - `workers/AiReplyWorker.php`, `lib/FollowUpPolicy.php`.
2. **Current vs wanted.** Each "Today" / "Wanted" pair (§2.1–§9.2).
3. **Schema.** One migration, 075: two new tables for J4 (§5.4). No existing table changes shape.
   - J3 changes stored **values**, only when a row is saved.
   - J8 uses the existing `category` column.
4. **Does J4 need a new deduplication table?** Yes: `job_notify_state`, plus `job_notify_events` for the record.
   §5.4 says why no existing mechanism fits.
5. **How the assignee is determined.** From uCRM's job, then the one active staff account linked to that uCRM user,
   then that account's number (§5.3). Never the uCRM user's record, never the browser.
6. **How reassignments are detected.** By comparing uCRM's current assignee with the last one the plugin told, on
   every job event and every plugin change (§5.7). Never from the webhook's "before" copy.
7. **How a staff member is recognised on WhatsApp.** An exact international-number match against active staff
   accounts (§9.2).
8. **How South Sudan is guaranteed unchanged.**
   - **One switch, true only on Uganda,** and false on any error (R1).
   - **Every changed place keeps today's code** in its South Sudan branch.
   - **Golden tests** replay each scenario with the South Sudan profile and demand byte-identical messages,
     mappings, answers and screens.
   - **A static test** proves every switch call passes the data directory.
   - **The one South Sudan difference is schema-only:** migration 075's two tables exist there, empty, and nothing
     reads or writes them.
9. **Test matrix.** §11.
10. **Deployment order and rollback.** §12.

---

## 11. Test matrix

**Every test runs the real handler** against:

- a fake uCRM;
- the repository's fake WhatsApp server;
- a store seeded with canary values.

**Every "Uganda" line has a South Sudan twin** that must match 5.18.49 byte for byte.

**Where they live:**

- new plugin suites under `dishnet-hybrid-sudan/tests/`: `test_staff_jobs_gate.php`, `test_ucrm_link.php`,
  `test_staff_phone.php`, `test_job_notifier.php`, `test_job_time.php`, `test_job_access.php`,
  `test_job_status_truth.php` and `test_staff_whatsapp.php`;
- the updated sandbox trace.

**How they are run:** the full suite twice, and the weakened copies of each section.

| Id | Change | Scenario | Expected on Uganda |
|---|---|---|---|
| T1.1 | J1 | list e-mail on a row; page load, version change, My Jobs, Clear Cache, auto-map | id unchanged |
| T1.3 | J1 | Clear Cache by retailer / accountant / support / admin | 403 ×3, 200; staff table unchanged |
| T1.4 | J1 | `get_ucrm_users` | admin-only; no South Sudan canary |
| T1.5 | J1 | staff created | no organisation-7 request |
| T1.6 | J1 | gate with and without the data directory | Uganda only with it; static check passes |
| T2.1 | J2 | picker | uCRM's users; e-mail match pre-selected, case-insensitive |
| T2.2 | J2 | unknown / inactive / taken / wrong-role id | refused; row unchanged; other fields saved |
| T2.3 | J2 | uCRM down at save | link unchanged, said so |
| T2.5 | J2 | engineer list, production's shape | nobody until S1 is linked to 1000 |
| T3.1 | J3 | `0772…`, `+256…`, `00256…`, `+211…`, `12345`, empty | canonical, +211 kept, warning, empty |
| T4.1 | J4 | My Jobs create + `job.add`, both orders | one message, one event |
| T4.2 | J4 | `job.add` ×3 | one message |
| T4.3 | J4 | two processes, same change | one message |
| T4.4 | J4 | A → B → A | one to B, then one to A |
| T4.5 | J4 | new time | one message to the assignee |
| T4.6 | J4 | unassign / delete / close | recorded; D3: one notice |
| T4.7 | J4 | no account / two accounts / no number | no message; outcome stated |
| T4.8 | J4 | forged `job.edit` | nothing |
| T4.10 | J4 | WhatsApp refuses | failed everywhere; no second send |
| T4.11 | J4 | customer "installation scheduled" e-mail | byte-identical |
| T5.1 | J5 | 09:00 on 6 Oct | `2026-10-06T09:00:00+0300`; message 09:00 |
| T5.4 | J5 | malformed time | 422, nothing sent |
| T6.1 | J6 | the role × action matrix (§7.4) | as §7.2; no data in refusals |
| T6.2 | J6 | a task of another job | refused |
| T7.1 | J7 | refused send | failed in Message Log, webhook log and WA Events |
| T7.2 | J7 | badge | counts today's events |
| T8.1 | J8 | staff number writes, including "STOP" | stored, `staff`, no AI, no opt-out |
| T8.2 | J8 | customer number writes | as today |
| T8.4 | J8 | +211 vs +256 with the same last nine digits | no match |
| T8.5 | J8 | queued `ai.reply` for staff | dropped by the worker |

**After deployment, read-only:**

- the users check (§13);
- the facts command (docs/43 §10.4) run a day later. Expected:
  - S1's link survives page loads;
  - "AI replies queued for a staff number" stays at 109;
  - no staff conversation is newly queued.

---

## 12. Deployment order and rollback

### 12.1 Order (D1: two releases)

| Step | What | Who | Sends anything? |
|---|---|---|---|
| 0 | **The read-only uCRM users check** (§13) — **done**, 27 Sep 16:37 UTC (§13.1) | you, one command | no |
| 1 | **Release A — 5.18.50**: J1, J2, J3, J5, J6, J7 (display), J8 | the pinned deploy command | no — nothing new is sent |
| 2 | **Create a uCRM user for each technician** in UISP/uCRM, with the same e-mail as their staff account (docs/43 §11.3). Retailers: none | you | no |
| 3 | **Link each staff account** with the J2 picker: S1 → 1000, then each technician. **Give S1 a number** | you, on the Staff page | no |
| 4 | **Release B — 5.18.51**: J4 and J7's webhook lines | the pinned deploy command | no — only later job changes send |
| 5 | **The controlled live test** (§12.3) | you, after approval | one message to you |

J1 must come first. Without it, step 3's link for S1 is undone at the next page load. J4 comes last, so the first
message it ever sends goes to a correctly linked person.

### 12.2 How each release is deployed

The same pattern as 5.18.49:

- **A deploy script pinned to the reviewed commit**, rehearsed against a fake install.
- **A backup before copying.**
- **Read-only checks afterwards:**
  - the switch resolves to uganda on the live install;
  - the installed files carry the gates;
  - A: the staff links before and after are identical;
  - B: migration 075 applied, both tables empty, and the Message Log count unchanged by the deploy itself.
- **A log file sent back.**
- **The deploy changes no setting** (R5). D8, if chosen, is your own command.

### 12.3 The controlled live test (after release B; waits for your approval)

On your own number, with **no customer on the job** and a neutral title (`TEST internal — please ignore`):

1. Create it in My Jobs, assigned to yourself (1000).
   - **Expect exactly one WhatsApp**, one `job_assigned` row and one `assigned` event.
2. Change its time **in uCRM's own screen**.
   - **Expect exactly one** "new time" message. This proves `job.edit` reaches the plugin (V2).
3. Delete it in uCRM.
   - **Expect no message**, and a `cancelled` event. D3: one notice.
4. Send back the log file of a read-only check of those minutes.

### 12.4 Evidence still to gather

| Id | What | How | Blocks |
|---|---|---|---|
| V1 | Uganda's uCRM users: ids, active, phones; what `users/{id}` answers for a real id | **done**, 27 Sep 16:37 UTC (§13.1) | nothing: it confirms §3 and §5.3 |
| V2 | uCRM sends `job.edit` and `job.delete` to the plugin | §12.3 step 2–3 | reassignment made **in uCRM's screen**. Plugin-side changes do not depend on it |
| V3 | uCRM accepts `…T09:00:00+0300` | §12.3 step 1 | J5 |
| V4 | uCRM's single-job answer carries `assignedUserId`, as its list answer does | §12.3 step 1 | J4, J6. Both treat a missing value as "assigned to no one" |
| V5 | the shape of one `messages.update` receipt | a later, separate change | receipts (not in J1–J8) |

### 12.5 Rollback

| Release | How | What remains | Watch |
|---|---|---|---|
| A | the previous plugin from the deploy's backup | canonical phones (valid); links (valid) | **the South Sudan lists re-arm**: S1 and S5 are forced back to 1 and 1581 at the next page load. Re-link after deploying again |
| B | the previous plugin from the deploy's backup | the two tables, ignored | messages revert to paths B and C. B never sends on Uganda, so there are no duplicates |

Nothing is ever migrated in uCRM, and no setting is changed by any step.

---

## 13. The read-only uCRM users check — ran 27 September 16:37 UTC

**What it answers.** You asked for the actual Uganda staff-user ids, and whether their uCRM records hold phone
numbers, before any code. The facts command already measured the list: one user, 1000, no phone field. This check
goes further:

1. **The profile and the zone** the plugin runs on, and why. It prints the resolved profile, not the raw setting.
2. **Every uCRM user**, with:
   - id;
   - active or not;
   - linked to a UISP user or not;
   - the field names of **both** its list record and its detail record (`GET users/admins/{id}`). A phone could hide
     in the detail only;
   - any phone-like field anywhere in them, including nested ones — its name, and whether it holds a number (the
     country code only, never the number);
   - what `GET users/{id}` — the address path B uses — answers for a **real** id.
3. **Each staff account against those users:**
   - the e-mail match the J2 picker would propose;
   - whether the stored id is a real user;
   - duplicate e-mails;
   - the two other settings that hold a uCRM user id (`bidal_ucrm_user_id`, `accountant_ucrm_user_id`).
4. **The offset uCRM writes into its own timestamps** (J5: which clock uCRM's screen shows). The offset only: no
   date, name or amount.
5. **Whether the follow-up engine is on** (J8).
6. **Which events each WhatsApp number's webhook subscribes to** (J7: delivery receipts). Event names only, never
   the address.

**What it never does:**

- create a user, change a mapping, create a job, send a message, or change a setting;
- write to the plugin's store.

**How it reads:**

- a copy of the store, made inside the container and removed afterwards;
- uCRM and Evolution with GET only, through the plugin's own clients.

**What it never prints:** a name, username, e-mail address, phone number, date, amount, address, URL, token or key.
A mask stands behind it as a backstop.

**Rehearsed** against a fake install full of canary values (`scripts/harness/ucrm-users/rehearse.sh`):

- **394 of 394 checks on two runs**, including production's shape;
- **11 weakened copies**, each caught: names, e-mails, a number, the webhook address or the dates printed; the detail
  record skipped; the live store read; a write to uCRM; a WhatsApp webhook changed; a case-sensitive e-mail match;
  the copy left behind;
- the mask proved on its own, with a control on the control.

**Run as root on the server:**

```
cd /opt/dishnet && git pull origin claude/study-this-jhe2eg && mkdir -p /root/dnb-jobs && bash scripts/dnb-ucrm-users-facts.sh 2>&1 | tee /root/dnb-jobs/ucrm-users-$(date -u +%Y%m%dT%H%M%SZ).log
```

Then send back **the log file** from `/root/dnb-jobs/`, not a copy of the terminal. The `git pull` updates only
this checkout of the repository; the installed plugin is not touched.

### 13.1 Result — 27 September 16:37 UTC

Run by the operator on the server, from checkout `2d1da64`, against the installed plugin 5.18.49 (`e076632`). **It
ran to its end: 3 ok, 0 failed, 5 notes.** Nothing was created, sent or changed.

| Question | Measured |
|---|---|
| Profile and zone | **uganda**, selected by the currency UGX (no `tenant_profile` setting). Africa/Kampala, from the `timezone` setting |
| uCRM staff users | **One**: id 1000, active, linked to a UISP user, with the same e-mail as S1, the admin account |
| A phone in uCRM's user records | **None**, in the list record or in the detail record. `users/admins/1000` adds no field |
| What path B's lookup, `users/{id}`, answers for the real user 1000 | **404.** Path B fails at its first step even for a real user, before the missing phone field and the broken fallback are reached |
| What a verified picker would propose | S1 → 1000, by e-mail. S2–S5: no uCRM user has their e-mail |
| Stored ids that are real uCRM users | **0 of 4** (1, 4, 81, 1581) |
| E-mails shared by two accounts | None, among staff accounts or uCRM users |
| The other uCRM-id settings | `bidal_ucrm_user_id` and `accountant_ucrm_user_id` are both unset |
| uCRM's own offset | **+0300** on a client, an invoice and a payment: uCRM keeps Kampala's clock |
| Follow-up engine | **On** |
| WhatsApp webhooks | All three numbers point at the plugin and subscribe to `MESSAGES_UPSERT`, `MESSAGES_UPDATE` and `CONNECTION_UPDATE` |

### 13.2 What it confirms, and what it sharpens

- **J2 stands as specified.** Today the picker would offer exactly one uCRM user, 1000, and propose it for S1. The
  technicians need a uCRM user each first (§12.1, step 2).
- **J4 stands, with one more reason.** Path B's lookup address answers 404 even for a real user. So no uCRM user
  record could ever give a number, and the staff account is the only source (§5.3).
- **J5 is confirmed.** uCRM writes +0300. Once J5 sends Kampala's offset, uCRM's own Scheduling screen shows the
  hour that was typed. V3, uCRM accepting +0300 on a new job, still waits for the first real job.
- **J7: the receipts already reach the plugin and are dropped.** `MESSAGES_UPDATE` is subscribed on all three
  numbers, so Evolution is sending delivery and read receipts today, and step 5 of `evo_webhook.php` discards them
  (§8.1). Capturing them stays outside J1–J8 until one real event's field names are recorded (V5). The subscription
  is no longer in question.
- **J8's follow-up exclusion (8.4) is needed, not a precaution.** The follow-up engine is on, so a staff
  conversation can be picked up for a follow-up today.
  - **Not measured:** whether a follow-up was ever drafted for, or sent to, a staff number. `followup_auto_send`
    was not read.
  - J8 stops it either way.
- **A second-site KYC job is created unassigned** on Uganda: both settings are unset and no support leader holds a
  uCRM id. Under J4 nobody is messaged until someone assigns it in uCRM; then the assignee gets one message. Nothing
  more is needed.

---

## 14. Not in J1–J8, noted on the way

- **J9–J16** stay out of scope, as you decided: WhatsApp commands, arrival and failed-visit, photos to uCRM, the
  admin job board, the daily summary, confirmations to the right person, and retiring path A.
  - J4 does take over one half of J15: a new time now goes to the assignee, not to whoever pressed Reschedule.
  - **The daily summary** keeps failing harmlessly at 07:00 (J14).
- **The wallet top-up's organisation-7 invoice** (`includes/post/post_admin.php:~550`, `post_sales.php:159`) fails
  on Uganda, and its screen still says "CRM invoice created". With 1.8, it stops asking uCRM. Its wording is a
  wallet matter, left alone here.
- **The API's session fallback** (`includes/api_handlers.php:76-85`) serves every action from a cached copy. J6
  re-reads the account for job actions only. Doing it for every action is a wider change for later.
- **The New Job date field** defaults to today in UTC (`tabs/support/scheduling.php:1750`), which reads as yesterday
  between midnight and 03:00 in Kampala. Cosmetic, J10 territory.
- **The security points of docs/43 §9.2 stand:**
  - no sign-in attempt limit on the app;
  - a predictable import password;
  - staff delete without a CSRF check, reusing ids;
  - Splynx defaults in `public.php` — rotate that key and secret.

---

**Nothing will be coded until you approve this specification and answer D1–D8.** The §13 check has run; its
result is in §13.1.
