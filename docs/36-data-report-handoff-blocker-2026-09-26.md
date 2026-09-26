# 36 — The data-report hand-off token: security-blocker analysis (26 September 2026)

**Status: READ-ONLY analysis. No patch, no deployment, no token-scheme change.** The operator
classified the finding of docs/35 §1 as a **security blocker**. The sibling plugin
`dishnet-data-report` 2.8.80 is not in this repository; its token path was read on the server on
26 September at 05:28 UTC with `scripts/phase2-verify.sh --data-report` (masked source, docs/35 §1b).
Every item below is **CONFIRMED** (read in this repository, or read in the sibling's source, or
measured on the server) unless marked **PENDING** — which now means only the extended read of two
paths that the first source read did not cover (§H).

---

## 0. The result in one paragraph

On this host the sibling **cannot verify the hand-off token at all**, so a forged token is refused —
and so is our legitimate one. Its verifier `drVerifyHybridJwt()` (`public.php:792–855`) opens
`<plugins dir>/dishnet-hybrid-telecom/data/plugin.sqlite3` — the **South Sudan** plugin's directory
and the pre-upgrade data location — and returns `null` if that file is absent (798–799); and even at a
store it can open it **refuses to derive a key when either input is empty** (830–832: *"Guard: if
either is empty, don't attempt — would collapse to attacker-predictable constant secret"*). The
"JWT is valid" block (642–713) is executable code, but on this install it is never reached. **The
exploit the blocker named — a forged token opening another customer's report — is therefore not
possible here.** What the reading establishes instead: (1) the portal's usage-report link is
**non-functional for every Uganda customer** (the sibling answers "Report not found"); (2) the
cross-plugin trust design is wrong — it keys on our uCRM credential and webhook secret and hard-codes
another installation's directory; (3) two of the sibling's *other* access paths still need to be read
(§H). Nothing here changes the rule: no change until approved.

## A. Exploitability — the instruction's eight items, answered from the source

| # | Question | Answer (public.php line numbers of the installed 2.8.80, sha256 `5d10b30ae54a…`) |
|---|---|---|
| 1 | Where is the token read? | Once: `622` `$token = trim($_GET['token'] ?? '')`, in "MODE 1 — TOKEN-BASED ACCESS". |
| 2 | Is it decoded/verified? | Yes, two ways. (a) `628`: `hash_equals` against every kit's `report_token` in `sl_kits.json` (the share-link feature) → `drServeReport()`. (b) if it has two dots, `641` → `drVerifyHybridJwt()`: three segments (793–794); opens **`dishnet-hybrid-telecom/data/plugin.sqlite3`** (797–799, `null` if absent); reads `kyc_config` row 0 (810); derives `sha256(webhook_secret \| crm_app_key ?? crm_auth_token \| constant)` (825–834) **only if both inputs are non-empty** (830–832); HMAC-SHA256 always — the header's `alg` is never trusted (838); `hash_equals` (842); `exp` with 5 s leeway (852). The caller then requires `sub` and `kind === 'app'` (642). **No `aud`, `iss`, `kid` or `jti` check.** |
| 3 | Where is `clientId` read? | `246` inside `drFetchClientServices()` (the `dr_raw_services` dump); `647` in MODE 1B; `1143` and `1160` in MODE 2 ("view as client"); `client.php` (session path); `dr_wifi_change.php:3616, 3785` as `client_id`. |
| 4 | Is `clientId` independently authorised? | MODE 1B: **bound to the token** — `649–651` refuse with `drNotFound()` unless it equals the token's `sub`. Only `sub`: a multi-account customer's other ids are refused. (The first run's mechanical D5 line said "none": a heuristic miss — the comparison is `$urlClientId !== $jwtClientId`; the code is the evidence.) MODE 2: **PENDING** (§H). |
| 5 | Every endpoint reachable with the value? | With a **valid** token: the branded subscription view (`client.php` through the MODE 2 pipeline, 654–713+): the customer's kits from Finance's `sl_kits.json` matched on `crm_client_id == sub` (676–682), usage from `sl_usage.json` (own + Finance), plan map through the sibling's uCRM app key (`getDishnetKitPlanMap`, `getDishnetPlanFromService` → `drFetchClientServices`), `sl_svc_cache.json`, `dr_plan_cache.json`; `dr_raw_services` only when the URL's `clientId` equals `sub` (245–246); 404 when the client has no kits (684–688). The 55 dispatched actions and the admin tabs are MODE 2 — a uCRM session cookie or the internal-auth header (1002–1018) — and the token opens none of them. |
| 6 | Can an attacker change `clientId` to another customer? | With a genuinely valid token: **no** (649–651). With a forged token: **not on this host** (item 7). |
| 7 | Is "JWT is valid" executable or stale? | **Executable but unreachable here.** The block runs only when `drVerifyHybridJwt()` returns claims; on this host it returns `null` before any cryptography, for two independent reasons: the path it opens is the South Sudan plugin's (this host's hybrid is `dishnet-hybrid-sudan`, store in `.dishnet-hybrid-sudan-data` — K), and the empty-input guard refuses the constant key regardless. Consequence: a forged token is refused; **our legitimate hand-off token is refused too; the portal's usage-report link answers "Report not found"** (its own comment 614–615: *"If neither works, only THEN do we drNotFound()"*; the fall-through line is in 714–767, covered by §H). |
| 8 | Can it accept a replacement shared secret? | **Not by configuration** — the key is computed in code from our store. **But the reading pattern is already in its code:** 1002–1010 read `_dishnet_shared/internal_auth.json` and compare with `hash_equals` for five internal actions. The change is to make `drVerifyHybridJwt()` take its key from a hand-off key file under `_dishnet_shared/` (by `kid`) instead of our SQLite (796–834), and to check `iss`/`aud` (§E). |

**Confirmed exploitability: NO, via forgery, on this host.** A forged token — and every other token
signed under the constant derivation — is refused, because the sibling refuses to verify anything
under a constant key and looks for a store that is not this install's.

**Installation A (South Sudan), read only from the sibling's own comment (771–777), not audited:**
production there holds a 32-character `webhook_secret` and a 64-character `crm_auth_token`, so the
derived key has entropy and forgery is not possible either. What the verifier *does* accept there is
**any legacy-signed JWT with `kind = app` within its `exp`** — no `aud`, `iss` or `kid` — i.e. a
replayed customer-app session token (the pre-Phase-2 30-day scheme that installation A's own hybrid
still issues, as far as this repository knows) doubles as the report credential. A leak, not a forgery.

## B. Affected data and actions (CONFIRMED)

With a valid token (none can be valid on this host today): the customer's Starlink kits and their
status (`sl_kits.json` — Finance's register), per-kit usage (`sl_usage.json`), the uCRM service plan
and per-kit plan map (uCRM `GET /api/v1.0/clients/services?clientId=X`, read with the **sibling's own
uCRM app key** — `ucrm.json` `pluginAppKey` is set), and the raw uCRM services JSON through
`dr_raw_services` when `clientId == sub`. Not reachable through the token: the 55 dispatched actions,
the admin tabs (all MODE 2), the hybrid plugin's own API and portal (they refuse this token — pinned by
`tests/test_customer_session.php`), the customer session, our store.

## C. Root cause — files and functions (CONFIRMED)

Hybrid:

1. `lib/JwtAuth.php:59–74` — `fromConfig()` / `legacySecret()`: a key derived from two settings that
   are empty here, falling back to a constant.
2. `includes/api/api_customer_app.php:921–940` — `app_data_report_token` mints the hand-off with it.
3. `tabs/customer_app/portal.php:7437–7447` — appends `&token=` to the sibling's URL.

Sibling:

4. `public.php:797–798` — the verifier hard-codes **`dishnet-hybrid-telecom/data/plugin.sqlite3`**:
   another installation's plugin directory and the pre-upgrade data location. On this host it does not
   resolve, so the integration was never wired to this install.
5. `public.php:825–834` — it derives the key from **our** settings and the constant (with the
   empty-input guard that saves it from the constant key).
6. `public.php:642–652` — accepts `kind`, `sub`, `exp`; no `aud`/`iss`/`kid`; binds `clientId` to
   `sub` only.

Underlying class: **a trust boundary between two plugins keyed on another plugin's live credentials
and directory layout.** It fails closed here (good) and silently (bad): nothing tells either side that
the report link has never worked on Uganda.

## D. Recommended architecture — ONE: a dedicated, generated hand-off key under `_dishnet_shared/`, `kid`/`iss`/`aud` on the token, `clientId` bound to the token on the sibling side

Unchanged by the reading, and now also the only fix that makes the feature work on Uganda without
putting our uCRM credential or webhook secret into a signing key.

**Shape.**

- **Key:** 32 random bytes, hex, at `<plugins dir>/_dishnet_shared/handoff_jwt.json` =
  `{"keys": {"h1": "<hex>"}, "active": "h1", "created_at": "..."}`, mode 0640 — the pattern
  `lib/InternalAuth.php` and the sibling's own 1002–1010 already use for `internal_auth.json`. **A
  separate file from `internal_auth.json`:** that secret *"must never gate anything a customer can
  reach"* (`InternalAuth.php:11–13`), and a browser-carried token is exactly that. **Created by the
  hybrid (the minter) on first mint; the sibling only reads it and refuses when it is absent.**
- **Token** (hybrid mints, **120 s**, per click): header `{alg: HS256, typ: JWT, kid: h1}`; claims
  `iss = dishnet-hybrid:<plugin dir>`, `aud = data-report`, `kind = app`, `sub`, `accounts`, `iat`,
  `exp`, `jti`. The same `JwtAuth` class, a `forHandoff()` constructor beside `forCustomers()`.
- **Sibling verifies:** three segments; `kid` must name a key in the file (**no `kid` = the legacy token
  = refused**); HMAC-SHA256 with `hash_equals`; `iss`, `aud = data-report`, `kind = app`, `exp` with
  leeway; then **`clientId` ∈ {sub} ∪ accounts** (649–651 extended to the account list).

**Why this one.**

- It closes the design flaw: the key is 256-bit random, never in source, never in a URL, and
  independent of our settings, our directory name, our data location and our SQLite schema — the four
  things the current verifier depends on and two of which are wrong on this host.
- It is the model Phase 2 already applied to sessions, and the file mechanism both plugins already
  run: no new infrastructure, **no runtime coupling** (the sibling verifies offline).

**Rejected, with the reason.**

1. *Give the derivation entropy by setting `crm_auth_token` or `webhook_secret`.* Both are live
   credentials with other meanings: a manual `crm_auth_token` **overrides** the `ucrm.json` app key
   for every uCRM call (`lib/CrmApiClient.php:26,54`); a non-empty `webhook_secret` makes uCRM's
   header mandatory whenever one is sent (`webhook.php:608–626`). And it would not work here anyway:
   the sibling reads `dishnet-hybrid-telecom/…`, not this install's store (797–798).
2. *A redeem call-back* (nonce in the browser; the sibling redeems it from the hybrid over loopback
   with `X-DishNet-Internal-Auth`). Single-use and revocable, but it adds a runtime dependency on
   resolving the hybrid's URL from inside the container (a recurring fault source here), a new hybrid
   endpoint and two more failure modes. Not needed.
3. *Point the sibling at this install's store and keep the derivation.* Keeps the trust keyed on our
   live credentials; on Uganda those are empty, so the guard would still refuse — the feature would
   stay dead unless we set them (rejected 1).

**Residual, accepted:** a 120-second token in a URL (history, referrer). Mitigations on the sibling's
page: `Referrer-Policy: same-origin`, `Cache-Control: no-store`; optional single use by `jti`.

## E. Required changes

**Hybrid (this repository):**

1. `lib/HandoffKey.php` (new): `ensure()` / `keys()` / `activeKid()` over
   `_dishnet_shared/handoff_jwt.json`, with `InternalAuth`'s path resolution and file semantics
   (`LOCK_EX`, 0640); never logged; not a config key.
2. `lib/JwtAuth.php`: `forHandoff(int $ttl = 120)` — `kid`/`iss`/`aud` as `forCustomers()`, keys from
   `HandoffKey`. `legacySecret()` / `fromConfig()` stay **only** for `api/v2/router.php`.
3. `includes/api/api_customer_app.php` `app_data_report_token`: mint with `forHandoff()`; TTL 120.
4. `tabs/customer_app/portal.php`: behaviour unchanged; the comment at 7429–7435 rewritten.
5. Version, docs/07 entry, the installation-A constraint (F).

**Data-report (the sibling; the exact patch, from the reading):**

6. `public.php:796–834` (inside `drVerifyHybridJwt()`): delete the SQLite open, the `kyc_config` read
   and the derivation; instead decode the header, require `kid`, look it up in
   `_dishnet_shared/handoff_jwt.json` (the same two candidate paths as 1005–1006), refuse when the file
   or the `kid` is absent. Keep 837–842 (HMAC + `hash_equals`) and 852 (`exp`). After 849 add
   `iss === 'dishnet-hybrid:<the hybrid's directory name>'` and `aud === 'data-report'` checks.
7. `public.php:647–652`: accept `clientId` when it equals `sub` **or** any id in `accounts`.
8. Delete the `dishnet-hybrid-telecom` path and the header comment 768–791 that documents reading our
   store: the sibling must not depend on our settings, directory or schema at all.
9. The report page: `Referrer-Policy: same-origin`, `Cache-Control: no-store`; the "Back to Portal" link
   carries **no** token (the portal is cookie-based since Phase 2 and reads no URL token).
10. `dr_raw_services`: it is in the admin whitelist (1101) and inside `drFetchClientServices()` guarded
    by `clientId == sub` on the token path (245–246) — no change needed for the token path; §H covers
    MODE 2.

## F. Migration and rollout impact

- **Customer sessions: none.** The hand-off is a separate short token minted per click.
- **Stored tokens: none exist.**
- **On Uganda there is no working feature to protect during rollout:** the link already answers 404
  for everyone. Deploy the sibling first, then the hybrid; the first mint creates the key file. No
  customer loses anything in between; after both, the link works for the first time here.
- **Permissions:** both plugins run as the same PHP user in the `ucrm` container (`internal_auth.json`
  proves it); the deploy command must show both can read the key file and nobody else can.
- **Installation A (South Sudan):** its hybrid is a different plugin (`dishnet-hybrid-telecom`) whose
  tokens the *current* sibling verifier accepts. If the same `dishnet-data-report` build is deployed
  there, its report link breaks until that hybrid mints the new-key token too. **Recommendation: this
  sibling change is Uganda's until installation A is audited; do not deploy it there on its own.**
- **Rollback:** the previous sibling file and the previous hybrid build; the key file is inert.
- **Interim on Uganda:** nothing to shut — the token path is already closed. Optional and cosmetic: hide
  the usage-report entry in the portal until the fix, so customers do not meet a 404.

## G. Tests required before deployment

**Hybrid (`tests/`, in the suite, both runs green):**

1. `HandoffKey`: created once (64 hex, 0640, under `_dishnet_shared`); a second call returns the same
   key; an unwritable directory → a clear exception, **never a fallback to a constant**; delete the file
   → a new key, every earlier token refused.
2. `JwtAuth::forHandoff()`: header carries `kid`; claims carry `iss`, `aud = data-report`,
   `exp − iat = 120`; verifies under the file's key; **a token signed with `legacySecret()` is refused**;
   `alg = none` / `HS512` refused; expired refused; wrong `aud` refused.
3. `app_data_report_token`: 200 only with a live customer session; `sub`/`accounts` equal the
   session's; **`legacySecret(` occurs zero times in `api_customer_app.php`** (the pin at
   `tests/test_customer_session.php:257` flips from `=== 1` to `=== 0`); the customer verifier and the
   API still refuse the hand-off token as a session.
4. Repository guard: no file but `api/v2/router.php` calls `JwtAuth::fromConfig` / `legacySecret`;
   `portal.php` sends the token on the report link only.
5. A fake data-report in the suite implementing §E.6–7, end to end: sign in (fake OTP) →
   `app_data_report_token` → GET fake `public.php?clientId=<own>&token=` → 200; `clientId=<other>` →
   403/404; `clientId=<second account>` → 200; a token forged under the constant → refused; no `kid` →
   refused; no token → the fake's staff gate.

**Sibling (on the server after its patch; read-only proofs with synthetic values, by the deploy
command):**

6. A token minted by the live hybrid for the operator's own test account opens the report; the same
   token with `clientId` changed → refused; a token **forged offline under the constant key** (computed
   by the command, never printed) → refused; a token without `kid` → refused; `dr_raw_services` with a
   forged token → refused.
7. The key file: readable by the PHP user of both plugins, not world-readable, outside every public
   directory, absent from every container-log line since the deploy (count 0).
8. The string `dishnet-hybrid-telecom` and the words `kyc_config`, `webhook_secret`, `crm_auth_token`
   occur **zero** times in the patched `public.php` (the dependence is gone, not moved).
9. Weakened copies, each caught: (a) the sibling's verifier with the `kid` check removed → 6's no-`kid`
   case fails; (b) the hybrid minting with `legacySecret()` → 2 fails.

## H. The extended read — 26 Sep 05:40 UTC — now READ (`verify-data-report-…T054045Z`, masked)

**1. The hard-coded path is absent here — the JWT verify path is confirmed dead (D-XII-b).** On this
host: `dishnet-hybrid-telecom` **ABSENT**, `.dishnet-hybrid-telecom-data` **ABSENT**;
`dishnet-hybrid-sudan` and `.dishnet-hybrid-sudan-data` present (owner `1000:1000`, mode 0775). The
verifier opens `<plugins>/dishnet-hybrid-telecom/data/plugin.sqlite3` (798) and returns `null` at 799
when that file is absent — which it is. So §0 is confirmed by execution of the path logic, not only by
reading it: **no hand-off token, forged or genuine, can verify here; the usage-report link 404s for
every Uganda customer.**

**2. MODE 2 "View as Client" trusts `?clientId=` from any session holder (NEW — the sibling's own, not
the hand-off).** Read at `public.php:1018–1153`. The client-portal block runs for **any** request
carrying a `PHPSESSID` or `nms-session` cookie (1018) that is not an admin action or admin tab (1135).
Inside it (1143–1152):

    $drViewAsClientId = trim($_GET['clientId'] ?? '');
    if ($drViewAsClientId !== '' && ctype_digit($drViewAsClientId)) {
        $pUser = ['clientId' => $drViewAsClientId, 'crmClientId' => $drViewAsClientId,
                  'isClient' => true, '_view_as_client' => true, ...];
    }

The requested `clientId` is taken from the URL and turned into a synthetic authenticated user **with no
check that it is the session's own client.** The comment (1137–1142) frames it as an admin "View as
Client" feature, but the code gates only on *having a session cookie*, not on being an admin. So the
report a session holder is shown is **whatever `clientId` they put in the URL.** For DishNet staff this
is expected (they may see every client anyway). It becomes a horizontal disclosure only **if a
non-admin uCRM client-zone login exists for these customers** — a session that is `isClient = true` but
should see only its own record. **Whether such logins exist is the operator's to confirm**: DishNet's
customers sign in through our own OTP portal, not necessarily through uCRM's client zone. This is
pre-existing in the sibling, unrelated to Phase 2 and to the hand-off token, and is **not** fixed by
§D/§E; it is the sibling's MODE 2 and would need its own change (bind the "view as client" override to
an admin session, or to `clientId == the session's own client`).

**3. The `dr_wifi_*` action handlers appear reachable without a session (consistent with SAFETY.md;
the sibling's own).** `dr_wifi_change.php` is `require`d at `public.php:6101` at top level, after the
session-gated MODE 2 block, and its handlers (e.g. `dr_wifi_get_config` at 973, `dr_wifi_change_password`,
`dr_wifi_test_block`) run at require-time when `$action` matches; the file itself carries **no** session,
401 or 403 check. A request with no session cookie skips the 1018 block and reaches 6101, so the
handlers run for it — exactly what `SAFETY.md:380–383` records for this plugin ("session-less calls fall
through to the action handlers"), and what our own `StarlinkBlockBridge` depends on (it sends the
internal-auth header, but the header is one of two accepted paths, not required). **Full confirmation
needs the control flow between 1260 and 6100, which this read did not print**, so this is stated as
strongly indicated, not proven to the last line. It is pre-existing, load-bearing for the Starlink
block/unblock feature, and entirely the sibling's — **not** the hand-off token, and **not** changed by
§D/§E. It is the sibling's most consequential access-control question and, if the operator wants it
closed, is its own separate piece of work (gate the WiFi actions on the internal-auth header or a real
admin session, without breaking the bridge).

**Net:** the hand-off blocker is answered (not exploitable here; the feature is simply dead). Findings 2
and 3 are the sibling's own pre-existing access control, surfaced by this read, and are recorded for the
operator to weigh separately from the hand-off remediation. None of the three is authorised to build.

---

*Nothing here is authorised to build. Sequence: the operator's decision on §D (the hand-off fix) →
separately, whether to raise findings §H.2 and §H.3 with the sibling's owner → the changes built with
§G. Findings §H.2/§H.3 are pre-existing and independent; they neither block nor are fixed by the
hand-off change.*
