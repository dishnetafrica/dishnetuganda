# 44 — J1–J8: staff identity, job notifications and permissions — implementation specification

27 September 2026. **A specification, not an implementation.** Nothing here has been coded, deployed, migrated or
configured. On 27 September you approved J1–J8 for **planning** (docs/43 §9.2), and asked for this document before
any code. **Coding waits for your approval of it.** J9–J16 are out of scope.

> **Update, 27 September: release A (5.18.50) is deployed.** The operator deployed it at 20:08 UTC, and the deploy's
> own checks PASSED: 41 ok, 0 failed, 1 note (§16.9). §1–§15 remain the specification and the decisions as recorded.
> **§16 is the build report**, written before the deploy and kept as written; **§16.9 records the deploy.** Release B
> stays BLOCKED (§15.8).

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

**§15 adds D9, P1 (who takes jobs) and amendments M1–M7, with the readiness assessment. Your decisions are
recorded in §15.6; release B is blocked (§15.8).**

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

- **The number field becomes a picker of Uganda's uCRM users** (`GET users/admins`), each shown as `#id` with its
  name, or with its UISP username when uCRM holds no name, and active or inactive. The UISP Users screen on 27
  September showed no first or last name for either user:
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
  - **Correction (§15.7):** the My Jobs list does read it, as a fallback for an account with no uCRM link
    (`api_scheduling.php:70, 73, 84, 141`). Release A removes that fallback on Uganda.
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
| 2 | **Create a uCRM user for each technician** in UISP/uCRM, with the same e-mail as their staff account (docs/43 §11.3). Retailers: none. The technician works in My Jobs, not in uCRM: the account only has to exist so a job can be assigned to it. **Started 27 Sep:** one UISP user created, for S4. The §13 check at 16:47 UTC confirms it: uCRM user **1099**, active, with S4's e-mail (§13.1) | you | no |
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

**Second run, 16:47 UTC**, after the operator created a UISP user for S4 (a technician). It ran to its end: 3 ok,
0 failed, 5 notes; nothing was changed.

| Question | Measured |
|---|---|
| uCRM staff users | **Two**, both active and linked to a UISP user: U1 = 1000 (S1's e-mail), and **U2 = 1099 (S4's e-mail)** |
| A phone in either record | None. `users/1099` also answers 404 |
| What a verified picker would propose | **S1 → 1000** and **S4 → 1099**. S2, S3 and S5: no uCRM user has their e-mail |
| Stored ids that are real | Still 0 of 4. S4 still holds 81 until it is linked |
| Everything else | As at 16:37: uCRM writes +0300, the follow-up engine is on, and receipts are subscribed on all three numbers |

A new UISP user is **active in uCRM at once**, as J2's "active" rule requires. It carries no phone, as J4 expects.

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

## 15. Decisions and readiness, before approval — 27 September

You asked for the decisions and a readiness assessment before approving anything. Your advisor's point is taken as
the first step: **decide who takes jobs, then decide the rest.** Nothing here changes the server. Three things are
new since §0:

- **D9**, a choice J2 had made for you;
- **P1**, who takes jobs;
- **five amendments, M1–M5**, found while mapping your acceptance tests to this specification.

### 15.1 The decisions

| # | Decision | Recommended | Why | What it changes for the business | Needed before |
|---|---|---|---|---|---|
| **P1** | Who will be assigned jobs? | S4: yes (uCRM user 1099 exists). S1: for the live test, and if you take jobs yourself (1000 exists). **S3 and S5: yours to decide.** S2 and retailers: no | Only people with a uCRM user can hold a uCRM job | Each "yes" needs a UISP user with the same e-mail (§15.3) | step 4, the links. It does not block release A |
| **D1** | One release or two? | **Two** (§0) | A adds no message. B starts sending only once the right people are linked | Between A and B, job messages behave as today. **No real jobs until the live test after B** | release A |
| **D2** | South Sudan byte-identical? | **Yes** | Your rule | South Sudan keeps today's holes until you decide otherwise: any signed-in account can act on any job, Clear Cache rewrites staff rows, and the engineer list returns phone numbers. Release B leaves two empty tables there | release A |
| **D3** | Tell a technician when a job is taken away or deleted? | **Yes, one short message** | Otherwise someone told "tomorrow 09:00" may still go | One extra message per reassignment or deletion. With a single technician it rarely happens | release B |
| **D4** | Remove "Notify via WhatsApp" from ＋ New Job on Uganda? | **Yes** | uCRM's `job.add` is a second route that knows nothing of the box | Every assignment is announced. No silent jobs | release B |
| **D5** | The wording (§5.6) | **Approve, or edit** | Every technician reads it | The title, address and time are uCRM's. The customer's name appears because My Jobs writes it into the title. A job typed in uCRM's screen shows what was typed there. The address is the one on the job, which My Jobs copies from the customer's street address, not from a service's installation address | release B |
| **D6** | Hide "CRM LINKED" and "⚠ No CRM link" on Uganda? | **Yes** | They count organisation 7, which does not exist here | The Staff page shows the J2 uCRM badges instead | release A |
| **D7** | May `support_engineer` create jobs? | **Yes** | The third technician role | None today: no Uganda account holds it | release A |
| **D8** | Set `tenant_profile = uganda` explicitly? | **Optional** | It keeps the switch independent of the currency setting | **None visible.** The profile already resolves to uganda by UGX (measured), and the sign-in page's phone example is the same string either way (`+256 7XX XXX XXX`, checked in the code) | no release |
| **D9** (new) | Link only to the uCRM user with the **same e-mail**? | **Yes, on Uganda** | As written, J2 proposes the same-e-mail user but lets an admin choose another. The UISP users carry no first or last name, so a manual choice is made by number and username alone. Your J2 said "match by e-mail" | Each technician's UISP e-mail must equal their staff e-mail. S1 and S4 already do (measured) | release A |

If you choose **one** release (D1), every decision except D8 is needed before it.

**Business decisions**, yours alone because they change what people receive, see or may do: P1, D2, D3, D4, D5,
D6, D7 and D9.

**Technical decisions**, which you can approve as a set:

- D1 and D8.
- The choices this specification already makes:
  - one Uganda switch;
  - migration 075, with the claim written before the send in one transaction;
  - the number taken only from the staff account;
  - class `staff` on the support number;
  - `+0300` to uCRM;
  - the caller and the job re-read on every job action;
  - staff recognised by the whole number;
  - delivery receipts marked unavailable.
- **One of them has an operational consequence: a failed job message is not retried automatically.** It stands in
  the Message Log, the failure queue (retry by hand) and WA Events.

### 15.2 Amendments found while mapping the acceptance tests

| # | What | Why | Where |
|---|---|---|---|
| **M1** | **J8 must also stop follow-ups by the number, and at the run and send steps** | As written, only the scan checks the category. The six staff conversations become `staff` only at their next message, and a follow-up opened before the deploy continues: the run's gate (`cron/followup_run.php:79`) and the send (`cron/followup_send.php:76, 82`) re-check the opt-out and "a colleague took over", but not the category. The fix: the scan (`followup_scan.php:86`), the run and the send each also check the number against active staff accounts, and a follow-up already opened for such a number is closed as "a colleague's number". No schema | release A. New test **T8.8**: an uncategorised staff conversation is not opened; an open follow-up is closed, never sent; a customer's follow-up is unchanged |
| **M2** | **A test of the message's content** | Your acceptance test 3 had none. **T4.13**: the title and address equal uCRM's job exactly; no address gives no address line; no value comes from the browser | release B |
| **M3** | **Live test step 2a**: change only the title in uCRM | It proves an edit that is neither the person nor the time sends nothing | the live test |
| **M4** (optional) | **A silent test job after the links** | It gathers V2, V3 and V4 before release B can send anything (§15.5, stage 5) | stage 5 |
| **M5** | **Clear the stale ids of people who will not take jobs** (S3's 4, S5's 1581) at step 4 | §3.2 says J4 cannot message anyone through them. That is true today, not for ever: **1581 is above every uCRM user number so far (1000, 1099)**, so uCRM could one day give it to a new user. S5 would then receive that person's job messages and could act on their jobs | step 4, by an admin, with the picker's "— not linked —" |

### 15.3 Accounts, matching and correction

- **Minimum accounts.**
  - One UISP/uCRM user for each person who will be assigned jobs, with the **same e-mail** as their staff account,
    active.
  - The technician never signs in to UISP; they work in My Jobs.
  - **Not measured: the narrowest rights a user may have and still be assignable.** S4's user was created with
    Read-only unticked, which is more than a technician needs.
  - A zero-change check: uCRM → Scheduling → new job. See whether the user is in the assignee list, then cancel
    without saving. Narrow S4's rights and look again.
- **Matching** (J2 with D9): the e-mail trimmed and lower-cased, and exactly one match. The server then checks, at
  every save:
  - the user exists (`users/admins/{id}` answers 200, measured for 1000 and 1099);
  - active;
  - not linked to another active account;
  - a job-taking role;
  - with D9, the same e-mail.

  A refusal gives the reason and keeps the old value.
- **Correcting the wrong ids.**
  - Only an admin's verified save changes a link, and only after release A. Before it, the South Sudan lists force
    S1 and S5 back.
  - S1: 1 → 1000. S4: 81 → 1099.
  - S3 and S5: their own uCRM user, or "— not linked —" (M5).
  - Until then the badge says "not a uCRM user", and New Job does not offer them.
  - The users check, run again, proves every stored id is real.

### 15.4 Readiness

| Area | State |
|---|---|
| Specification | Complete for J1–J8. Every line checked against the installed 5.18.49 (`e076632`). Plus M1–M5 |
| Evidence | V1 done. **V2, V3 and V4 wait for the first job** (M4 gets them before B). V5 is outside J1–J8 |
| People | uCRM users exist for S1 (1000) and S4 (1099). **S3 and S5 undecided. S1 has no number** |
| Code | None written |
| Server | Unchanged: 5.18.49 |

**Verdict.**

- **Release A** can be built once P1 is known and D1, D2, D6, D7, D9 and M1 and M5 are answered.
- **Nothing may send** until release B, which also needs D3–D5, the links, and preferably M4's evidence.

### 15.5 Stages, and the evidence each needs

| Stage | What | Who | Sends | Evidence before moving on | Rollback |
|---|---|---|---|---|---|
| 0 | read-only checks | you | no | **done**: 15:54, 16:37, 16:47 | — |
| 1 | decisions | you | no | your answers to P1, D1–D9 and M1–M5 | — |
| 2 | build release A (5.18.50) | me | no | full suite twice, 0 failed; each weakened copy caught; South Sudan golden byte-identical; the deploy command rehearsed twice, 0 failed; the pinned commit and ZIP digest | — |
| 3 | deploy A | you | no | log file: backup taken; the switch reads uganda on the live install; gates present; staff links identical before and after; Message Log count unchanged by the deploy | the previous plugin from the backup. **The South Sudan lists re-arm** |
| 4 | accounts and links | you | no | UISP users (same e-mail, narrow rights, in the assignee list); links via the picker; S1's number. Then the users check: every stored id real. A day later the facts command: the links survive; the last AI reply queued for a staff number is older than the deploy; no follow-up for a staff number (one read-only line the command gains) | re-link with the picker |
| 5 | (M4, optional) one internal job: no customer, box unticked, assigned to 1000. Change its time in uCRM, then delete it | you, after approval | **nothing from this plugin**: the box is unticked, path B's lookup answers 404 (measured) and no customer is on it | the facts command: the job's detail carries `assignedUserId` (V4); uCRM showed the typed hour (V3); the webhook log shows `job.add`, `job.edit` and `job.delete` (V2) | the deletion is part of the stage |
| 6 | build release B (5.18.51) | me | no | D3–D5 answered; suite twice; T4.1–T4.13; the trace's B1, A6 and A7 prevented; rehearsed twice | — |
| 7 | deploy B | you | no | log file: 075 applied, both tables empty, Message Log unchanged by the deploy | the previous plugin; the tables stay, ignored; path B never sends, so no duplicates |
| 8 | the live test (§12.3 + M3) | you, after approval | 2–3 messages, to you | log file: exactly the expected rows and events, nothing else | the previous release |

**Not known, for any stage:** whether uCRM itself e-mails the assigned user, and whether another system subscribed
to uCRM's webhooks reacts to a job. The stages prove this plugin's own sends.

### 15.6 Your decisions, recorded 27 September

You approved the two-release approach and answered §15.1–§15.2 as follows.

| # | Your decision |
|---|---|
| **P1** | **S1: yes**, for the internal test and administration; it gets a valid number before any WhatsApp test. **S4: yes**, after release A and a verified link to 1099. **S3 and S5: no for now**; they get no jobs until you confirm. S2 and retailers: no. **No account is created, and no job assigned to anyone else, automatically.** S3 or S5 can be enabled later through the linking process |
| D1 | Yes: two releases, A and B |
| D2 | Yes: South Sudan unchanged |
| D3 | Yes: the previous technician is told when a job is reassigned, removed or deleted |
| D4 | Yes: "Notify via WhatsApp" is removed on Uganda |
| **D5** | **Pending.** You see the exact messages for a new assignment, a reassignment, a new time, a removal and a deletion before release B is built |
| D6 | Yes |
| D7 | Yes, under the server-side checks |
| D8 | Yes: `tenant_profile = uganda`. It is set by your own command (R5), as a step of its own in release A's runbook |
| D9 | Yes, enforced on the server |
| M1, M2, M3 | Approved |
| M4 | Approved, after release A. One test job with no customer, assigned to S1 (1000). Check in uCRM that the time is Kampala's and the assignment is right; check in the webhook log that the edit and the deletion arrived; then delete it. **No job-assignment message during the test** |
| M5 | Approved: only S3's and S5's ids are cleared. **No other link is cleared or overwritten automatically** |

**Release and safety requirements, recorded:**

1. Release A is built only after this record. Nothing is deployed automatically.
2. The full suite runs twice, South Sudan regression tests included, with zero failures.
3. A verified backup, and the deploy and rollback commands, are handed over.
4. After release A, S1 → 1000 and S4 → 1099 are verified. No link changes silently.
5. The internal test verifies which staff account, and which number, would be used.
6. Release B is not built or deployed until you approve the exact wording, the links are verified and the tests pass.
7. In the live test, messages go only to your own number: no duplicates, no other recipient.
8. Customer billing, invoices, the portal and unrelated features are not changed.

**A passing automated test is not proof that WhatsApp delivers.** No job message has ever been sent on Uganda
(§11). Release B's live test, on your own phone, is a gate of its own.

### 15.7 What still blocks release A

**Two confirmations**, found while preparing it.

- **M6 — release A sends no job-assignment WhatsApp on Uganda.** As specified, the old sends go only in release B
  (§5.2). Until then:
  - ＋ New Job sends when its box is ticked (`api_scheduling.php:625-643`);
  - Bulk Dispatch sends with no box at all (`:990-1014`);
  - Reschedule messages whoever pressed it (`:686-697`);
  - uCRM's `job.add` block (`webhook.php:2408-2457`) cannot send today only because uCRM answers 404 (measured).

  Once S4 is linked, a job made in My Jobs could reach S4 in the old, unapproved wording.

  **Recommended:** on Uganda, release A switches off all four and hides the box, so D4 applies from release A. The
  New Job answer then says that no WhatsApp was sent. Accept, task and completion messages stay as today; they need
  someone to act on an existing job. South Sudan: unchanged.
- **M7 — only a verified link counts.** Your advisor's point is that S3's and S5's old ids must not match a future
  uCRM user. M5 clears them by hand after release A.

  **Recommended in addition:** on Uganda, only a link saved through the verified picker counts, wherever a job is
  involved:
  - the My Jobs list;
  - ＋ New Job;
  - every job action (J6);
  - in release B, the recipient (J4).

  An old id then matches nobody, whether or not it has been cleared, and nothing stored changes.

**A correction, inside what you approved.** §3.2 said nothing here reads `ftth_crm_client_id`. The My Jobs list
does.
- An account with no uCRM link is shown the jobs assigned to its `ftth_crm_client_id`
  (`api_scheduling.php:70, 73, 84, 141`).
- Your J2 said never use it, so release A removes that fallback on Uganda.
- The Staff page's "CRM LINKED" tile showed no account holding one, so nothing visible changes.

**For requirement 8.** One approved J1 change sits beside an invoice path.
- 1.8 stops the plugin asking uCRM for an organisation-7 client for staff and retailers.
- The retailer wallet top-up's uCRM invoice needs that client, and fails today because organisation 7 does not
  exist. Its outcome and its screen stay the same.
- It is kept as approved; say if you want it left out.

**Nothing else blocks release A.** Around it, in this order, each step yours:

1. D8, then the read-only users check (in the runbook);
2. the deploy;
3. S1's number; the links S1 → 1000 and S4 → 1099; S3's and S5's ids cleared (M5); the users check again;
4. M4.

### 15.8 Release B — BLOCKED

Release B is neither built nor deployed until **all** of these hold, and then only on your separate approval:

1. **D5:** you approve the exact messages;
2. release A is deployed and verified;
3. the users check shows S1 → 1000 and S4 → 1099, and S3's and S5's ids cleared;
4. S1 holds a valid number;
5. M4 is done, with no job message sent, and V2, V3 and V4 recorded;
6. release B's tests pass: the full suite twice, South Sudan regression included, zero failures.

**Its delivery gate is the live test** (§12.3 with M3), on your own number only. The log file must show exactly the
expected messages, no duplicate and no other recipient.

### 15.9 Final decisions for release A — 27 September

You approved M6, M7 and the J2 correction, and set a strict exclusion. Release A is to be built and **not
deployed**: you review the build report first.

| # | Decision |
|---|---|
| **M6** | **Yes.** Release A sends no job-assignment WhatsApp on Uganda. All four paths are switched off: ＋ New Job, Bulk Dispatch, Reschedule and uCRM's `job.add`. The "Notify via WhatsApp" checkbox is hidden, and each answer says plainly that no message was sent. Accept, task-progress and completion messages are unchanged. South Sudan is unchanged |
| **M7** | **Yes.** On Uganda, only a link saved through the validated picker is used for job operations: My Jobs (the list and every action), ＋ New Job and Bulk Dispatch, and later release B's recipient. Never `ftth_crm_client_id`, and never an old, unvalidated id |
| **J2 correction** | Accepted: the My Jobs list's `ftth_crm_client_id` fallback is removed on Uganda |
| **Strict exclusion** | **Billing, invoices, payments, customer records and their workflows are not modified.** A cleanup that would touch them is proposed separately |

**What the strict exclusion removes from release A**, each to be proposed separately:

1. **1.8, 1.9 and 1.10.** These are the organisation-7 client creation for staff and retailers, the wallet top-up
   path that depends on it, and the staff-creation message about it. Unchanged.
2. **5.3, the second-site KYC job's time** (`lib/KycService.php:1937`). KYC is the customer-onboarding workflow, so
   it keeps today's `T09:00:00.000Z` (12:00 in Kampala). J5 still applies to ＋ New Job, Bulk Dispatch and
   Reschedule.
3. **J3 on save applies to job-taking roles only**: support, support leader, support engineer and admin.
   - Sales/retailer, field-agent, collection-agent, accountant and field-accountant accounts are saved exactly as
     today. Their numbers carry wallet, collection and invoice-queue messages.
   - J4 and J8 still read every staff number through J3's rule when they use it, which changes nothing stored.
4. **J8 recognises staff accounts only**: admin, accountant, field accountant, support, support leader and support
   engineer.
   - Dealer accounts (sales/retailer, field agent, collection agent) keep today's behaviour.
   - S1–S5, and the six staff conversations measured in §11, are all covered.

**Found while preparing, not changed** (they touch customer records or billing workflows):

- **`update_client_gps`** (`includes/api/api_crm_misc.php:120-150`) writes a customer's GPS position into uCRM. It
  checks for an assigned job only when the caller holds a uCRM link, so **an account with no link skips the check**.
- **`save_job_signature`** (`api_crm_misc.php:17-47`) writes a log to the uCRM customer named in the request body,
  never checked against the job. J6 limits who may call it; what it writes, and where, is unchanged.
- **The app API's job check-in and check-out** (`api/index.php:1446-1540`) have no assignee check, and check-out
  queues an invoice task. uCRM serves only `public.php` (docs/98), so whether this file is reachable is not measured.

**What J6 does touch, as approved.** It limits who may call three job actions that write to uCRM:

- the survey appends a note to the customer's uCRM record;
- the signature writes a customer log;
- a comment goes onto the uCRM job.

Only the assignee, a support leader or an admin may call them. What they write is unchanged.

---

**Your decisions are recorded (§15.6, §15.9). Release A is being built and will not be deployed without your
explicit approval of its build report. Release B is BLOCKED (§15.8).** The §13 check has run; its result is in §13.1.

---

## 16. Release A (5.18.50) — build report, 27 September

> **Deployed 27 September at 20:08 UTC by the operator: PASSED, 41 ok / 0 failed / 1 note (§16.9).** The report below
> is kept as it was written before the deploy.

**Built, tested and rehearsed. Not deployed.** Production still runs 5.18.49 (`e076632`) and nothing on the server was
touched. The deploy waits for your review of this report and your explicit approval. **Release B stays BLOCKED**
(§15.8).

| | |
|---|---|
| Plugin commit (what the deploy records) | **`125fa0c`** — 38 files against `e076632`: 20 changed, 18 added, 0 removed; +3,647 / −29 lines |
| Version | 5.18.50 |
| Deploy command | `scripts/deploy-5.18.50.sh`, pinned to `125fa0c`; refuses any other build, and any server not running `e076632` |
| Rollback | the same script with `--rollback` (back to `e076632`), or the documented `git checkout e076632 && bash scripts/deploy-hybrid.sh` |
| ZIP | none: this plugin has always been deployed from the pinned commit by `deploy-hybrid.sh` (the ZIPs of 5.18.42–5.18.49 were uCRM templates) |

### 16.1 What was built — Uganda only

Every Uganda line sits behind one switch, `lib/StaffJobsGate.php`, which is true only where the tenant profile reads
Uganda, and false on anything unclear. Its false branch is the 5.18.49 code, verbatim.

| Item | On Uganda, after the deploy | Files |
|---|---|---|
| **J1** | The South Sudan staff lists no longer touch a Uganda account: not on a page load, not on a version change, not through Clear Cache, auto-map or the old id setter | `public.php`, `includes/api/api_scheduling.php`, `tabs/support/scheduling.php` |
| **J2 + D9 + M7** | The Staff page's uCRM picker lists only real uCRM users, and saves a link only after checking on the server: the user exists, is active, is not linked to another active account, the account takes jobs, and the e-mail is the same (D9). **Only a link saved this way counts** for My Jobs, job detail, every job action, ＋ New Job and Bulk Dispatch. `ftth_crm_client_id` and an id stored the old way match nobody. The "CRM LINKED" / "⚠ No CRM link" badges are replaced (D6) | `lib/StaffLink.php`, `lib/UcrmUsers.php`, `lib/StaffDirectory.php`, `tabs/admin/retailers.php`, `includes/post/post_sync.php`, `includes/api/api_scheduling.php`, `includes/api/api_support.php`, `tabs/support/scheduling.php`, `tabs/support/bulk_dispatch.php` |
| **J3** | A job-taking account's phone is saved in the international form; every staff number is *read* that way; nothing stored is rewritten | `includes/post/post_sync.php`, `includes/post/post_admin.php`, `lib/StaffDirectory.php`, `tabs/admin/retailers.php` |
| **J5** | ＋ New Job, Bulk Dispatch and Reschedule send Kampala time to uCRM (`…T09:00:00+0300`) | `lib/JobTime.php`, `includes/api/api_scheduling.php` |
| **J6 + D7** | Only the verified assignee, a support leader or an admin may act on a job; a job whose assignee cannot be read is left to a leader or an admin; the caller is re-read on every action, so an account deactivated or demoted mid-session is refused at once; `support_engineer` may create jobs | `lib/JobAccess.php`, `includes/api/api_scheduling.php`, `includes/api/api_crm_misc.php`, `includes/api/api_support.php` |
| **M6** | **No job-assignment WhatsApp.** ＋ New Job, Bulk Dispatch, Reschedule and uCRM's `job.add` send none. The New Job box is hidden and the screen says *"No WhatsApp message is sent for jobs yet"*; New Job, Bulk Dispatch and Reschedule answer *"No WhatsApp was sent: job notifications are not switched on yet. The engineer sees the job in My Jobs."*; the webhook log says *"WhatsApp skipped: job notifications are not switched on yet"*. Accept, task-progress, completion and invoice-request messages are unchanged | `includes/api/api_scheduling.php`, `webhook.php`, `tabs/support/scheduling.php`, `tabs/support/bulk_dispatch.php` |
| **J7 (display)** | WA Events says "Sent (handed to WhatsApp)", never "Delivered", and explains that delivery is not measured; its menu badge counts what was sent and what failed (it read keys the log never writes); the Message Log says what "sent" means and counts an opted-out message as suppressed, not failed; a job message skipped by M6 is filed Skipped | `tabs/engage/failed_queue.php`, `tabs/engage/whatsapp.php`, `includes/navigation.php` |
| **J8 + M1** | A WhatsApp message from an active staff number (whole number, staff roles only) is not answered by the AI as a customer's; no follow-up is opened for it, and one already open is closed at the scan, the run or the send | `lib/ColleagueNumbers.php`, `evo_webhook.php`, `workers/AiReplyWorker.php`, `lib/FollowUpPolicy.php`, `cron/followup_scan.php`, `cron/followup_run.php`, `cron/followup_send.php` |

`manifest.json` says 5.18.50. The other 11 added files are tests and their fixtures.

### 16.2 Test counts — exact

**Full plugin suite (`tests/run.sh`), twice, on the final tree (`125fa0c`), South Sudan regression tests included:**

| Run | Files | Assertions passed | Failed | Exit | Duration |
|---|---|---|---|---|---|
| 1 | 223 | 10,422 | **0** | 0 | 994 s |
| 2 | 223 | 10,422 | **0** | 0 | 1,006 s |

**The eight new suites** (in both runs), each with weakened copies of the code that must make it fail:

| Suite | What it proves | Assertions | Weakened copies caught |
|---|---|---|---|
| `test_staff_jobs_gate.php` | the switch: Uganda by setting or by UGX, false on anything unclear, every call passes the data directory | 41 | 7 of 7 |
| `test_ucrm_link.php` | the picker, the server-side checks (existence, active, one account per user, role, same e-mail), J3's phone rule | 78 | 8 of 8 |
| `test_job_time.php` | J5: Kampala time out, Kampala time shown | 34 | 5 of 5 |
| `test_job_access.php` | J2/M7/J6: My Jobs, detail, every action, by role; stale and FTTH ids match nobody; a deactivated or demoted session is refused | 83 | 12 of 12 |
| `test_job_notifications_off.php` | M6: the four paths send nothing, say so, write nothing to the Message Log; the seven other messages are byte-identical to 5.18.49 | 47 | 7 of 7 |
| `test_staff_whatsapp.php` | J8/M1: staff numbers not answered, not followed up; open follow-ups closed; South Sudan unchanged | 50 | 10 of 10 |
| `test_job_status_truth.php` | J7: "sent", not "delivered"; suppressed and skipped counted | 23 | 5 of 5 |
| `test_staff_jobs_south_sudan.php` | **South Sudan byte for byte**: the same day on 5.18.49 (from Git) and on this tree — every answer, WhatsApp text, uCRM request, the Message Log, the webhook log, the e-mail, two forms and seven whole pages | 37 | 5 of 5 |
| **Total** | | **393** | **59 of 59** |

**Failures found, and what they were.** The first full run (before this report's runs) failed **8** assertions in
two suites. **Both were defects of the new test code; no product line changed to fix them:**

1. `test_timezone.php` (an existing guard: *no executable line pins a timezone*) caught my scenario fixture, which
   named `Africa/Kampala`. The guard exempts `test_*` files only. The zone now comes from the two test files that call
   the fixture — and South Sudan's runs now use **`Africa/Juba`**, which makes the South Sudan comparison stronger (a
   Kampala clock leaking into that path would now show).
2. `test_staff_jobs_south_sudan.php` failed on 7 pages: each tree prints its own version label (`v5.18.49` /
   `v5.18.50`), three times per page, after the version bump. The comparison now replaces exactly `v` + each tree's
   own manifest version; a new control proves the label is found three times on each Staff page and nothing else is
   normalised.

**Also checked:** the 37 changed PHP files use no syntax or function newer than PHP 8.0 (a token scan, with a control
that catches 7 planted post-8.0 features). The server's own PHP checks them again before a byte is copied (§16.5, A2).

### 16.3 Your checks (4) and (5)

- **The four Uganda job-assignment paths are off** — `test_job_notifications_off.php`, the same day run on 5.18.49
  and on 5.18.50: on 5.18.49 the four assignment messages go out (the control); on 5.18.50, **none reaches WhatsApp and
  none is in the Message Log**; New Job, Bulk Dispatch and Reschedule answer `whatsapp: not_sent` with the note; the
  webhook logs the skip; the New Job answer no longer claims "notified".
- **Messages that are not job assignments are unchanged** — the other **seven** WhatsApp texts of the day are the
  same text to the same number in the same order as 5.18.49: accepted, to the engineer and to the support leader; a
  task done; all tasks done; completed, to the engineer and to the admin; the invoice request, to the accountant. The
  Message Log rows match event for event, the customer's "installation scheduled" e-mail byte for byte, the webhook
  log line for line (the skip line apart); uCRM receives the same writes in the same order (the J5 dates apart).
- **The checked picker** — `test_ucrm_link.php`: only real, active uCRM users are offered; a save is refused, with
  its reason, for a missing user, an inactive one, one already linked to another active account, a role that takes
  no jobs, or a different e-mail; the old value is kept.
- **Role permissions** — `test_job_access.php`: the matrix of admin, support leader, support, support engineer,
  accountant, field accountant, sales/retailer, field agent and collection agent over list, detail, create, the
  engineer lists and every job action; each refusal changes nothing in uCRM (non-GET count and four local tables
  compared before and after).
- **Staff-link validation and the removed fallback** — the same suite: an account whose `ucrm_user_id` was stored
  without a verified link, one whose link e-mail no longer matches, and one with only `ftth_crm_client_id` all
  **see no job and may act on none**, although the job is assigned to that very id. **S3's and S5's stale ids** are
  exactly this case: they match nobody, now or when uCRM later hands the number to a new user (your advisor's note).

**What the tests do not prove.** They run against a fake uCRM and a fake WhatsApp gateway. **No test is proof that
WhatsApp delivers**; release A sends nothing new, and the live test of release B is a gate of its own (§12.3).

### 16.4 Scope — what changed and what did not

**Changed:** the 38 files in §16.1. **Not changed — by construction, and checked:**
- **Billing, invoices, payments, customer records and their workflows.** No changed file is under billing, invoice,
  payment, wallet, KYC, portal, cashbook, ledger, quotation, DPO, EFRIS or dunning code. Four files mix concerns and
  were read hunk by hunk: `public.php` (only the J1 gate), `webhook.php` (only the `job.add` block), `api_crm_misc.php`
  (only the job guard in front of the survey, signature and comment actions; what they write is unchanged),
  `post_admin.php` (only J3's phone note).
- **Excluded as you instructed:** 1.8–1.10 (organisation-7 client creation, the wallet top-up path, its message) and
  5.3 (the second-site KYC job's time). J3's save rule covers job-taking roles only; J8 covers staff roles only.
- **Found, reported, not changed** (they touch customer records): `update_client_gps` skips its job check for an
  account with no uCRM link; `save_job_signature` logs to the customer named in the request; the app API's job
  check-in and check-out have no assignee check (reachability unmeasured). Each can be proposed separately.
- **South Sudan:** unchanged, byte for byte (§16.2).
- **Scope changes against the approval:** none in the product. The two test-code fixes of §16.2 are the only
  changes made after the first full run.

### 16.5 Deployment, rollback and backup — rehearsed, not run

**Rehearsal** — `scripts/harness/deploy-5.18.50/rehearse.sh`, twice, on the committed script and harness (`e83f62a`):
**99 passed, 0 failed** and **99 passed, 0 failed**, 35 runs of the script each. It runs the real script, the real `deploy-hybrid.sh` and the real `git checkout` in a clone of the repository, against a sandbox
container holding 5.18.49 exactly, Uganda selected as on the server (UGX in the vault, no `tenant_profile`), and
stand-ins for the public address and the `:8443` door. DEPLOY and ROLLBACK are typed through a terminal. It covers:
- **NO-GO before anything changes:** the tenant reads South Sudan; only one configuration source reads Uganda; the
  server runs another build; an edited checkout; a database copy that arrives changed; anything but `DEPLOY`;
- **the server's PHP rejecting a changed file:** NO-GO before the backup; a rejected *test* file is a note only;
- **the deploy:** PASSED, six backups (two databases, the data directory, the plugin's `data/`, the installed plugin,
  the configuration vault), every one of the 38 files installed as `125fa0c` has it, no data or setting changed, and
  the log carries no staff name, e-mail or number;
- **later runs:** an accept message since the deploy is counted, a job-assignment message fails R4 by name; a link
  saved through the picker is a note, not a failure; a changed file, a tenant no longer Uganda, a fatal in the log —
  each caught;
- **the rollback:** PASSED; the 20 changed files back exactly as `e076632` has them; the 18 new files left in place and
  inert; the checkout returned to the branch; no data changed; a second `--rollback` says there is nothing to do;
- **forward again**, **back by the printed one-line command**, and **forward with a staff account edited during the
  deploy** (R3 fails, naming only the account id);
- **stage V finds the public address redirecting:** it rolls back by itself;
- **12 weakened copies of the script**, each caught.

**Rehearsal failures on the way — all in the harness, none in the deploy script:**
1. The first attempt passed 93 of 99: **6 weakened copies never ran**, because the harness handed `--script` to the
   real script after another option, which the real script refused as unknown. Fixed in the harness, which now also
   fails any weakened copy that did not run. All 12 are caught since.
2. The first evidence pair gave 99/99 and **98/99**: the one failure was the last check, *this checkout was never
   touched*, which saw this report being written in the checkout while the run went on. The check now compares the
   checkout before and after the run (commit `e83f62a`); the two runs above were made on that commit without
   touching the checkout.

**The commands, for when you approve** (as root; send back the **log files**, never a copy of the terminal):

0. D8, your own setting (optional, approved): make the switch independent of the currency —

   ```
   docker exec -u $(stat -c %u:%g /home/unms/data/ucrm/ucrm/data/plugins/dishnet-hybrid-sudan) -w /data/ucrm/data/plugins/dishnet-hybrid-sudan ucrm php tools/set_config.php --key tenant_profile --value uganda
   ```
1. The read-only users check (§13), before:

   ```
   cd /opt/dishnet && git pull origin claude/study-this-jhe2eg && mkdir -p /root/dnb-jobs && bash scripts/dnb-ucrm-users-facts.sh 2>&1 | tee /root/dnb-jobs/ucrm-users-$(date -u +%Y%m%dT%H%M%SZ).log
   ```
2. **Backup and deploy** — the script takes the backup itself, prints GO, and deploys only when you type `DEPLOY`.
   **A backup alone:** the same command, answering anything else — the backup is taken, nothing is deployed
   (rehearsed):

   ```
   cd /opt/dishnet && git pull origin claude/study-this-jhe2eg && mkdir -p /root/dnb-5.18.50 && bash scripts/deploy-5.18.50.sh 2>&1 | tee /root/dnb-5.18.50/deploy-$(date -u +%Y%m%dT%H%M%SZ).log
   ```
3. **Rollback**, if ever needed — the same backup first, then 5.18.49, when you type `ROLLBACK`:

   ```
   cd /opt/dishnet && mkdir -p /root/dnb-5.18.50 && bash scripts/deploy-5.18.50.sh --rollback 2>&1 | tee /root/dnb-5.18.50/rollback-$(date -u +%Y%m%dT%H%M%SZ).log
   ```
   By hand, if the script cannot run: `cd /opt/dishnet && git checkout e076632 && bash scripts/deploy-hybrid.sh && git checkout -`
4. **Later**, to re-measure since the deploy (no job-assignment message, staff accounts):

   ```
   cd /opt/dishnet && bash scripts/deploy-5.18.50.sh --after-only 2>&1 | tee /root/dnb-5.18.50/after-$(date -u +%Y%m%dT%H%M%SZ).log
   ```

**What the deploy checks** — stage A: the checkout is the pinned build, clean, and holds `e076632`; the server runs
`e076632`; **the installed plugin reads Uganda from both configuration sources** (NO-GO otherwise); **the server's own
PHP accepts every changed file** (NO-GO otherwise); the staff accounts and the Message Log are marked. Then the
backup, GO, `DEPLOY`. Stage V: the public pages and the `:8443` door as every deploy since 5.18.42 checks them, and
no fatal in the container log. Stage R: every changed file installed as `125fa0c` has it; the switch reads Uganda from
both sources; no staff account changed during the deploy; no job-assignment message since; the cron's `job_assign`
entry still off; the screens say no message is sent; how many accounts that take jobs hold a verified link. **Every
read opens the database read-only, as its owner. It changes no setting, no staff account and no job, and sends
nothing.**

### 16.6 Deployment risks

1. **My Jobs is empty for every technician until their link is saved (M7, by design).** No account can hold a
   verified link before the deploy: the picker is new. After the deploy, S4 sees no job until an admin saves S4 → 1099
   through the picker; S1 → 1000 likewise. A leader's or an admin's all-jobs view is unaffected. The deploy log's R7
   line counts it.
2. **V3 is not measured: uCRM has never been sent `+0300`.** J5 sends `2026-10-06T09:00:00+0300` where 5.18.49 sent
   the typed hour as UTC (`…T09:00:00.000Z`, which is 12:00 in Kampala) or, from Reschedule, with no zone at all.
   The offset form is ISO 8601 and the fake uCRM accepts it, but the real one has not been asked. **If uCRM refused it, ＋ New Job, Bulk Dispatch and Reschedule would fail on Uganda with
   uCRM's error shown**, and nothing half-written. M4 (approved, after release A) measures it with one internal job;
   the rollback is the remedy if it fails.
3. **A rollback re-arms the South Sudan lists.** On 5.18.49 the next Staff page load rewrites the ids they name
   (S1 → 1, S5 → 1581), which breaks a link saved for those accounts; after deploying again, save those links again.
   Job-assignment messages come back with 5.18.49 (M6 is undone).
4. **Your own number counts as staff under J8** once your account holds it: the AI will not answer it as a
   customer, and no follow-up opens for it. Testing the customer assistant needs a number that is not a staff
   account's.
5. **The server's PHP version is not measured.** The static scan found nothing newer than PHP 8.0, and stage A2
   refuses to deploy if the server's PHP rejects any changed file. **Measured at the deploy (§16.9): PHP 8.1.34.
   It accepted all 26 changed files that run on the server and all 11 test files.**
6. **Stage V4 watches the container log for 60 seconds.** The staff pages are exercised only when staff use them.
7. **The first page load after the deploy empties the jobs cache**, as on every version change; My Jobs refills
   from uCRM.
8. **After a rollback the 18 new files stay on disk** (`deploy-hybrid.sh` never deletes); the rehearsal proves no
   5.18.49 file loads them.
9. **The all-jobs list** (`scheduling_jobs_all` in `api_crm_misc.php`, leaders and admins) still checks the
   session's cached record: a leader demoted mid-session keeps that read-only list until the session ends. Every job action re-reads the
   caller (J6/D7).
10. **Not known, as before (§15.5):** whether uCRM itself e-mails an assigned user, and whether another system
    subscribed to uCRM's webhooks reacts to a job.

### 16.7 Release B — the five messages for D5 (for approval; nothing built)

Release B is **BLOCKED** (§15.8). For D5 you asked to see the exact messages first. Proposed, built only from
uCRM's job and the staff directory; made-up people; Kampala time; a job with no time says "📅 Not scheduled yet":

**1. A new assignment** (to the technician given the job — also the new technician of a reassignment):
```
Hi *Grace*,

🔧 *New job for you*

*Starlink installation — Test Client*
📍 Plot 1 Test Road, Kampala
📅 *Tue 06 Oct* at *09:00*

Open *My Jobs → Job #950* for the details and the tasks.

— DishNet Africa
```

**2. A reassignment** (to the technician who no longer has it, D3):
```
Hi *Peter*,

↩️ *Job #950 is no longer assigned to you*

*Starlink installation — Test Client*
📅 Tue 06 Oct at 09:00
It has been given to a colleague. Please do not go.

— DishNet Africa
```

**3. A new time** (to the technician who has it):
```
Hi *Grace*,

📅 *Job #950 has a new time*

*Starlink installation — Test Client*
Now: *Wed 07 Oct* at *11:00*
Was: Tue 06 Oct at 09:00

— DishNet Africa
```

**4. A removal** (the job stays, with nobody assigned, D3):
```
Hi *Grace*,

↩️ *Job #950 is no longer assigned to you*

*Starlink installation — Test Client*
📅 Tue 06 Oct at 09:00
Please do not go.

— DishNet Africa
```

**5. A deletion** (the job is deleted in uCRM, D3):
```
Hi *Grace*,

❌ *Job #950 has been cancelled*

*Starlink installation — Test Client*
📅 was Tue 06 Oct at 09:00
Please do not go.

— DishNet Africa
```

Open for release B's build: whether uCRM's deletion notice still carries the title and time (V2). If it does not,
message 5 takes them from the plugin's own record of the job.

### 16.8 What happens next

1. **You review this report.** Nothing is deployed until you approve it explicitly.
2. On approval, the order is §15.7's: D8 (optional), the users check, the deploy, then the links (S1 → 1000,
   S4 → 1099; S3's and S5's ids cleared, M5) and the users check again; then M4.
3. Release B only after D5 and the rest of §15.8, on your separate approval.

### 16.9 The deploy — 27 September 2026, 20:08 UTC

**PASSED: 41 ok, 0 failed, 1 note.** `125fa0c` over `e076632` (5.18.49), deployed by the operator; `DEPLOY` was typed
at 20:08:44 UTC. Recorded from the log files, which the operator printed on the server with
`tail -n +1 /root/dnb-5.18.50/*.log`: `deploy-20260927T200815Z.log` and the two rollback logs below. They carry no
name, e-mail, number or secret.

- **A.** The checkout at `87ba12f` (plugin commit `125fa0c`, no tracked edits); 38 files against `e076632`: 20
  changed, 18 added, 0 removed. The server ran `e076632`.
  - **The server's PHP is 8.1.34.** It accepted all 26 changed files that run on the server and all 11 test files
    (A2). Risk 5 is closed.
  - The installed plugin read Uganda from both configuration sources (A1).
  - The staff accounts (A3): 5 accounts, all active. Of the 4 active accounts that take jobs, all 4 hold a uCRM user
    id stored the old way and none a verified link; none holds only an FTTH id. Digest `b315fd023593b6a2`.
  - The Message Log (A4): 374 rows; the last is #374.
- **The backup**, `/root/dnb-5.18.50/backup-20260927T200815Z`:
  - `plugin.sqlite3`, 22 MB: `VACUUM INTO` as `1000:1000`, SQLite 3.48.0, integrity ok, 224 tables, the same sha256
    on both sides. There is no `dishnet.sqlite` in the data directory;
  - the data directory without the live databases, 99 MB, and the plugin's `data` folder, 120 KB;
  - **the installed 5.18.49 itself**, `plugin-installed-5.18.49.tar.gz`, 10 MB: a restore that needs no Git;
  - the configuration vault, 1,953 bytes, identical;
  - UISP health recorded; no tar note; `GO`.
- **B.** At `DEPLOY` the script read the Message Log's mark (#374) and the staff digest (unchanged) again and wrote
  them to `state-5.18.50.env`. `deploy-hybrid.sh`: *"✓ container now serves 125fa0c"*.
- **V.** All `ok`:
  - the public sign-in page answers 200 with no redirect;
  - the portal without a session answers 302, and its Location carries no `:8443`;
  - the Terms and Privacy pages are as checked since 5.18.42;
  - on `:8443`, pages answer 302 to the public address, `page=api` and the wrapper 200, and a POST 401 — never a
    redirect;
  - no fatal error of the plugin in the container log after the 60-second wait.
- **R.** All `ok`:
  - R1: all 38 files installed exactly as `125fa0c` has them; the manifest says 5.18.50;
  - R2: the switch is on from both configuration sources;
  - R3: every staff account as it was (digest `b315fd023593b6a2`);
  - R4: no job-assignment message since #374 — in fact no Message Log row of any kind;
  - R5: the master cron's `job_assign` entry is still commented out;
  - R6: the three screen texts are installed.
  - **The note, R7:** none of the 4 accounts that take jobs holds a verified link. **My Jobs is empty for all four
    until their links are saved** (risk 1).

**Two rollback runs followed. Both stopped at their question and changed nothing.**
- **The cause was my handover message in the chat.** It put the deploy command and the rollback command in one
  copyable block. Pasted together, the shell ran them in turn, so the rollback started at 20:09:47, as soon as the
  deploy ended.
- It took its own backup (`backup-20260927T200947Z`), printed `GO` and asked for `ROLLBACK`. The answer was not
  `ROLLBACK`, and it ended: *"STOP: not confirmed. Nothing further was done."*
- A second run of the rollback command, at 20:12:08, did the same (`backup-20260927T201208Z`).
- Each run's stage A read the server first. It found `125fa0c` (5.18.50) live and Uganda from both sources. The staff
  digest was still `b315fd023593b6a2`, and the Message Log still ended at #374: **no row of any kind was written
  between 20:08 and 20:12.**
- Rollback mode never writes `state-5.18.50.env`. A later `--after-only` run therefore still measures from the
  deploy's own mark (#374) and staff snapshot.
- The operator's `deploy-hybrid.sh --check` afterwards reads `live 125fa0c`, *"Up to date."* **Production runs
  5.18.50**, and the checkout is unchanged at `87ba12f`.

> **Binding from now on:** a deploy command and its rollback are never given in one copyable block, in the chat or
> in a document. The rollback goes in a block of its own, marked "only if needed". A pasted block is one input, and
> the shell runs every line in it in turn. What stopped this rollback, twice, was the typed `ROLLBACK`: a rollback
> must always ask for a word, never take a default.

**The backups.** Keep `backup-20260927T200815Z`: it holds 5.18.49's installed code and the data as they were before
the deploy. The two later ones hold 5.18.50's code and copies of the same data, taken minutes after. Nothing needs
them, and removing them leaves two fewer copies of the customer database:

```
rm -rf /root/dnb-5.18.50/backup-20260927T200947Z /root/dnb-5.18.50/backup-20260927T201208Z
```

**What this deploy does not show.**
- **That M6 holds in use.** R4's zero covers the run's own minutes, in which no job was created. It shows the deploy
  sent nothing. That New Job, Bulk Dispatch, Reschedule and a job from uCRM stay silent is shown by the tests
  (§16.2); on the server, M4 and the later `--after-only` run measure it.
- **That anything reached a phone.** The Message Log records what the plugin sent or suppressed. It is not a
  delivery report (J7).
- **The staff screens in use.** R6 read the installed files; nobody signed in. V4 watched the log for 60 seconds.
- **The suite on PHP 8.1.** It ran twice on PHP 8.4.19. On 8.1.34 the changed files pass the server's own syntax
  check (A2), and the token scan found nothing newer than 8.0 (§16.2). PHP 8.1 could not be installed in this
  session to run the suite on it: its package source is blocked by this session's network policy.
- **V3**, whether uCRM accepts `+0300` (risk 2). M4 measures it.

**What happens next** — each step yours, in this order:
1. **On the Staff page**, edit → uCRM user (the new picker):
   - **S1 → 1000** and **S4 → 1099**;
   - **S3 and S5 → "— not linked —"** (M5);
   - and **S1's phone number**, which release B needs. The AI then no longer answers that number as a customer
     (risk 4).
2. **The users check again**, then send its log file:

   ```
   cd /opt/dishnet && mkdir -p /root/dnb-jobs && bash scripts/dnb-ucrm-users-facts.sh 2>&1 | tee /root/dnb-jobs/ucrm-users-$(date -u +%Y%m%dT%H%M%SZ).log
   ```
3. **M4** (§15.5 stage 5, §15.6): one internal job with no customer, assigned to S1 (1000). In uCRM, check that its
   time is the one typed, in Kampala time (V3), and that the assignment is right (V4). Change its time, then delete
   it (V2). No job message may be sent.
4. **About a day later**, `--after-only`, then send its log file:

   ```
   cd /opt/dishnet && bash scripts/deploy-5.18.50.sh --after-only 2>&1 | tee /root/dnb-5.18.50/after-$(date -u +%Y%m%dT%H%M%SZ).log
   ```
   - R3 then names the accounts whose links were saved: a note, as expected.
   - R4 counts every job-assignment message since #374, and must read 0.

**Release B stays BLOCKED** (§15.8). It needs D5 (your approval of the five messages in §16.7), the users check
showing the links, S1's number, M4, and its own tests.

### 16.10 The links, and the users check — 27 September 2026, 20:31 UTC

The operator saved two links through the new picker, then ran the users check and sent a screenshot of the Staff page.

**Right:**
- **S1 → 1000** and **S4 → 1099**. Both stored ids are real uCRM users with the account's own e-mail (the users check,
  §3). Both links are verified: the picker shows S4's as *"#1099 — as saved (verified)"*, and it lists #1000 as
  *"linked to"* S1 and refuses it. It lists a user that way only when another active account holds a verified link to
  it (`StaffDirectory::byUcrmUser`).

**Not done yet:**
- **M5.** S3 still holds id 4 and S5 id 1581, and uCRM has neither user. Neither id counts for jobs (M7), and S3's
  card says so: *"uCRM #4 is not a uCRM user"*. Clearing them: Staff → Edit → CRM → *"— not linked —"* → Save
  Changes. That choice clears the id and the link, and asks uCRM nothing (`StaffLink::verify`: action `clear`).
- **S1's number.** S1's card reads *"No number: job messages cannot reach this person"*.

**The rest of the check, as expected:**
- No uCRM user record holds a phone number, so a job message's number comes from the Staff page.
- 2 of the 5 staff accounts have a uCRM user (S1, S4). S2, S3 and S5 need a uCRM user with their own e-mail before
  they can be given a job.
- uCRM writes its own timestamps at +03:00. That is not V3: whether uCRM accepts `+0300` when the plugin sends it is
  measured by M4.
- Delivery receipts are subscribed on all 3 WhatsApp numbers.

**One line of the check was wrong for 5.18.50, and is corrected.** Section 5 printed *"follow-ups can be drafted for
any conversation, a staff member's included"* whenever follow-ups are on. That was 5.18.49's behaviour, written into
the check before release A. On 5.18.50 the scan, the run and the send each skip a number held by an active staff
account on Uganda (J8, M1).
- The check now reads the installed crons themselves, never whether `lib/ColleagueNumbers.php` is on disk: a
  rollback leaves that file behind while the crons stop using it.
- On this install it will print: *"on — never for a number held by an active staff account (5.18.50, J8); an
  account with no number is not recognised"*.
- **Rehearsed: 413 of 413 checks on two runs** (394 before). It covers:
  - 5.18.50's crons;
  - the crons after a rollback, with the file left on disk;
  - a mixed install;
  - South Sudan.
- **14 weakened copies, each caught** (11 before). The three new ones key on the file instead of the crons, keep
  5.18.49's wording, or ignore the tenant.

**Next, each step yours:**
1. M5: S3 and S5 → *"— not linked —"* → Save Changes. Then add **S1's phone number**.
2. The users check again, with `git pull`, because the corrected check is on the branch. Send its log file:

   ```
   cd /opt/dishnet && git pull origin claude/study-this-jhe2eg && mkdir -p /root/dnb-jobs && bash scripts/dnb-ucrm-users-facts.sh 2>&1 | tee /root/dnb-jobs/ucrm-users-$(date -u +%Y%m%dT%H%M%SZ).log
   ```
   Expected:
   - S3 and S5 read *"stored uCRM id not set"*;
   - *"stored uCRM ids that are real uCRM users: 2 of 2"*;
   - section 5 prints the line above.
3. `--after-only` now too (read-only; the pull changes no plugin file, so it still finds `125fa0c`), and send its
   log file:

   ```
   cd /opt/dishnet && bash scripts/deploy-5.18.50.sh --after-only 2>&1 | tee /root/dnb-5.18.50/after-$(date -u +%Y%m%dT%H%M%SZ).log
   ```
   Expected:
   - R7: *"2 of 4 active accounts that take jobs have a verified uCRM link"*, with 0 holding an id stored the old
     way;
   - R3: a note naming the four accounts changed since the deploy;
   - R4: 0 job-assignment messages.
4. M4, as in §16.9; then `--after-only` again about a day later.
