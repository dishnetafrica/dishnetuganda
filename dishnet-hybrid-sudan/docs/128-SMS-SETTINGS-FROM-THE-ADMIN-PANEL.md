# 128 — SMS settings from the Admin panel

**Status:** review written **before** code, 2026-09-25 (§A–§E); **built in
development** (§F, commit `8d40936`); the staging command **built and
rehearsed** (§G); **DEPLOYED on staging 2026-09-25 06:38:19 UTC, first
attempt** (§I). The trigger was the
staging command of `docs/127` §L.2, which asked for an Africa's Talking account
the operator does not yet have. The operator's instruction was: *"i dont have
currently keep it configuratblae from ui i will add later"*. Nothing here is
HARDWARE VERIFIED, and F6-B stays NOT AUTHORIZED.

**Why.** Since `docs/127` phase 2, the SMS sender is chosen by the worker's
**environment**: `DN_SMS` plus `DNB_SMS_*`. The key reaches it by being typed
into the staging command on the server. The operator wants to enter it later, in
the Admin panel. That changes one binding rule, *"the SMS API key is typed on the
server"*: it may now also be typed into the Admin panel, over HTTPS, by a DishNet
Admin who has signed in with a password **and** an authenticator code. The rest
of the rule stands:

- never in chat, a log or the terminal;
- never in any response, audit row or error message;
- never shown again after it is typed.

---

## A. What exists — measured

- **The sender is chosen once, when the worker starts.**
  `SmsSenders::fromEnvironment()` reads `DN_SMS`:
  - unset → `NullSms` (nothing is claimed, and codes expire);
  - `africastalking` → the adapter, or a refusal to start without its username
    and key;
  - anything else → a refusal to start.

  A container's environment is fixed when it is created. **Changing the key today
  means recreating the worker** (`docs/127` J.1).
- **`SmsWorker::runOnce()` asks `isConfigured()` on every tick**, which is every
  second (`bin/worker.php`). A sender that can change its mind between ticks
  needs no change to the loop.
- **On staging the worker has no `DN_SMS`**, and logs `"sms":"null"`
  (`docs/127` §L.2).
- **The outbox and the worker's three SMS functions belong to `dnb_def_auth`**
  (migration 032).
- **`mt_audit_write` is EXECUTE-able by `dnb_def_prov`, `dnb_def_work`,
  `dnb_def_comm` and `dnb_def_staff`, and not by `dnb_def_auth`.** Measured on
  the development schema. Each grant was issued from `dnb_def_audit`'s own role
  block: 024 and 026 are the pattern.
- **Sealing exists.** `Dn\Crypto\SecretBox` is AES-256-GCM with associated data.
  `CodeEnvelope` derives its key from `DNB_SECRET_KEY` under a label of its own,
  so an envelope from one purpose does not open under another.
- **Both the Admin API and the worker hold `DNB_SECRET_KEY`.** On staging both
  read `runtime.env` (`docs/127` J.1).
- **`AdminReader` is an allow-list of 15 functions** (counted from the class,
  not from the documents, which number the estate projections differently), and
  the connection it uses, `dnb_adminapi`, holds no table privilege.
- **Capabilities:** `staff.manage` is Admin-only. No capability covers
  deployment settings.

## B. Decisions

| # | Decision | Reason |
|---|---|---|
| SS-1 | **One row in a new table, `mt_sms_settings`, created as `dnb_def_auth`.** That role already owns the outbox and the worker's SMS functions. **No login role holds any privilege on the table**, enumerated from `pg_roles`. No RLS: the table is not a tenant table, because the SMS account is DishNet's, not an operator's. Isolation is by privilege, as for `mt_staff` and the outbox | 032 and 026 are the precedent. Creating the table as its owner means migration 015's default privileges hand nobody anything |
| SS-2 | **The key never rests in clear, and never leaves the server again.** The Admin API seals it **before the database sees it**, with `SecretBox` under `HMAC(DNB_SECRET_KEY, 'dn-sms-settings-v1')`. That label is its own, so a settings envelope and an outbox envelope never open under each other. The associated data is `africastalking\|<username>`, so a key cannot be re-paired with another username by editing a row. The database stores the envelope and a **keyed fingerprint**, `HMAC(key)` under a second derived label, used for one thing only: telling an identical re-save from a real change | the phase-2 rule, *the code never rests in clear*, applied to the key |
| SS-3 | **Only the worker opens the envelope**, in memory, each time the settings change. It reads it through a function EXECUTE-able by **`dnb_worker` only** | the worker is the only thing that sends |
| SS-4 | **Nothing of the key is ever returned.** The Admin read gives `key_set: true/false`: not the envelope, not the fingerprint, not a fragment. No route returns it, and the form's key field is never pre-filled | the key's only journey is browser → API → sealed |
| SS-5 | **A new capability, `sms.manage`, held by the Admin role only**, for both reading and changing the page | it decides where every sign-in code goes and spends DishNet's credit. `staff.manage` is the precedent |
| SS-6 | **The write is `POST /api/v1/admin/settings/sms`**, through a new façade, `SettingsAdmin`, on `dnb_adminwrite`. It calls `mt_sms_settings_set(provider, username, sender, key_sealed, key_fp, actor)`: SECURITY DEFINER, owned by `dnb_def_auth`, EXECUTE **`dnb_adminwrite` only**. **W-1 audit** `sms.settings_changed` writes `actor_kind = 'staff'`, actor = the authenticated subject, `customer_id` NULL. The detail holds `provider`, `username`, `sender` and `key: replaced \| kept \| removed`, **never** the key, envelope or fingerprint. `dnb_def_auth` gains EXECUTE on `mt_audit_write`, from `dnb_def_audit`'s role block, because it now owns an audited mutation | the W-1 shape of every Admin write since 020 |
| SS-7 | **The function's rules.** `none` clears the username, sender and key. `africastalking` needs a username, and either a newly typed key or a stored key for **the same** username. **A username change without a new key is refused**: the stored key belongs to the old account, and its associated data would not open under the new name anyway. **Identical settings are a no-op, with no audit row** (RULE I-1, the domain-invariant class): the same provider, username and sender, with the same fingerprint or no new key | a double click must not write a false *key replaced* row that the append-only trigger then keeps forever |
| SS-8 | **The body carries exactly `provider`, `username`, `api_key` and `sender`.** Any other field is **400, refused rather than ignored**: `actor`, `updated_by`, `key_sealed`, `version`, `key_fp` and so on. **One validation rule, in one place** (`Dn\Notify\SmsSettings`), the same as the staging command's prompts: <br>• username `^[A-Za-z0-9_.-]{1,64}$`;<br>• key `^[A-Za-z0-9_-]{16,256}$`;<br>• sender `^[A-Za-z0-9 ._-]{1,15}$`, which is the adapter's own rule.<br>The username and sender are **also CHECK-constrained in the table**, a floor below the function | derive, never accept; the adapter already refuses a bad sender |
| SS-9 | **The worker picks up a change without a restart.**<br>• **`DN_SMS` unset** → the panel's settings. Every tick it reads one row's version, and rebuilds its sender **only when the version changed**. `none` means nothing is sent, which is phase 2's truth, unchanged. `africastalking` means the adapter, built from the opened key.<br>• **A setting it cannot use**, whether an envelope that does not open (say `DNB_SECRET_KEY` changed) or values the adapter refuses → **it sends nothing, keeps running and says so.** The same process runs the intents, which must not stop. That is failing closed, loudly, and not a fallback to another provider.<br>• **`DN_SMS` set** (`null` or `africastalking`) → **the environment wins**, exactly as in phase 2: `africastalking` without its variables still refuses to start. The panel's settings are ignored, and the page says so | a panel setting that needed a restart would not be "from the UI" |
| SS-10 | **An honest status, and no invented signal.** The worker reports what it is doing through `mt_sms_worker_report(version, state, detail)`, EXECUTE **`dnb_worker` only**. It reports on every change, and at most once a minute otherwise. The state is one of: **off · in use · unusable · environment**. The page shows three things:<br>• the settings: configured or not, the username, the sender, whether a key is set, and when and by whom;<br>• **the worker's view**: *in use since …*, *waiting for the worker* (the saved version is newer than the worker's), *cannot use it — <fixed reason>*, *set on the server (`DN_SMS`)*, or *no report yet*;<br>• **the outbox's own record** for the last 24 h, as counts only: sent, failed, expired unsent and unknown numbers. Also the last send the provider **accepted**, and the last refusal with its fixed reason. **No phone number and no code.**<br>*Accepted by the provider* is shown only from an outbox row the worker settled as sent, **never because a form was saved** | `docs/90`: no signal may be invented |
| SS-11 | **The Admin read is `mt_admin_sms_settings()`**: owned by `dnb_def_auth`, EXECUTE **`dnb_adminapi` only**, and added to `AdminReader`'s allow-list as its **16th function**. It returns nothing of the key | the list is closed; a new entry is a stated decision |
| SS-12 | **The doctor.** With `DN_SMS` set it behaves as today. With `DN_SMS` unset it reads the panel's settings through the Admin read: **WARN** *no SMS sender configured — set it in the Admin panel* when there are none; **OK** *set in the Admin panel; key set (value withheld)* when there are. The doctor never opens the key | the doctor reports what the worker will do |
| SS-13 | **Bound only under the real staff identity provider**, like `/staff`. The development identity gets **501** | the development identity may look at the estate, not route DishNet's sign-in codes |
| SS-14 | **Migration 033 verifies itself before committing**, as 032 does: the owners, SECURITY DEFINER, the exact EXECUTE grantees, no privilege on the table for anyone but its owner, and `dnb_def_auth`'s temporary `CREATE` revoked | 032's lesson: a `REVOKE` after `RESET ROLE` can change nothing, and only the verification noticed |

**Not built, deliberately:**
- **no test message**, because sending one needs a real phone and spends credit;
  the operator's first sign-in stays the proof (`docs/127` J-12);
- **no second provider**, and **no per-operator SMS account**;
- **nothing in the operator app**; `dnb_app` gains nothing;
- **the staging command's SMS prompt stays**: it writes the environment, which
  wins, and the panel then says so. **Corrected in §G:** once 033 is applied,
  the operator-app command (`docs/127` §J) stops at its first check, because it
  accepts only ledgers 30 to 32. On staging, the panel is therefore the way to
  set the account from now on. The rehearsal proves this refusal (S9).

## C. Evidence, per piece

| Piece | Label, once built |
|---|---|
| The settings path: route → façade → function → sealed row → worker → adapter | **MEASURED** in the suite, over HTTP, against a loopback fake provider |
| Every login role refused every new function except its own role | **MEASURED** by execution, with the roles enumerated from `pg_roles` |
| Africa's Talking itself | unchanged from `docs/127` §G.3: the request shape is **MEASURED** from the official SDK; the answers are **DOCUMENTED, UNVERIFIED**; delivery is **UNVERIFIED** until a real message arrives |

## D. Order

1. Migration 033.
2. The PHP: `SmsSettings`, `SettingsAdmin`, `PanelSms`, the routes, the doctor
   and the manifest.
3. The panel page and its own client.
4. The tests, and weakened copies of the migration and the code.
5. The suite twice, the install test and the package digest.
6. The staging command, rehearsed in a harness rebuilt with the real earlier
   commands.
7. The handover.

## E. What the operator will do, once deployed

When the Africa's Talking account exists:

1. Sign in to the Admin panel as an Admin.
2. Open **Administration → SMS for sign-in**, and enter the username, the API key
   and, optionally, a sender name.
3. Save.

The page then says *waiting for the worker*, and within seconds *in use*. The
first real sign-in on `app-staging` is the proof that a message arrives.

---

## F. The build (development) — commit `8d40936`

Everything in §B was built as decided. What follows records what differs from
the plan, what was measured while building, and the proofs.

### F.1 Migration 033

- `mt_sms_settings` is created under `SET LOCAL ROLE dnb_def_auth`, with one row
  (`provider = 'none'`, version 0). No RLS, no grant: **no role but its owner
  holds any privilege on it**, not even the installing owner `dnb`.
- The four functions (§B SS-6, SS-3, SS-10, SS-11) are SECURITY DEFINER, owned
  by `dnb_def_auth`, and granted exactly as decided, from inside the role block.
  `dnb_def_auth` gains EXECUTE on `mt_audit_write`, issued from
  `dnb_def_audit`'s own role block, and keeps no `CREATE` on the schema.
- **Measured while building: the first trial of 033 passed a check that the
  real install then failed.** The trial ran as `SET ROLE dnb` from a superuser
  session. The migration's own `RESET ROLE` then returned that session to the
  **superuser**, so its closing check could read a table the real owner cannot.
  Run as the owner itself (`psql -U dnb`), the same check failed with
  *permission denied for table mt_sms_settings*. **The row is now checked inside
  `dnb_def_auth`'s role block, and migrations are trialled as the owner
  directly, never through `SET ROLE` from a superuser.**
- The verification block refuses the commit unless the owners, SECURITY
  DEFINER, the exact EXECUTE grantees, the table's ACL, `dnb_def_auth`'s audit
  privilege and its missing `CREATE` are all as decided. It also refuses unless
  the installing role **cannot** read the table.

### F.2 The code

| Piece | What it does |
|---|---|
| `Dn\Notify\SmsSettings` | the one validation rule; `seal()`/`open()` under `HMAC(DNB_SECRET_KEY, 'dn-sms-settings-v1')`, associated data `africastalking\|<username>`; the keyed fingerprint under its own label. Refuses to exist without `DNB_SECRET_KEY` |
| `Dn\Admin\SettingsAdmin` | the façade on `dnb_adminwrite`: seals, fingerprints, calls the setter with the signed-in subject as the actor. A `22023` refusal becomes `SettingsRefused` with the function's own words; any other database error becomes a fixed message with the SQLSTATE only, **never the driver's text** |
| `AdminRoutes` | `GET` and `POST /api/v1/admin/settings/sms` under `sms.manage` (Admin only). Bound only under the real staff provider; otherwise **501 `sms_settings_unavailable`**. A body field other than `provider`, `username`, `api_key` and `sender` is **400**. The key is validated but never echoed |
| `Dn\Notify\PanelSms` | the worker's sender when `DN_SMS` is unset: reads the row's version every tick, rebuilds only on a change, reports on every change and at most once a minute otherwise. An unusable setting sends nothing and reports a fixed reason |
| `Dn\Notify\EnvironmentSms` | wraps the phase-2 environment sender when `DN_SMS` is set, and reports `environment` |
| `SmsSenders::forWorker()` | chooses between the two; an unknown `DN_SMS` still refuses to start |
| `bin/worker.php` | logs `"sms":"panel"` (or the environment's binding) at start, and one `sms_settings` line each time what it applied changes |
| `Doctor` | with `DN_SMS` unset it reads the panel's settings through the Admin read: WARN when none is set, OK *set in the Admin panel … value withheld* when one is, SKIP when 033 is not yet applied |
| the panel | `panel/settings.js`, a fifth client with exactly two calls; the page **Administration → SMS for sign-in**. The key field is `type="password"` and `autocomplete="new-password"`, is **never given a value**, and is emptied before the request is sent. The page shows the worker's own report and the outbox's 24-hour counts, and no number and no code |

`plugin/doc/INSTALL.md`, `plugin/.env.example` and the manifest describe both
ways to set the account and which one wins.

### F.3 Proofs

- **`tests/test_sms_settings.php` — 120 assertions**, in eleven sections:
  - the catalogue;
  - every login role, enumerated from `pg_roles`, refused the table and every
    function but its own, by execution;
  - sealing: another username, the raw master key, another master key and an
    outbox envelope each fail to open;
  - the function's rules, through the façade;
  - the routes, as Admin, NOC and anonymous;
  - **the worker following the panel**: a code queued before the save is sent
    by the **same worker object** on its next tick, with the key typed in the
    panel, to a loopback fake of the provider, and the code received signs the
    owner in;
  - a new key used by the next message, an unopenable key reported, and SMS
    turned off;
  - `DN_SMS` taking precedence, including in the worker process itself;
  - the doctor;
  - the Admin read's exact columns;
  - the panel's guards, the manifest and the capability.
- **21 weakened copies, each caught**:

  | Copy | How it was caught |
  |---|---|
  | setter granted to `dnb_admin`; table created as the owner | the migration refused itself |
  | the RULE I-1 no-op removed | 4 failures |
  | the username-change check removed | 4 failures |
  | the Admin read returns the envelope | 1 failure |
  | the audit row carries the fingerprint | 1 failure |
  | the worker's read granted to `dnb_app`, verification weakened | 3 failures |
  | the key stored unsealed | 8 failures |
  | the key echoed | 1 failure |
  | unknown fields ignored | 8 failures |
  | the worker never rebuilds | 9 failures |
  | the worker rebuilds every tick | 1 failure |
  | the environment's precedence removed | 3 failures |
  | NOC given `sms.manage` | 2 failures |
  | the driver's message rethrown | 1 failure |
  | the previous sender kept after an unusable change | 2 failures |
  | the key field pre-filled | 1 failure |
  | an extra path in `settings.js` | 1 failure |
  | the key field as plain text | 1 failure |
  | bound under the development identity | 1 failure |
  | the worker reports nothing | 6 failures |

  **One was first caught only by a crash.** With the key stored unsealed, the
  suite died on an uncaught *envelope did not open*. A crash is a failure, but
  it hides every later assertion, so each open is now a counted assertion. The
  copy then fails 8 counted assertions.
- **Nine guards in four other suites were rewritten to the new truth**, none
  deleted:
  - seven assertions pinning migration 032 now pin 033;
  - the manifest's surface string gains *SMS settings read-write (Admin)*;
  - `test_simulator.php` forbade the word `api_key` in the panel. The SMS form
    now has that field, exactly as the login form has a `password` field since
    026. The guard now forbids a credential-SHAPED assignment (with a control
    that the pattern matches `api_key: "…"`) and allows the word **exactly four
    times**, each place named. **Control on the control:** a planted
    `api_key: 'atsk_…'` in `settings.js` fails 2 of 103.
- **Suite 41 suites / 4,037 assertions / 0 failed, twice**, up from 40 / 3,906.
  install-test **86/86**.
- **Package: 149 files, content digest
  `2af800b7748fea0e7f11f17267c25e7283da964ca2997d39d696ae8d9c5a5c2d`**, built
  from a clean `git archive` of `8d40936`.

---

## G. The staging command — `scripts/dnb-staging-sms-settings.sh`

**One command, run as root on the server.** It builds commit `8d40936`,
**fetched by its hash**, and refuses unless the content digest is
`2af800b7…9c5a5c2d`. It applies exactly **033** on top of 032, verifies the
result independently, and restarts the API, the worker and the operator app on
the new build.

### G.1 Decisions

| # | Decision | Reason |
|---|---|---|
| G-1 | **No SMS question, and no secret handled at all.** | The operator's instruction: the account is entered later, in the panel. |
| G-2 | **The three containers are RESTARTED, not recreated.** | Nothing in their environment changes. A restart mounts the tree now at the path, which the 028–030 commands relied on and this server showed. Recreating a container is a chance to change its environment by mistake. |
| G-3 | **Step 0 refuses unless `DNB_SECRET_KEY` is the same in the API, the worker and the app.** The values are compared by hash and never printed. | The API seals the key and the worker opens it. With two different values, a key saved in the panel would never open, and the page would only say so afterwards. The rehearsal shows exactly that consequence (X4). |
| G-4 | **The execution tests run in one transaction that is always rolled back.** They cover a save with its one audit row, the same save again (no change, no row), a new username without its key (refused), the worker's read and report, and the Admin read. Then **every login role**, enumerated from `pg_roles`, is tested against the table and each of the four functions. | The catalogue cannot see a role membership. The execution test can (S7). |
| G-5 | **The worker's mode is read twice**: from its log since the restart, and from its report in the database. | The report is what the panel will show. |
| G-6 | **Step 0 accepts ledger 32 (the first run) or 33 (a re-run), and only the operator app's build or this one.** | Anything else is not a state this command knows. |
| G-7 | **The printed rollback puts the tree back first, then restarts the three containers. 033 stays.** | A container mounts the tree it is started on (`docs/127` §K). The previous build never reads 033's table. |
| G-8 | **The result tells the operator not to run the operator-app command again.** | Its step 0 accepts only ledgers 30–32, so after 033 it stops and changes nothing (S9). The panel is where the account is set from now on. |

### G.2 The rehearsal — `scripts/harness/sms-settings/`

The sandbox is rebuilt from nothing through the real 028 build, the real
staff-login switch, and **the real 029, 030 and operator-app commands, byte for
byte**. The last is the copy the operator ran (sha256 `1c27d06d…`), run with no
terminal so the SMS question is skipped, as on the server. The first Admin
sign-in (authenticator enrolled, password changed) is part of the setup. A
second starting state has the SMS key typed into the operator-app command, so
the worker holds `DN_SMS`.

| Scenario | What it proves |
|---|---|
| **S1** | the command on the server's state: 033 applied and verified; the four functions each granted to exactly one role; the rolled-back save, replay and refusal; every login role refused; residue 0; `"sms":"panel"`; the report `off` at version 0; the environments unchanged; the three containers on the new tree **by inode**; route files untouched |
| **S1b** | **the Admin's journey through Traefik on the deployed build**: sign in with password and authenticator code; the page shows nothing set; a body naming the actor is 400; the save answers 200 with no key; the row holds an envelope and no key; one audit row by `dishnet-admin`; the page says the worker has not caught up; **the deployed worker's next tick reports `in_use` with Africa's Talking**; the same save again changes nothing and writes no row |
| **S3** | the command again with an account set: already deployed, already applied, the probe *replaced* then *kept*, the saved setting untouched, the worker `in_use` after the restart |
| **S1c** | *Turn SMS off*: the key and account removed; the worker's next tick `off` |
| **S2** | `DN_SMS` in the worker's environment: the environment wins, the report says `environment`, and the key typed into the operator-app command appears nowhere |
| **S4a–e** | refused in step 0, nothing changed: before the operator app; the development identity; **another `DNB_SECRET_KEY` in the worker** (the value never printed); the F6-B gate open; an unknown deployed build |
| **S5** | a wrong digest: refused, no half-built tree left |
| **S6** | a default privilege planted so that 033 would hand `dnb_app` the table: **033 refuses by itself**, keeps nothing, and the tree goes back; the same command completes once the fault is removed (S6b) |
| **S7** | a role membership planted after the installer: the catalogue reads clean, and **the execution test names `dnb_app`** |
| **S8** | the printed rollback, run verbatim: the previous build back under all three containers by inode. **Control on the control:** in the wrong order, all three stay on the new build and the inode checks see it |
| **S9** | the operator-app command run again after 033: it stops at step 0 and changes nothing |

**Five broken copies of the script, each caught:**
- X1: the probe commits, and the script's own residue check stops it.
- X2: the per-role refusal checks are removed. With S7's membership planted, it completes, so S7's assertion would fail.
- X3: the app is not restarted. The script's container check stops it, and the app is left on the renamed tree.
- X4: the `DNB_SECRET_KEY` check is removed. It completes over the mismatch, and a key then saved in the panel is reported *does not open*.
- X5: the digest check is removed. It proceeds on a wrong pin.

**Two findings on the way:**
- The first run gave 147 passed, 5 failed. The script printed `allowed:  none` with a double space, a cosmetic fault now fixed in the script. The harness's own tree check matched every older `app.prev-*`; it now takes the newest by the stamp in its name.
- The next two runs stopped in the setup. The previous run's `app.env` and `app.ports` had travelled into snapshot 030, and restoring it started an app that did not exist at that point, holding port 8098. The setup now clears stale state, and a restore starts the app only if the snapshot's container list has one.

**Result: 152 passed, 0 failed, on two consecutive runs.**

### G.3 What it does not do

It adds no DNS record, writes no Traefik file, changes no firewall rule,
recreates no container and touches no production container. It sets no SMS
account and sends no message. `DN_ALLOW_REAL_BINDINGS` stays absent and router
delivery stays simulated.

## H. Handover

1. **Run the one command** on the server, as root:

   ```sh
   curl -fsSL -o /root/dnb-sms-settings.sh https://raw.githubusercontent.com/dishnetafrica/dishnetuganda/claude/study-this-jhe2eg/scripts/dnb-staging-sms-settings.sh \
     && sh /root/dnb-sms-settings.sh 2>&1 | tee /root/dnb-staging-evidence/sms-settings-$(date -u +%Y%m%dT%H%M%SZ).log
   ```

   The script rehearsed is sha256
   `089c806b4265c8978075c927f836f7826d5acb03a49abbadecf8f15012be94ba`.
2. **Send back the log file**, not the terminal. The command prints how.
3. **When the Africa's Talking account exists:** sign in to the Admin panel as
   an Admin, open **Administration → SMS for sign-in**, enter the username, the
   API key and, if you like, a sender name, and save. The page says *waiting
   for the worker*, then *in use*.
4. Then add an owner with your own number and sign in on the phone. **Say only
   what the screen shows: never the code, and never the key.**

## I. Result — DEPLOYED on staging, 2026-09-25 06:37:39–06:38:19 UTC

The operator ran the one command and pasted the terminal. That was safe this
time: the command prints no secret. **Every step passed on the first attempt,
with no correction.**

| Step | Observed |
|---|---|
| 0 | the operator app's build `2874d643…` deployed; the real staff login (trusted proxy `172.22.0.1`); the worker `simulated` with **no `DN_SMS`**; **one `DNB_SECRET_KEY` in the API, the worker and the app**; ledger `32|032`; O-1 `1|1|2|true` |
| 1 | commit `8d40936` fetched by hash; **149 files, content digest `2af800b7…9c5a5c2d`** |
| 2 | doctor 26 checks: 24 ok, 1 warn (*32 of 33 applied*), 0 blockers, 1 not measured (the SMS row, before 033 existed) |
| 3 | the installer applied **exactly 033**; 33 migrations recorded |
| 4 | the store is `dnb_def_auth`'s with no RLS, **no privilege held by anyone but its owner**, one row; **4 of 4** functions SECURITY DEFINER, each granted to exactly its role; `dnb_def_auth` may audit and kept no `CREATE`; the Admin read returns `key_set` only; 031/032 grants unchanged; O-1 unchanged. **The rolled-back probe:** `first\|true\|set`, `again\|false\|kept`, the refusal *a new username needs its API key typed again*, `audit\|1\|staff\|sms.settings_changed\|true\|true`, the worker's read, `report\|ok`, the Admin read. **8 login roles refused by execution, the owner `dnb` included**; residue 0 |
| 5 | panel 200, `settings.js` 200, the SMS page in the bundle; session 401 naming `dishnet`; **both SMS routes 401 anonymously**; the worker logs **`"sms":"panel"`** and `{"version":0,"state":"off","binding":"null"}`, and **its report in the database is `off` at version 0, written after the restart**; the three environments unchanged; the app unchanged; through Traefik 200, 401 and 200; **Traefik not restarted** (started 2026-09-15); both route files unchanged |
| 6 | **exactly the API, the app and the worker changed**; none removed |

**What this establishes:**
- On staging, the SMS account is now set in the Admin panel.
- **No SMS sender is set yet**, so no sign-in code is sent, as before.
- The worker follows the panel and reports what it is doing.

**What it does not establish:** no message has reached a phone, and Africa's
Talking stays **DOCUMENTED, UNVERIFIED** until the operator's first real sign-in
(`docs/127` S-6, J-12).

**Next, by the operator:**
1. Sign in to the Admin panel as `dishnet-admin`. The first time, it asks you
   to set up an authenticator app and change the password.
2. When the Africa's Talking account exists, enter it under **Administration →
   SMS for sign-in**.
3. Then add an owner with your own number and sign in on the phone. Say only
   what the screen shows: never the code, and never the key.
