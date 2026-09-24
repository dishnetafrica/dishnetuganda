# 127 — Operator sign-in end to end: codes by SMS, the operator's status, one phone form, and the operator app served

**Status:** review written **before** code, 2026-09-24 (§A–§E). **Phase 1 BUILT**
the same day (§F: migration 031 and one phone form at sign-in). **Phase 2 BUILT**
the same day (§G: migration 032, sign-in codes by SMS through the worker).
Phase 3 follows. **Development only; nothing deployed.** Nothing here is
HARDWARE VERIFIED; F6-B stays NOT AUTHORIZED.

**Why now.** `docs/126` bound the owner login and found that an operator still
cannot sign in: the code is delivered nowhere (§B.1 there), sign-in ignores the
operator's status (F-J1-1), the sign-in side does not use the canonical phone
(D-4), and the operator app is not served. Asked how codes should reach a phone,
the operator chose **"SMS (Recommended)"**. This document designs all four
pieces together, because none is useful alone, and builds them in order.

---

## A. The order

| Phase | What | Needs from the operator |
|---|---|---|
| **1** | **F-J1-1:** sign-in requires an **active operator** as well as an active person. **D-4:** the sign-in routes use the canonical phone. Migration **031** | nothing |
| **2** | **SMS delivery:** a sealed outbox written by the sign-in request, sent by the worker through a provider adapter | an account with an SMS provider; its key is entered **on the server**, never in chat |
| **3** | **The operator app served:** the prototype's audited screens on the real data layer, on their own host name | one DNS record, as for stage 2 |
| **4** | **Staging:** one command per phase, rehearsed first | running them |

Nothing is exposed to operators until phases 1–3 are all in place.

---

## B. Phase 1 — the operator's status, and one phone form

| # | Decision | Reason |
|---|---|---|
| F-1 | `mt_auth_issue_code` finds a principal only if the person **and** the operator are `active`. `mt_auth_verify_code` re-checks both at verification, so a code issued before a suspension cannot be used after it. `mt_auth_resolve_token` re-checks both **on every request**, so a suspended operator's live sessions stop on the next request — exactly as a disabled person's already do | F-J1-1 |
| F-2 | `dnb_def_auth`, which owns the three functions, gains **SELECT on `mt_customers`** with one `USING (true)` SELECT policy, named as 017 names them — nothing else. Return shapes and grants are unchanged (`dnb_app` only) | least privilege for the check |
| F-3 | **Uniformity is unchanged:** an unknown number, a disabled person and a suspended operator produce the same `202 sent` and the same `401` | the endpoint must not become a directory |
| F-4 | Suspension does **not** revoke sessions: nothing suspends an operator yet (no writer exists), and the live check is the enforcement. A reinstated operator's unexpired sessions work again | recorded, not silent |
| F-5 | **D-4:** `request-code` and `verify` apply `Phone::canonical()`. Input that has no canonical form is passed on unchanged: it matches nobody, and the answer stays `202` | one form at both ends |

---

## C. Phase 2 — SMS delivery

| # | Decision | Reason |
|---|---|---|
| S-1 | **A code is sent only to a number that belongs to an active person of an active operator.** Never to an unknown number | otherwise anyone could spend DishNet's SMS credit on any number, and the endpoint becomes an SMS cannon |
| S-2 | **The request never waits on the provider.** `mt_auth_issue_code` writes an outbox row in the same transaction as the code, **for every request**: a registered number gets the sealed code and `queued`; an unregistered one gets `no_recipient` and no payload. The same writes either way, so the answer's timing does not tell registered from unregistered. **The worker sends** | response uniformity is not tradeable |
| S-3 | **The code never rests in clear.** PHP seals it with `SecretBox` under a key **derived from `DNB_SECRET_KEY` with its own label**, `dn-sms-outbox-v1` — never the device-secret key itself — and the canonical phone as associated data, so a sealed code moved to another row does not open. The payload is erased when the row settles or expires | a database dump must not carry live sign-in codes |
| S-4 | **The outbox** `mt_auth_sms_outbox` belongs to `dnb_def_auth`, is created under `SET LOCAL ROLE dnb_def_auth`, and **no login role holds any privilege on it**. The worker reaches it only through three definer functions — claim (with a lease), settle, expire — EXECUTE-able by **`dnb_worker` only**. At most 3 attempts; the lease is 60 seconds | the 026/030 pattern |
| S-5 | **The provider is a port**, `Dn\Notify\SmsSender`. `DN_SMS` unset → `NullSms`: nothing is sent and rows expire as `expired`, which is the truth. `DN_SMS=africastalking` → the real adapter, which refuses to construct without its username and key. Any other value → refuse to start. **No fallback in any direction** | the `DN_DELIVERY` rule (`docs/118` D-7) |
| S-6 | **The first adapter is Africa's Talking** — the provider the operator was pointed to. Its HTTP interface is **DOCUMENTED, UNVERIFIED**: built from the provider's published API and proved against a fake provider; it becomes verified only when the operator's account sends the first real message from staging. A different provider is one more adapter | the evidence labels of `docs/00` |
| S-7 | **The message** is fixed: `DishNet sign-in code: 123456. Valid 10 minutes. Do not share it.` No operator name, no link | nothing more than the code |
| S-8 | **Nothing secret is ever logged**: not the code, not the key, not the sealed payload. A provider error is scrubbed of the key before it is stored or printed | the B-1 lessons |
| S-9 | **Configuration**: `DN_SMS`, `DNB_SMS_USERNAME`, `DNB_SMS_API_KEY` (secret), `DNB_SMS_SENDER` (optional sender name) and `DNB_SMS_SANDBOX` (the provider's test endpoint). All are declared in the manifest. The doctor: `DN_SMS` unset is a **WARN** (no sign-in codes are sent); the adapter selected with its key missing is a **BLOCKER** | an install must say what it can do |

---

## D. Phase 3 — the operator app served (recorded now, built after phase 2)

| # | Decision | Reason |
|---|---|---|
| A-1 | **Its own host name** — recommended `app-staging.dishnetuganda.com` on staging: one DNS record and one Traefik file, the stage-2 precedent. `serve.php` routes by host: the operator host serves only the app, `/api/v1/auth/*` and `/api/v1/me*`; the admin host serves only the panel and `/api/v1/admin/*` | a fault in one app must not be able to act with the other's sign-in: the Admin session is a cookie, and the Admin API's cross-site check trusts its own origin |
| A-2 | **`public/` joins the package**, reversing the RC1 exclusion, which was recorded *because nothing served it* | it is now served |
| A-3 | The app is the prototype's **audited screens** on the real data layer (`api.js`, `store.js`, `docs/82`), with the mock data and reviewer chrome removed. Screens whose data does not exist stay **unavailable**, never empty (`docs/82` G1–G3) | nothing invented |
| A-4 | Before exposure: F-J1-1 (phase 1), the per-number rate limit (exists: 5 per 15 minutes), uniform answers (exist). **F-8**: the sign-in routes audit nothing. Whether a sign-in writes an audit row is decided in phase 3's own review | recorded |

---

## E. Staging, in outline

- **The SMS key is typed on the server**, into a file with mode 0600, by a prompt
  that does not echo. It never appears in the chat, in a log or on the terminal.
  The doctor reports it as *set (value withheld)*.
- **The worker needs outbound HTTPS** to the provider. Whether the staging
  worker's network allows it is **measured on the server**, not assumed.
- **Each phase has its own command**, pinned to a reviewed commit and rehearsed
  in the harness first.

---

## F. Phase 1 — build record (2026-09-24)

**Development and test schema only. Nothing deployed.**

### F.1 What was built

- **Migration 031** (`031_sign_in_requires_active_operator.sql`): F-1 and F-2 as
  designed, with the one refinement in §F.2.
  - `dnb_def_auth` gains `SELECT` on `mt_customers`, with one policy,
    `dnb_def_auth_mt_customers_select … USING (true)`. Nothing else.
  - The three functions are replaced by their owner, inside `SET LOCAL ROLE
    dnb_def_auth` and the `GRANT CREATE` / `REVOKE CREATE` bracket that 026 and
    027 use.
  - Signatures, return shapes and EXECUTE (`dnb_app` only) are unchanged;
    `CREATE OR REPLACE` keeps the ACL.
  - A verification block refuses the whole migration if any of these fails:
    - each of the three is a `SECURITY DEFINER` owned by `dnb_def_auth`;
    - each reads `mt_customers`;
    - each is executable by `dnb_app` and nobody else;
    - `dnb_def_auth` can read `mt_customers` and do nothing more.
- **`Authenticator::keyOf()`** (F-5, the D-4 finding): `issueCode()` and
  `verifyCode()` both apply `Phone::canonical()`.
  - It sits in the Authenticator rather than the routes because every sign-in
    enters there: the customer API today, the operator app later. The routes are
    unchanged.
  - Input with no canonical form passes on as typed, trimmed. It matches nobody,
    and the answer is the same `202`.

### F.2 A refinement: WHICH operator is checked

The design said the functions "re-check both". Writing the proof showed that this
has two readings:

- the person's **current** operator (`mt_principals.customer_id`); or
- the operator **the session acts for**.

They are equal today, because nothing can move a person to another operator
(P-C). 031 checks **the operator the session acts for**, everywhere:

- **the resolver** joins `mt_auth_sessions.customer_id`, the session's own
  operator (P-C, as documented in 027);
- **verification** joins the **code row's** `customer_id`. That is the value it
  returns, and so the operator the new session will carry.

Proved by moving a person between operators. Only the fixture identity can do
that, and only inside transactions that are always rolled back:

- the person is moved to an active operator and their own operator is suspended
  → neither the code nor the session opens anything;
- the person is moved and their own operator is active → both act for their own
  operator (the control).

### F.3 Measured: without its own policy, sign-in fails closed — and loudly

Weakening M6 makes `dnb_def_auth`'s policy `USING (false)`.

- `dnb_def_auth` then falls under the table's `mt_customers_isolation` policy.
  That policy is `TO public` and calls `mt_current_customer()`, which
  `dnb_def_auth` may not execute.
- **Every sign-in then fails with 42501.** It fails closed, and loudly (a 500).
  It never looks like a quiet "unknown number".

This is recorded because it makes a mistake in this policy visible at once. With
the policy correct, the `USING (true)` branch satisfies the check, so the
isolation predicate is never evaluated. Every definer role since 017 relies on
the same pattern.

### F.4 Tests

`tests/test_operator_sign_in.php`, **37** assertions:

1. **Catalogue and privileges.**
   - Each function: owner, `SECURITY DEFINER`, reads `mt_customers`, EXECUTE by
     `dnb_app` only.
   - `dnb_def_auth` reads `mt_customers` and cannot write it.
   - The ledger records 031.
2. **At issue.** A `suspended` or a `closed` operator's person gets a code row
   naming nobody, exactly as an unknown number does. The control runs first.
3. **At verification.**
   - Suspended between issue and verify → no session. The same when closed.
   - Which operator is checked (§F.2).
   - Reinstated → the same unexpired code verifies. The check is live, not a
     one-way latch.
4. **On every request.**
   - A live session gets 401 on `/me` and on every `/me` route.
   - The session is not revoked (F-4).
   - **Control on the control:** the pre-031 resolver, swapped back inside a
     rolled-back transaction, lets the same session through.
   - Which operator is checked (§F.2).
   - Reinstated → 200. `closed` → 401 again.
   - A disabled person is still refused.
5. **Uniform answers.** An unknown number, a suspended operator's person and an
   active person all get the same `202 {status: sent}`, and the same 401 for a
   wrong code.
6. **One phone form.**
   - A number typed with spaces and a hyphen requests a code, and the code is
     filed under the canonical number.
   - A parenthesised spelling of the same number verifies it.
   - A non-number passes on and matches nobody, with the same `202`.
7. **Repository state.**

**Deliberate updates.** Each was rewritten to the new truth; none was deleted.

- **`test_operator_owner_login`**
  - §6: the two GAP assertions now read **CLOSED**. A spaced phone signs in
    (D-4). A suspended operator's owner cannot sign in, and a live session stops
    (F-J1-1).
  - §9: *"no migration was added"* became *"the creator J-1 binds is defined in
    027 and nowhere after"*. 031 now exists and does not touch that creator.
- **`test_definer_roles`:** `dnb_def_auth` gains `mt_customers => SELECT` in the
  policy matrix, with the reason.
- **The three migration pins** (`test_router_control_plane` §13,
  `test_router_lifecycle_provision` §10, `test_operator_staff` §15): migrations
  now end at **031**, ledger **31**.
- **Found by M4:** a failed verification left the session token `null`, which
  crashed the suite instead of failing it. The token is now read with a default,
  so a weakened 031 produces counted failures.

### F.5 Weakened copies — each caught

| | Weakening | Caught by |
|---|---|---|
| M1 | the resolver drops the operator's status | 6 of 37 |
| M2 | the resolver checks the person's operator, not the session's | 1 of 37 (§F.2) |
| M3 | code issue drops the operator's status | 2 of 37 |
| M4 | verification drops the operator's status | 8 of 37 |
| M5 | verification checks the person's operator, not the code's | 1 of 37 (§F.2) |
| M6 | `dnb_def_auth`'s policy `USING (false)` | every sign-in fails with 42501 at §2's first control (§F.3) |
| M7 | verification's re-check removed | **the migration refuses itself** |
| M8 | EXECUTE on the resolver granted to `dnb_worker` | **the migration refuses itself** |
| M9 | `dnb_def_auth` also granted `UPDATE` on `mt_customers` | **the migration refuses itself** |
| M10 | the Authenticator without the canonical form | 2 of 37 |
| M11 | `CREATE` on the schema not revoked from `dnb_def_auth` | `test_definer_roles`, 1 of 58 |
| M12 | `status <> 'suspended'` in place of `= 'active'`, so `closed` gets through | 9 of 37 |

M8's first version issued the grant after `RESET ROLE`, as the owner.
PostgreSQL itself refused it (*permission denied*), because the owner does not
own the function. That run tested nothing about 031, so M8 was run again with the
grant issued by `dnb_def_auth` inside the role block. That time the migration's
own check refused it.

### F.6 Proof runs

- **Full suite:** 38 suites / **3,614** assertions / 0 failed, twice on the final
  code. Earlier runs at 3,609 and 3,611, before the §F.2 and `closed` assertions
  were added, also passed.
- **`plugin/bin/install-test.sh`:** 85/85. Ports 8099, 443 and 8131 were checked
  first for stray harness servers.
- **Package:** **125 files**, content digest
  `9118f2e7766c63d845025d711613f7e9e04f99138f3de2ffadf8d14714c0316e`. The later
  test-only changes left it unchanged, confirmed by rebuilding. It is not
  deployed and not pinned anywhere.

### F.7 Staging — 031 travels with phase 2's command

031 does not go to staging on its own:

- Nobody can sign in as an operator on staging yet: no code is delivered, and the
  operator app is not served.
- So 031 alone would change nothing anyone could see or test, and would still
  cost the operator a command.

It goes with phase 2's command, which must apply 031 and phase 2's migration and
prove both. **This refines §E's "each phase has its own command".** Nothing is
exposed to operators until phases 1–3 are all in place (§A).

### F.8 Not done here

- SMS delivery (phase 2) and serving the operator app (phase 3).
- F-8, the sign-in routes' missing audit, which is decided in phase 3's review.
- Any writer that suspends or closes an operator. Measured: nothing in the code
  sets `mt_customers.status`, and only the fixtures change it. 031 makes that
  status count at sign-in. Setting it is its own instruction.

---

## G. Phase 2 — build record (2026-09-24)

**Development and test schema only. Nothing deployed. No message has been sent
to any phone.**

### G.1 What was built

- **Migration 032** (`032_sign_in_codes_by_sms.sql`), S-1…S-4 as designed:
  - **The outbox.** `mt_auth_sms_outbox` is created as `dnb_def_auth`. No login
    role holds any privilege on it.
    - One row per code, holding the code row's id rather than a second copy of
      the phone.
    - Its states are `queued`, `sending`, `sent`, `failed`, `expired` and
      `no_recipient`.
    - Two table constraints are the floor under the functions: the envelope
      exists while, and only while, the message may still be sent; and a lease
      exists only while it is being sent.
  - **`mt_auth_issue_code` gains a fourth parameter, the sealed code.**
    - The three-argument form is **dropped**, not left beside it.
    - A missing or malformed payload is refused with `22023` for every number,
      before anything is written.
    - The same lookup as 031 decides the outbox row: `queued` with the
      envelope, or `no_recipient` with none.
  - **Three worker functions**, owned by `dnb_def_auth` and executable by
    `dnb_worker` **only**:
    - `mt_auth_sms_claim(limit)`: a 60-second lease, `SKIP LOCKED`, at most three
      attempts.
    - `mt_auth_sms_settle(id, attempt, outcome, ref, error)`: the attempt number
      is the claim's token, so a late worker cannot settle a newer claim.
    - `mt_auth_sms_expire()`: closes what can no longer be sent.
    - Every final state erases the envelope.
  - **REFERENCES on `mt_auth_codes`** is granted to `dnb_def_auth` only for
    creating the foreign key, and revoked straight after.
  - **A verification block** refuses the migration unless the owners, the
    EXECUTE lists, the absence of any other grant on the outbox, and those
    revocations are exactly as stated.
- **`Dn\Notify\CodeEnvelope`** (S-3): AES-256-GCM (`SecretBox`), under
  `HMAC-SHA256(DNB_SECRET_KEY, 'dn-sms-outbox-v1')`, with the phone as
  associated data.
  - `Authenticator::issueCode()` seals **every** request's code, registered or
    not.
  - The plaintext is returned only for `DNB_EXPOSE_OTP`, as before.
- **`Dn\Notify\SmsSender`** (S-5), the port every provider is reached
  through:
  - `NullSms` is not configured, and is never asked to send.
  - `AfricasTalkingSms` is the adapter (S-6, §G.3).
  - `SmsSenders::fromEnvironment()` selects: unset or `null` → `NullSms`;
    `africastalking` → the adapter, which refuses to start without
    `DNB_SMS_USERNAME` and `DNB_SMS_API_KEY`; anything else refuses to start.
    **No fallback.**
- **`Dn\Jobs\SmsWorker`** runs a pass on every tick:
  - `expire`, then — only when a real sender is configured — claim, open each
    envelope **in memory**, send, and settle.
  - Its report is counts only.
  - A sender that throws is retried, and its message is **not** kept.
  - An envelope that does not open is failed, and nothing is sent.
- **`bin/worker.php`:**
  - Sign-in codes are handled **every second**, because someone is waiting at a
    sign-in screen. Intents keep their five-second cadence.
  - The startup line now carries `"sms": <binding>`.
- **The message** (S-7), fixed:
  `DishNet sign-in code: 123456. Valid 10 minutes. Do not share it.` The "10"
  is `Authenticator::CODE_TTL_MINUTES`, the same constant that sets the code's
  lifetime.
- **The doctor** gains `sms.sender`. It constructs exactly what the worker
  would, so the two cannot disagree:
  - unset → **WARN** (*no operator can sign in*);
  - a missing username or key → **BLOCKER**, naming the variable;
  - configured → **OK**, *value withheld*, and it says **SANDBOX** when the
    sandbox is selected.
- **The manifest** declares `DN_SMS`, `DNB_SMS_USERNAME`, `DNB_SMS_API_KEY`
  (**secret**) and `DNB_SMS_SENDER`.
  - **`DNB_SECRET_KEY` is now required**, because no code can be issued
    without it. The installer already generates it.
  - `.env.example` and `INSTALL.md` document all of this. The template carries
    no key, not even a placeholder value.

### G.2 Refinements of §C, found while building

- **`DNB_SMS_SANDBOX` is dropped.** The provider's own SDK selects the sandbox
  when the username is `sandbox`. A separate flag could only disagree with it:
  a sandbox flag with a live username would fail authentication.
- **The grants are issued inside the owner's role block.** Measured, not
  assumed: the installing owner `dnb` is a member of `dnb_def_auth` **without
  inherit**. A `REVOKE` it issued on `dnb_def_auth`'s functions after `RESET
  ROLE` was therefore a **WARNING that changed nothing**, and PUBLIC kept
  EXECUTE on the new `mt_auth_issue_code`.
  - The first run of this migration was refused by **its own verification
    block**, which named the function.
  - 027 and 030 grant inside their role blocks for the same reason.
  - Recorded because it would have been silent without the self-check.
- **HTTP 403 from the provider counts as refused credentials**, like 401, not
  as a generic client error. Both fail the message at once; retrying cannot
  help.

### G.3 The adapter's evidence, per piece

| Piece | Label | Source |
|---|---|---|
| Live endpoint `https://api.africastalking.com/version1/messaging` | **MEASURED** | the provider's official SDK, npm `africastalking` 0.7.9, `lib/common.js` |
| Sandbox endpoint, selected by the username `sandbox` | **MEASURED** | same, `lib/common.js` and `lib/index.js` |
| POST; `apikey` and `Accept: application/json` headers; form body `username`, `to`, `message`, optional `from` | **MEASURED** | same, `lib/sms.js` |
| Success is HTTP **201** and nothing else | **MEASURED** | same, `lib/sms.js` (*"API returns CREATED on success"*) |
| `SMSMessageData.Recipients[].statusCode` / `messageId`, and what each code means | **DOCUMENTED, UNVERIFIED** | the provider's published API, as known. Its documentation host was **blocked from this session twice** (egress policy) |
| That the provider accepts these requests from DishNet's account and delivers to Ugandan numbers | **UNVERIFIED** | only a real message from staging can show it (S-6) |

The endpoint **cannot be configured from the environment**. A value that could
point the adapter at another host would be a way to send every code somewhere
else. Only a test may pass a loopback endpoint, and nothing but HTTPS or
loopback is accepted at all.

### G.4 Tests

`tests/test_sms_delivery.php` — **115** assertions, in 13 sections:

1. **Catalogue.** The owner. Every `dnb_*` login role, enumerated from
   `pg_roles`, holds nothing on the outbox (the owner is the control). There is
   no default ACL. The old signature is gone. Each function's owner and exact
   EXECUTE list. The revoked REFERENCES and CREATE. The ledger.
2. **Sealed at rest.**
   - The code is in neither the envelope nor its decoded bytes.
   - The derived key and the right phone open it (control).
   - Another phone does not; the **raw** `DNB_SECRET_KEY` does not; and a
     device-credential envelope does not open as a code.
   - There is no key without a master key, and the code row holds only the hash.
   - **The table refuses** a settled row with an envelope, a queued row with a
     lease, and a queued row without an envelope.
3. **Who gets a code.**
   - Each request writes exactly one code row and one outbox row, registered or
     not, and the answers are the same.
   - An unknown number, a suspended operator's owner and a disabled person all
     get `no_recipient` with no payload.
4. **A payload is required.** No payload or a malformed one → `22023` and no row
   written, the same for an unknown number and a registered owner.
5. **End to end.**
   - The route answers 202 and shows no code.
   - The worker sends **one** message (not the unknown number's) to the
     canonical number, with exactly the fixed text.
   - The row is `sent`, the envelope erased, the reference kept.
   - **The code from the message signs the owner in, and `/me` answers.**
   - The report holds no code or number, and nothing is sent twice.
6. **Retry, failure, lease, expiry.**
   - Three retryable answers → `failed` after exactly three sends.
   - A permanent refusal fails at once.
   - The lease holds; a lapsed lease is reclaimed as attempt 2; a late settle
     with attempt 1 changes nothing; a double settle finds nothing; an invalid
     outcome → `22023`.
   - Two workers at once claim **different** messages (with a lock timeout, so
     a missing `SKIP LOCKED` fails instead of hanging).
   - An expired code's message is not claimed and is closed as `expired`. A
     third attempt is never claimed a fourth time and is closed as `failed`.
   - `NullSms` claims nothing and spends no attempt; its message expires with
     its code.
   - An envelope under another key is failed and never sent. A throwing
     sender's message is not stored.
7. **Selection.**
   - Unset or `null` → `NullSms`.
   - A missing username or key refuses to start, naming the variable and never
     the key.
   - Live versus sandbox; the mode is case-insensitive.
   - An unknown binding or a malformed sender name refuses to start.
   - The endpoints are exactly the SDK's, and only HTTPS or loopback is
     accepted.
8. **The doctor.** WARN, BLOCKER, OK with *value withheld* (the key appears
   nowhere in the row), SANDBOX, and BLOCKER for an unknown binding.
9. **The adapter over a real socket**, against `tests/fake_sms_provider.php` on
   loopback. **That fake is not the provider**: it proves our HTTP handling,
   nothing about the provider.
   - The exact request: method, path, headers, and the fields, with no `from`
     when there is no sender name.
   - Eleven answers read correctly: refused number, no balance, status code as
     a string, unknown code, no recipient, free text never repeated, refused
     credentials, 400, 500, not JSON, and **a 200 is not a 201**.
   - Connection refused, with a control that nothing listens.
   - A slow provider times out after the timeout, not after the provider.
   - **No reason carries the key, the number, the code or the message.**
10. **The whole chain with the real adapter.** Request → worker → provider →
    the code the provider received signs the owner in. A refusal fails the row,
    and nothing stored in it is the key.
11. **The worker process.**
    - With `DN_SMS` unset it starts and says `"sms":"null"`.
    - An unknown binding refuses to start.
    - Without the key it refuses, naming the variable and no value.
12. **Declared.** The manifest and the template.
13. **Repository state.**

**Deliberate updates.** Each was rewritten to the new truth; none was deleted.

- `test_definer_roles` F2: the side-effect proof now calls the four-argument
  function with a real envelope, and also finds the outbox row.
- `test_operator_sign_in` §1 checks `mt_auth_issue_code` under its current
  signature. 031's operator check is still read by the rest of that suite.
- The three migration pins now end at **032**, ledger **32**.

### G.5 Weakened copies — each caught

| | Weakening | Caught by |
|---|---|---|
| N1 | every number queued a code (S-1 gone) | 4 of 115 (§3, §5) |
| N2 | the payload check removed | 4 of 115 (§4) |
| N3 | settle keeps the envelope **and** the table's floor is removed | 7 of 115 (§2, §5, §6, §10) |
| N3b | the table's floor alone removed | 2 of 115 (§2's refusals) |
| N4 | no `SKIP LOCKED` | 1 of 115 (§6, the two-worker claim fails on its lock timeout instead of hanging) |
| N5 | a fourth attempt allowed | 1 of 115 (§6: the claim fails on the table's attempts check instead of returning nothing) |
| N6 | the stale-claim token ignored | 3 of 115 (§6) |
| N8 | claim also granted to `dnb_app` | **the migration refuses itself** |
| N10 | the raw key, not a derived one | 2 of 115 (§2) |
| N11 | no associated data | 1 of 115 (§2) |
| N12 | any 2xx confirms | 1 of 115 (§9) |
| N13 | claiming with no sender configured | 1 of 115 (§6) |
| N14 | storing what a throwing sender said | 1 of 115 (§6) |
| N15 | falling back to nothing when the key is missing | 4 of 115 (§7, §8, §11) |

Two assertions were added after the first round of these runs, and one was
rewritten:

- **Added:** the table-floor refusals in §2, and *"never claimed a fourth time"*
  in §6. Before them, N3b and N5 had nothing to fail.
- **Rewritten:** the two-worker claim now carries a lock timeout. N4 would
  otherwise have hung the suite rather than failed it.
- **Rewritten:** the fourth-attempt claim now catches the table's refusal. N5
  first crashed the suite on it, instead of counting a failure.
- **Found:** §10 first failed *on the correct code*. §6 had left a retry
  queued, so the worker sent two messages; the section now closes pending
  messages first.

### G.6 Proof runs

- **Full suite:** 39 suites / **3,730** assertions / 0 failed, twice.
  `test_sms_delivery.php` alone is 115.
- **`plugin/bin/install-test.sh`:** **86/86**. Its first run after
  `DNB_SECRET_KEY` became required failed 1 of 85. The *"nowhere to put
  generated secrets"* refusal it expected was pre-empted by the new
  configuration refusal, so that guard was no longer exercised. Both refusals
  are now exercised, each on its own (the step-4 check was split in two).
  Ports 8099, 443 and 8131 were checked first for stray harness servers.
- **Package:** **134 files**, content digest
  `0b9b1a58ba059f2e0129240a857e0e20828ca0bacac08b06e1304f927c4684f5`. The test
  suite and `tests/fake_sms_provider.php` are excluded, as all of `tests/` is.
  The package is not deployed and not pinned anywhere.
- **Fourteen weakened copies**, all caught (§G.5).

### G.7 Not done here

- **Phase 3:** serving the operator app. Until then no operator can reach the
  sign-in routes on any host, because `serve.php` sends `/api/` to the Admin
  API.
- **Delivery reports.** `sent` means the provider accepted the message, not
  that the phone received it. The provider's delivery callbacks are not read.
- **Retention.** Code rows and outbox rows accumulate, as code rows always
  have. What is kept after settling is metadata only, because the envelope is
  erased.
- **Spend bound.** Codes go only to registered, active numbers, at most five
  per number per fifteen minutes (the existing limit). There is no global cap
  across numbers yet.
- **The staging command** carries 031 and 032 together (§F.7), with the key
  typed on the server (§E).

