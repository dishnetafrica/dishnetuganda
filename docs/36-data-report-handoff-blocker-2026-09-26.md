# 36 — The data-report hand-off token: security-blocker analysis (26 September 2026)

**Status: READ-ONLY analysis. No patch, no deployment, no token-scheme change.** The operator
classified the finding of docs/35 §1 as a **security blocker** on 26 September. The sibling plugin
`dishnet-data-report` 2.8.80 is not in this repository and its source has **not yet been read**: the
first reading command's mechanical lines were invalid (docs/35 §1a) and the command is rewritten.
Every item below is marked **CONFIRMED** (read in this repository, or measured on the server by the
K block of the first run) or **PENDING** (awaits the source reading).

---

## A. Exploitability — UNKNOWN pending the source reading; both possible answers are fixed in advance

**CONFIRMED.** The hand-off token is signed with a key that is a **public constant on this install**:

```
key = sha256( webhook_secret | (crm_app_key ?? crm_auth_token) | 'DishNet-Hybrid-JWT-v2-2026' )
    = sha256( '' | '' | constant )                          — on Uganda, measured
```

- `lib/JwtAuth.php:66–74` — the derivation; the constant is in this public repository.
- `public.php:495` — `$config = $store->load('kyc_config.json')`: the request-time `$config` is the
  store's `kyc_config` row and nothing else; `public.php` never calls `PluginConfig::load()`, so the
  vault cannot fill a value in. `crm_app_key` is set by **no** config loader at all (it exists only as
  the `??` fallback in the derivation).
- K (server, 26 Sep ~05:00 UTC, a read-only copy of that row): `webhook_secret=EMPTY`,
  `crm_auth_token=EMPTY`, `crm_app_key=EMPTY`.

So anyone can mint, offline, with no session, no OTP and no account, a token
`{sub: <any client id>, kind: 'app', accounts: [...], aud: 'data-report', exp: ...}` that verifies
under that key. **Minting is not the exploit; what the sibling does with it is.** Three branches, one
of which the reading will select:

| The sibling… | Exploitable through the token? |
|---|---|
| verifies the signature under the same derivation and then authorises `clientId` from the token's `sub`/`accounts` — what the comment at its `public.php:643–647` says (*"JWT is valid. SECURITY: … own valid JWT could set clientId=SOMEONE_ELSE in the URL and read"*, then `$urlClientId = trim($_GET['clientId'] ?? '')`) | **YES.** A forged `sub = victim` passes the 643–647 rule, which only stops a *legitimate* customer from widening `clientId`. |
| never reads the token and gates the page on a uCRM staff session | **No, for the token** — it would be decorative — but then a customer could never have opened the report, which contradicts the feature having worked; unlikely. |
| never reads the token and serves the page on `clientId` alone | **YES, and worse:** no forgery needed; the finding is the sibling's own access control. |

The rewritten command's D2 / D3 / D5 / D7 lines answer this mechanically and D-V / D-VI print the code.

## B. Affected data and actions — candidates CONFIRMED from the `clientId` lines; the exact list PENDING

Read directly from the sibling's `public.php` (the one part of the first run that used no `--include`):

- `drFetchClientServices(string $clientId)` (≈216–232) → uCRM `GET /api/v1.0/clients/services?clientId=X`
  with the **sibling's own `X-Auth-App-Key`** — the sibling holds a uCRM API credential and can read
  any client; which client it shows is its own authorisation decision.
- `?action=dr_raw_services&clientId=X` (≈243–246) → **the raw uCRM services JSON** for that client
  (service names, plans, prices, statuses, addresses — whatever uCRM returns).
- `getDishnetKitPlanMap(string $clientId)` (≈419–426) → kit/plan mapping.
- the customer report page itself (services, usage, kit; by the feature's name and its "Back to
  Portal" contract, likely invoices) and the report's own data files behind the same gate
  (`dr_*.json`, `wifi_router_map.json`, Starlink account data).

**Not reachable with this token (CONFIRMED):** the hybrid plugin's customer API and portal — the
customer verifier refuses a token without a known `kid` and with `aud = data-report`
(`tests/test_customer_session.php` pins `app_me` → 401 on the hand-off token) — the customer session,
and our store.

## C. Root cause — files and functions

Hybrid, **CONFIRMED**:

1. `lib/JwtAuth.php:59–74` — `fromConfig()` / `legacySecret()`: a key derived from two settings that
   are empty here, falling back to a constant.
2. `includes/api/api_customer_app.php:921–940` — `app_data_report_token` mints the hand-off with that
   key (600 s, `aud = data-report`, the session's own `sub`/`accounts`).
3. `tabs/customer_app/portal.php:7437–7447` — appends `&token=` to the sibling's URL.
4. History (`portal.php:7429–7435`, v4.12.29): the sibling was built to accept the *session* JWT under
   that derivation; Phase 2 moved sessions to a dedicated key set (E3-a) and kept the derivation for
   the hand-off only, because the sibling could not be changed from this repository.

Sibling, **PENDING**: its verifier and where it takes the key from (D-V, D-VIII), the 643–647 gate
(D-VI), whether `dr_raw_services` sits behind the same gate.

Underlying class: **a trust boundary between two plugins keyed on a value that was meant to be a secret
and is a constant when nothing is configured** — the same defect Phase 2 removed for customer sessions.

## D. Recommended architecture — ONE: a dedicated, generated hand-off key under `_dishnet_shared/`, `kid`/`iss`/`aud` on the token, `clientId` bound to the token on the sibling side

**Shape.**

- **Key:** 32 random bytes, hex, at `<plugins dir>/_dishnet_shared/handoff_jwt.json` =
  `{"keys": {"h1": "<hex>"}, "active": "h1", "created_at": "..."}`, mode 0640 — the exact pattern
  `lib/InternalAuth.php` already uses for `_dishnet_shared/internal_auth.json`, which
  `dishnet-data-report` 2.8.58+ already reads and verifies with `hash_equals` for five internal
  actions. **A separate file from `internal_auth.json`:** that secret *"must never gate anything a
  customer can reach"* (`InternalAuth.php:11–13`), and a browser-carried token is exactly that.
  **Created by the hybrid (the minter) on first mint; the sibling only reads it and refuses when it is
  absent** — no creation race.
- **Token** (hybrid mints, **120 s**, per click): header `{alg: HS256, typ: JWT, kid: h1}`; claims
  `iss = dishnet-hybrid:<plugin dir>`, `aud = data-report`, `kind = app`, `sub`, `accounts`, `iat`,
  `exp`, `jti`. The same `JwtAuth` class, a `forHandoff()` constructor beside `forCustomers()`.
- **Sibling verifies:** three segments; `alg === 'HS256'` pinned; **`kid` must name a key in the
  file — no `kid` is the legacy token and is refused**; HMAC compared with `hash_equals`; `iss`,
  `aud = data-report`, `kind = app`, `exp` with a few seconds' leeway; then **`clientId` ∈ {sub} ∪
  accounts** — the 643–647 rule, kept.

**Why this one.**

- It closes the forgery: the key is 256-bit random, never in source, never in a URL — only signed
  tokens travel. It is the model Phase 2 already applied to sessions, so it is understood, tested and
  documented here.
- It reuses the proven cross-plugin secret mechanism (same disk, same PHP user, first-use creation,
  `hash_equals`): no new infrastructure and **no runtime coupling** — the sibling verifies offline; no
  HTTP call from the sibling to the hybrid at page load.

**Rejected, with the reason.**

1. *Give the derivation entropy by setting `crm_auth_token` or `webhook_secret`.* Both are live
   credentials with other meanings: a manual `crm_auth_token` **overrides** the `ucrm.json` app key
   for every uCRM call (`lib/CrmApiClient.php:26,54`); a non-empty `webhook_secret` makes uCRM's
   header mandatory whenever one is sent (`webhook.php:608–626`). The derivation stays shared with
   every copy of the constant, and cannot be rotated.
2. *A redeem call-back* (the browser carries a nonce; the sibling redeems it from the hybrid over
   loopback with `X-DishNet-Internal-Auth`). Strongest in theory — single-use, revocable — but it adds
   a runtime dependency on resolving the hybrid's URL from inside the container (a recurring source of
   faults on this host: `:8443`, `crm_public_url`), a new hybrid endpoint and two more failure modes.
   Not needed to close the blocker.
3. *Fix the sibling's checks alone, under the constant key.* Insufficient: the key is public.

**Residual, accepted:** a 120-second token in a URL (browser history, referrer). Mitigations on the
sibling's page: `Referrer-Policy: same-origin`, `Cache-Control: no-store`; optional single use by
`jti` if the sibling keeps state.

## E. Required changes

**Hybrid (this repository):**

1. `lib/HandoffKey.php` (new): `ensure()` / `keys()` / `activeKid()` over
   `_dishnet_shared/handoff_jwt.json`, with `InternalAuth`'s path resolution and file semantics
   (`LOCK_EX`, 0640); never logged; not a config key, so never in any settings screen.
2. `lib/JwtAuth.php`: `forHandoff(int $ttl = 120)` — `kid`/`iss`/`aud` exactly as `forCustomers()`,
   the key set from `HandoffKey`. `legacySecret()` / `fromConfig()` stay **only** for
   `api/v2/router.php` (unreachable; its fate is a separate decision).
3. `includes/api/api_customer_app.php` `app_data_report_token`: mint with `forHandoff()`; TTL 120;
   claims unchanged plus `iss`/`aud`/`kid`.
4. `tabs/customer_app/portal.php`: unchanged behaviour (still appends `&token=` to the report link);
   the comment at 7429–7435 rewritten.
5. Version, docs/07 entry, the Sudan constraint (F).

**Data-report (the sibling; not in this repository — the exact patch is written from the D-V / D-VI
reading):**

6. Replace the key derivation in its verifier with a read of `_dishnet_shared/handoff_jwt.json`
   (`kid` → key); refuse a token without `kid` or with an unknown `kid`; add `iss` / `aud` / `kind`
   checks; keep `exp`; keep `hash_equals`; keep the 643–647 rule.
7. Remove every read of the hybrid's `kyc_config` / `webhook_secret` / `crm_auth_token`: the sibling
   must not depend on our settings at all.
8. On the report page: `Referrer-Policy: same-origin`, `Cache-Control: no-store`; the "Back to Portal"
   link carries **no** token (the portal is cookie-based since Phase 2 and reads no URL token).
9. `dr_raw_services`: confirm from the reading which gate protects it; if it is reachable on `clientId`
   alone, it needs the same authorisation as the page.

**Item 8 of the instruction — can the sibling accept a replacement shared secret?** Not without a code
change: its key is computed from our settings and a constant, not read from a configurable place
(**PENDING** confirmation by D-V / D-VIII). The change is item 6.

## F. Migration and rollout impact

- **Customer sessions: none.** Sessions are the Phase 2 cookie under the customer key set; the
  hand-off is a separate short token minted per click. Nobody signs in again; nothing stored expires.
- **Stored tokens: none exist.** Nothing to migrate.
- **Order** (both plugins on the same host, one maintenance window): **(1) the sibling first**,
  accepting the **new key only** — from that moment the report link fails for a customer until (2);
  **(2) the hybrid within minutes**; its first mint creates the key file. A token minted before (1) and
  used after it is at most ten minutes old and is refused (no `kid`) — correct. The alternative with no
  gap — the sibling accepting both legacy and new for a window — is **rejected**: it keeps the forgery
  open for the window and needs a third deploy to close it; the gap in the accepted order is minutes
  and the operator controls both deploys.
- **Permissions:** both plugins run as the same PHP user in the `ucrm` container (`internal_auth.json`
  proves it); the deploy command must show both can read the key file and nobody else can.
- **Sudan (installation A, `dishnet-hybrid-telecom` + its own data-report, unaudited):** this hybrid
  code would mint new-style tokens an un-updated data-report cannot verify — the usage-report link
  would stop working there until its data-report is updated; the alternative (a version gate that keeps
  minting legacy tokens on Sudan) keeps a forgeable token there. **Recommendation: deploy both plugins
  on Sudan the same way; until then the Sudan hybrid must not be updated to this version** — a
  deployment constraint, not a code branch.
- **Rollback:** the previous sibling file and the previous hybrid build; the key file is inert.
- **Interim available now, for the operator's decision only:** remove `&token=` from the portal link
  (`portal.php`, one line). Customers then cannot open the usage report until the fix (the sibling's
  page falls to its staff gate or refuses — the reading says which); it trades one screen's
  availability for closing the window. **Not** the config-entropy route (D, rejected 1).

## G. Tests required before deployment

**Hybrid (`tests/`, in the suite, both runs green):**

1. `HandoffKey`: created once (64 hex chars, 0640, under `_dishnet_shared`); a second call returns the
   same key; an unwritable directory → a clear exception, **never a fallback to a constant**. Control:
   delete the file → a new key, and every earlier token is refused.
2. `JwtAuth::forHandoff()`: header carries `kid`; claims carry `iss`, `aud = data-report`,
   `exp − iat = 120`; verifies under the file's key; **a token signed with `legacySecret()` is refused
   (`Unknown JWT key id`)**; `alg = none` / `HS512` refused; expired refused; wrong `aud` refused.
3. `app_data_report_token`: 200 only with a live customer session; `sub` and `accounts` equal the
   session's; **`legacySecret(` occurs zero times in `api_customer_app.php`** (the pin at
   `tests/test_customer_session.php:257` flips from `=== 1` to `=== 0`); the customer verifier and the
   API still refuse the hand-off token as a session.
4. Repository guard: no file but `api/v2/router.php` calls `JwtAuth::fromConfig` / `legacySecret`;
   `portal.php` sends the token on the report link only.
5. A fake data-report in the suite implementing the new verifier, end to end: sign in (fake OTP) →
   `app_data_report_token` → GET fake `public.php?clientId=<own>&token=` → 200; `clientId=<other>` →
   403; a token forged under the constant → 401; no token → the fake's staff gate.

**Sibling (on the server after its patch; read-only proofs with synthetic values, done by the deploy
command):**

6. A token minted by the live hybrid for the operator's own test account opens the report; the same
   token with `clientId` changed → 403; a token **forged offline under the constant key** (computed by
   the command, never printed) → 401; a token without `kid` → 401; `dr_raw_services` with a forged
   token → 401.
7. The key file: readable by the PHP user of both plugins, not world-readable, outside every public
   directory, and absent from every container-log line since the deploy (count 0).
8. Weakened copies, each caught: (a) the sibling's verifier with the `kid` check removed → 6's no-`kid`
   case fails; (b) the hybrid minting with `legacySecret()` → 2 fails.

---

*Nothing here is authorised to build. The source reading (docs/35 §1a, `bash scripts/phase2-verify.sh
--data-report`) comes first; then the operator decides D; then the two changes are built with G.*
