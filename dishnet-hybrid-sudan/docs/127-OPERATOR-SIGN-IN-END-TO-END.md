# 127 — Operator sign-in end to end: codes by SMS, the operator's status, one phone form, and the operator app served

**Status:** review written **before** code, 2026-09-24 (§A–§E). **Phase 1 BUILT**
the same day (§F: migration 031 and one phone form at sign-in). Phases 2 and 3
follow. **Development only; nothing deployed.** Nothing here is HARDWARE
VERIFIED; F6-B stays NOT AUTHORIZED.

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
