# 127 — Operator sign-in end to end: codes by SMS, the operator's status, one phone form, and the operator app served

**Status:** review written **before** code, 2026-09-24 (§A–§E). **Phase 1 BUILT**
the same day (§F: migration 031 and one phone form at sign-in). **Phase 2 BUILT**
the same day (§G: migration 032, sign-in codes by SMS through the worker).
**Phase 3 approved by the operator** (*"Yes, own address (Recommended)"*),
reviewed before code in §H and **BUILT** the same day (§I: the operator app,
served by its own process). **Development only; nothing deployed.** Phase 4, the
staging command, is next. Nothing here is HARDWARE VERIFIED; F6-B stays NOT
AUTHORIZED.

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

---

## H. Phase 3 — serving the operator app: review before code (2026-09-24)

**The operator's decision**, asked because it reverses a written rule:
*"May I reverse that rule and serve the operator app at
app-staging.dishnetuganda.com on staging?"* → **"Yes, own address
(Recommended)"**. The rule was CLAUDE.md's *"public/ … Do not add them"*,
recorded because nothing served the app.

### H.1 What exists — measured

- **`public/index.php`** is the customer API front controller: `Database::app()`,
  `Routes::build()`, the Kernel. It serves the three sign-in routes, every
  `/api/v1/me…` route, and **`/internal/radius/accounting`**. Nothing serves it
  today, and the package excludes it.
- **`public/pwa/api.js` and `store.js`** are the data layer (`docs/82`). The
  token is held in `sessionStorage` only. Responses are classified into seven
  states, `202` counts as `queued` and never as success, and G1–G3 are
  `unavailable`.
- **The screens exist only in the prototype**,
  `prototype/dishnet-customer-pwa-prototype.html`. It is mock data from top to
  bottom (customers, sites, routers, plans, vouchers, sessions, invoices),
  reviewer chrome (a persona switcher, an *identity chain*, a tamper demo, a
  guest-portal preview), inline `onclick` handlers, and Google Fonts.
- **The operator-plane API** has 21 `/me` routes. Each is guarded by an `op.*`
  capability. A 403 is `{error: forbidden, capability}`, and a foreign id is a
  404.
- **Voucher creation's `Idempotency-Key` deduplicates only the intent**
  (migration 024, I-A open). A replay creates a **second batch** and returns
  the first intent, so that second batch is never published.
- **`plugin/bin/serve.php`** serves the Admin panel and sends every `/api/` to
  the **Admin** API. It could not serve the operator app even by mistake: its
  static branch is contained under `panel/`.

### H.2 Decisions

| # | Decision | Reason |
|---|---|---|
| H-1 | **Two entry points, two processes.** `serve.php` stays the Admin: `panel/` plus `/api/v1/admin/*`. A new **`plugin/bin/serve-app.php`** serves the operator app and passes **only** `/api/v1/auth/*` and `/api/v1/me` / `/api/v1/me/*` to `public/index.php`. Each answers **404** for the other's surface, tested both ways. **Refines A-1**: host routing inside one process becomes separate processes | the app process never holds an Admin credential. It needs exactly `DNB_DSN`, `DNB_APP_PASS`, `DNB_TOKEN_PEPPER` and `DNB_SECRET_KEY`. A-1's browser boundary holds either way: another host name means the Admin cookie is never sent there |
| H-2 | The app host refuses `/internal/*` (RADIUS accounting), `/api/v1/admin/*` and every other path. Static files are served only from `public/app/` and `public/pwa/`, contained by `realpath`, with an **extension allow-list**, so **a `.php` file is never served as a file**. `/` is `public/app/index.html` | a static branch that can read `public/` could otherwise print `index.php`'s source |
| H-3 | **Headers on every answer:** `Content-Security-Policy: default-src 'none'; script-src 'self'; style-src 'self'; img-src 'self' data:; connect-src 'self'; manifest-src 'self'; base-uri 'none'; form-action 'none'; frame-ancestors 'none'`, plus `X-Content-Type-Options: nosniff`, `Referrer-Policy: no-referrer`, `Cross-Origin-Opener-Policy: same-origin` and a restrictive `Permissions-Policy`. **The app therefore carries no inline script, no inline handler, no inline style and no third-party URL**, and a test asserts all four | the page holds a bearer token. A strict script policy is what keeps an injected string from reaching it |
| H-4 | The token stays in **`sessionStorage`** (the `docs/82` rule). A 401 on any request returns to sign-in with *"Your session ended"*. That is how a suspended operator's person sees 031 | a front-desk device is shared |
| H-5 | **`api.js` gains one state, `forbidden` (403)**, and the plan calls (`createPlan`, `retirePlan`). Each screen names its capability. A 403 renders *"Your role doesn't include this"*, distinct from *unavailable* and *failed* | collapsing *not allowed* into *failed* sends a staff member to retry something they can never do |
| H-6 | **The screens:** sign-in (request code → enter code) · home (operator, person, services, queued requests) · Wi-Fi and locations · a location · vouchers (list by state, **new**, **revoke**, copy a code) · plans (list, **new**, **retire**) · devices (**disconnect**) · usage · account (sign out). Access points (G1), billing (G2) and support (G3) render **unavailable** with the reason, never empty | the operator's go-ahead was *"to manage their own vouchers and plans"* |
| H-7 | **What the app may say, in this build:** after a code request, *"If this number can sign in, a code is on its way by SMS"*, never *"sent"* (F-3). Vouchers: *"Codes are recorded; the Wi-Fi login that accepts them is not switched on yet."* Devices: *"No device has been reported"*, never *"nobody is connected"*. Access points: *unavailable*, never *"0 of 0 on"*. A `202` is *queued* with no word about when a router is reached (B1). Usage shows the four measured counters and no invented chart | `LifecycleReport` has `activating`/`active`/`expired` unreachable; the voucher path is NON-CONFORMING (`docs/86`); sessions exist only when accounting reports them |
| H-8 | **No `Idempotency-Key` on voucher creation.** The button is disabled while a request is in flight, and **no POST is ever retried**. Making the route idempotent is its own item (I-A) | today the key would turn a replay into an unpublished second batch |
| H-9 | **Plans:** create (name, duration, download and upload speed, devices, optional data allowance, price) and retire. **Editing is not in this build.** Currency **UGX**, which has **no minor unit** (ISO 4217 exponent 0), so `price_minor` is whole shillings | the smallest honest version of *manage their plans* |
| H-10 | **Not in this build:** the staff-management screens (**J-14**; the API exists), plan editing, a service worker or offline install, the guest captive portal (the redemption path is unbuilt), billing and support (not in Domain B), and **any sign-in audit — F-8 stays OPEN**. CLAUDE.md: *"F-8.1…5 are OPEN. Nothing in this area may be implemented without an explicit instruction"* | recorded, not silently skipped |
| H-11 | **Packaging:** `public/` joins the package on the operator's approval. `tests/`, `tools/` and `docs/` stay excluded. CLAUDE.md's rule text is updated to record the approval, not deleted | the exclusion's premise, *nothing serves it*, is gone |
| H-12 | **The manifest gains `app`:** its entry point, static prefixes, headers, and the API routes it passes. A test asserts those routes equal what `Routes::build()` serves, minus `/internal/*` | the Admin surface is asserted that way already (`docs/90`) |
| H-13 | **Staging (phase 4):** a container of its own, `dnb-staging-app`, with only the four variables above, published on loopback and the bridge gateway (port **8098**, the 8099 precedent). A Traefik route for **app-staging.dishnetuganda.com** with a per-address rate limit, as stage 2 has. **The operator creates the DNS record first.** The SMS key is typed on the server, as §E says | one command, rehearsed first |


---

## I. Phase 3 — build record (2026-09-24)

**Development only. Nothing deployed.** The operator app runs in this container
and nowhere else. No operator has seen it and no SMS has reached a phone.

### I.1 What was built

- **`plugin/bin/serve-app.php`** — the operator app's server, its own process
  (H-1). Everything in H-2 and H-3, and nothing more:
  - `/` is `public/app/index.html`;
  - `/app/…` and `/pwa/…` are static files, each contained by `realpath` under
    its **own** directory, by an extension allow-list with no `php` on it;
  - exactly `/api/v1/me`, `/api/v1/me/…` and `/api/v1/auth/…` reach
    `public/index.php`;
  - everything else is its own `404`, with the same five headers as every other
    answer.
- **`public/app/`** — the prototype's screens on the real data layer:
  `index.html` (one empty module script tag), `app.js`, `app.css`, `icon.svg`.
  - No mock data, no reviewer chrome, no web fonts.
  - No inline script, handler or style: clicks and submissions are delegated
    from `data-act` and `data-form` attributes.
  - The wording is H-7's.
  - Voucher creation sends **no** `Idempotency-Key` (H-8). A write disables its
    button while in flight, and nothing retries a POST.
- **`public/pwa/api.js`** gains `FORBIDDEN` (403) and the two plan calls.
  **`store.js`** gains `reload(key)`, to reread one collection after a write.
- **Packaging (H-11):** `package.sh` copies `public/`. Its comment records why
  `public/` was excluded and when that ended, instead of deleting the history.
- **Manifest (H-12):** a new `app` section — entry point, front controller,
  static map and types, the API allow-list, what is refused, the four variables,
  the five headers, what is unavailable and what is not in this build.
- **Doctor:** `files.present` requires five more paths — `serve-app.php`,
  `public/index.php`, `public/app/index.html`, `public/app/app.js`,
  `public/pwa/api.js`.
- **`plugin/doc/INSTALL.md`:** §7b says how to serve the app — its own process,
  its own host name, the four variables, **never a document root at
  `public/`**, TLS in front, never `DNB_EXPOSE_OTP`. The capability table no
  longer says the app is not served.

### I.2 Found while building

- **The manifest's allow-list was ambiguous.** It was first written as three
  *prefixes*, one of which, `/api/v1/me`, the server treats as an **exact**
  path. Read as a prefix, it would let `/api/v1/meta` through. The manifest now
  states `exact` and `prefixes` apart. The suite derives its predicate from the
  manifest and probes `/api/v1/meta` over HTTP. A weakened server with a bare
  prefix (S2) is caught by that probe.
- **`form.name` is the form's own name**, not its `name` control. A form's own
  properties shadow its controls. Every form value is now read through
  `form.elements.namedItem`.
- **The browser asks for `/favicon.ico` by itself.** The host answered its own
  404 — correctly — but that put a console error on every page load. The page
  now declares `/app/icon.svg`. `/favicon.ico` still answers 404, and the suite
  asserts it. The one `http://` string in the bundle is the SVG namespace: a
  name, not a fetch. It is excepted exactly once, and counted.
- **A guard that read words, not the archive.** `test_installability` checked
  whether `package.sh` *named* `public/` in its exclusion rationale. The comment
  explaining why `public/` now ships names it, so the check stayed green while
  the rule was reversed. It was **rewritten, not deleted**:
  - it now builds the archive and reads its file list;
  - `public/` and `serve-app.php` must be in it;
  - nothing under `tests/`, `tools/` or `docs/` may be, and no `.env` anywhere.
  
  Two weakened builders fail it: one without `public/` (8 of 141) and one that
  ships `tests/` (2 of 141).

### I.3 Evidence, per piece

| Piece | Label | How |
|---|---|---|
| The served surface: files, 404s, headers, the API allow-list | **MEASURED** | over real HTTP, against PHP's built-in server — the server staging will run — started with only the four variables |
| The two processes refuse each other's surface | **MEASURED** | both servers started in the suite, each with its own positive control |
| Sign-in with the code the worker sent; a plan; a 403; sign-out | **MEASURED** | over HTTP, with a recording SMS sender standing in for the phone |
| The CSP holds in a browser: no inline code runs, nothing is blocked that the page needs | **MEASURED in headless Chromium only** | §I.6: zero CSP violations on every screen visited |
| Other browsers, a real phone, its keyboard and clipboard | **UNVERIFIED** | not run |
| A code reaching a phone | **UNVERIFIED** | phase 2's adapter, until a real message is sent (§G.3) |

### I.4 Tests

**`tests/test_operator_app.php` — 152 assertions**, in fourteen sections:

1. **The manifest against the code.**
   - The declared static types are the ones the server serves; `php` is not
     one of them.
   - Every one of the 25 operator routes `Routes::build()` serves is inside the
     allow-list. The one outside it is RADIUS accounting.
   - None of the Admin router's 40 routes is inside it.
2. **The process** holds exactly the four variable names, read from
   `/proc/<pid>/environ`. It was started with `env -i`, from the **package
   root**: were the server ever to fall through to PHP's own file server,
   `src/` would be one request away.
3. **Seven static files**, each byte for byte, with its type and `no-cache`.
4. **Thirty refusals, each the host's own 404.** The page, the source, the
   canary and PHP are each checked absent from every body. A new `.json` file
   under `public/app` is served first, as the control. The refused paths:
   - the front controller by name and by package path;
   - traversal out of `/app/` and `/pwa/`, plain and encoded;
   - a `.json` in `public/` but outside `/app/`, a `.php` and a `.txt` placed
     in `public/app`, and a `.js` symlink pointing out of it;
   - the directories themselves;
   - `src/`, a migration, the manifest, the env template, the panel and the
     test harness;
   - the Admin API and RADIUS accounting, by GET and by POST;
   - `/api/v1/meta`, `/api/v1/authx/…` and `/api/v1/auth`.
5. **The five headers, exactly, on six answers**: a page, a script, its own
   404, the Admin path's 404, an API 401 and an API 400. The script policy is
   `'self'` alone.
6. **All 25 operator routes reach the front controller** (JSON), none refused
   by the host.
7. **Sign-in over the wire.**
   - A spaced number gets `202 {status: sent}` and no code; an unknown number
     gets the byte-identical answer.
   - The worker sends **one** message, to the canonical number.
   - A wrong code is 401. The received code gives a token and `/me` as owner.
8. **A plan in UGX:** `201`, 5000 whole shillings. A Seller can read plans
   (the control) and is `403 {forbidden, op.plans.write}` on creating one.
9. **Sign-out:** `204`, and the same token then gets 401. RADIUS accounting
   with a token header is still the host's 404.
10. **`serve.php` refuses the app's surface** while its Admin API (401) and
    panel (200) answer. Neither server's code names the other's front
    controller.
11. **The bundle:**
    - one empty script tag; no style or handler attribute in the page; every
      `src` and `href` under `/app/`;
    - no other origin anywhere; no `@import`, font or `url(` in the styles;
    - one `fetch`, in `api.js`; no other connection, storage or `eval`;
    - `sessionStorage` in `api.js` alone; three request headers and no other;
    - no `Idempotency-Key`.
12. **No mock data.** The control shows the prototype does carry those
    constants. The screen's numbers come from their sources:
    `Authenticator::CODE_TTL_MINUTES`, and the rate window read out of
    `mt_auth_issue_code`'s body. The review's words are present; the claims
    are absent: no *sent*, no *nobody*, no router timing. 403 is `FORBIDDEN`.
13. **The doctor:** OK on this tree, with fifteen required paths. On an empty
    tree, the refusal names each of the five app paths.
14. **Repository state.**

**Deliberate updates:** `test_installability` — the packaging section is
rewritten (§I.2), and the operator app server's `realpath` containment is added
beside the panel servers'.

### I.5 Weakened copies — each caught

The server (`test_operator_app`):

| Copy | Weakening | Caught by |
|---|---|---|
| S1 | `/internal/` passed to the front controller | 3 — both RADIUS probes and the post-sign-out probe |
| S2 | `/api/v1/me` as a bare prefix | 1 — `/api/v1/meta` |
| S3 | every `/api/` path passed | 7 — the three Admin paths, `meta`, `authx`, `auth` and `health` |
| S4 | contained under `public/`, not the prefix's own directory | 1 — the `.json` outside `/app/` |
| S5 | no extension allow-list | 2 — the `.php` (its source was printed) and the `.txt` |
| S6 | no Content-Security-Policy | 6 — every header check |
| S7 | `'unsafe-inline'` added to `script-src` | 6 — every header check |
| S8 | an unknown path falls through to PHP's own file server | 22 — all 21 static refusals, because PHP's own file server answered them instead (it served the manifest), and the headers on the 404 |
| S9 | headers sent only for static files | 3 — the Admin 404 and both API answers |

The bundle (`test_operator_app`):

| Copy | Weakening | Caught by |
|---|---|---|
| B1 | an `onclick` in a screen | 1 |
| B2 | a `style` attribute in a screen | 1 |
| B3 | an `Idempotency-Key` on every POST | 2 |
| B4 | the token in `localStorage` | 1 |
| B5 | the screen's code lifetime set to 5 minutes | 1 |
| B6 | an inline `<script>` in the page | 1 |
| B7 | a web font from another origin | 2 |
| B8 | *"a code has been sent"* | 2 |
| B9 | 403 no longer `FORBIDDEN` | 1 |
| B10 | a second `fetch`, outside `api.js` | 1 |
| B11 | the prototype's `SITES` constant | 1 |
| B12 | the data layer imported from another origin | 2 |

And the builder (`test_installability`): P1 without `public/` (8 of 141), P2
shipping `tests/` (2 of 141).

### I.6 The browser run — headless Chromium

The installed Chromium (build 1194), driven by Playwright at 390 × 844, against
`serve-app.php`. The server was started with only the four variables, on a
fresh database seeded through the Admin plane's real writers. The drive:

1. It signed in with `+256 700 555 001`, typed with spaces. The code stage said
   *"If +256 700 555 001 can sign in, a code is on its way by SMS. It is valid
   for 10 minutes."*
2. A wrong code said *"That code didn't work. Check it, or send a new one."*
3. The code the worker sent — a file-writing stand-in for the phone — signed in.
4. It created a plan, *1 Day*, shown as **UGX 5,000**.
5. It created two codes at *Poolside*. The result sheet showed both codes and
   said **Queued.**, and *Copy codes* put both on the clipboard.
6. It revoked one. The toast said *"Revoked. Removing it from your Wi-Fi is
   queued."* and *Finished* listed it as *Revoked*.
7. The other screens each said only what the platform knows:
   - *Wi-Fi*: access points *not available*, locations listed;
   - *Devices*: *No device has been reported*;
   - *Usage*: *No measurements yet*;
   - *Home*: the two requests, queued;
   - *Account*: role *Owner*, billing and support *not available yet*.
8. Signing out returned to sign-in and left **no token** in `sessionStorage`.

**Zero CSP violations and zero page errors.** The one 4xx was the deliberate
wrong code. The database afterwards:

- one plan;
- one voucher revoked and one unused;
- two queued intents (`voucher.publish`, `voucher.revoke`);
- one SMS `sent`;
- audit rows from the Admin plane (`customer.created`, `service.created`, two
  `site.created`, `principal.created`) and from the operator's own person
  (`plan.created`, `voucher.issued`, `voucher.revoked`).

The screenshots stayed in the session's scratch space; the drive script is not
part of the suite.

### I.7 Proof runs

- **Full suite:** 40 suites / **3,906** assertions / 0 failed, twice. `test_operator_app.php` alone is 152, and `test_installability.php` went from 117 to 141.
- **`plugin/bin/install-test.sh`:** **86/86**. Ports 8099, 8098, 8131 and 443 were checked first for stray harness servers.
- **Package:** **142 files** (was 134), content digest `2874d64346b927e6ec2da77ceecb19714a623030ea7e89b74590d7bc6640a0c4`. The eight new files are `public/index.php`, four under `public/app/`, two under `public/pwa/` and `plugin/bin/serve-app.php`. Not deployed; phase 4's command will pin it.

### I.8 Not done here

- **Phase 4, the staging command:**
  - apply 031 and 032;
  - add the `dnb-staging-app` container and the Traefik route for
    `app-staging.dishnetuganda.com`, after the operator creates its DNS record;
  - take the SMS key, typed on the server.
- **J-14** (the staff screens), plan editing, a service worker or offline
  install, and the guest captive portal. Billing and support are not in Domain
  B.
- **F-8 — the sign-in routes' audit** stays OPEN (H-10). **I-A** — voucher
  creation's idempotency — stays open. The app sends no key and never retries.
- **Vouchers are still NON-CONFORMING** (`docs/86`). The app records codes and
  says plainly that guests cannot use them yet.
