# 128 — SMS settings from the Admin panel

**Status:** review written **before** code, 2026-09-25 (§A–§E). The trigger was
the staging command of `docs/127` §L.2, which asked for an Africa's Talking
account the operator does not yet have. The operator's instruction was: *"i dont
have currently keep it configuratblae from ui i will add later"*. The build comes
first, in development. Staging then gets its own command. Nothing here is
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
  wins, and the panel then says so.

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
