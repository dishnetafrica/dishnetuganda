# Phase 2 gate report — 26 September 2026

Follows `34-phase2-checkpoint-2026-09-26.md`. Nothing was deployed, rolled back, migrated or changed
in plugin or website code for this report. The only new file is a verification command,
`scripts/phase2-verify.sh`, which the operator runs on the server.

## 1. Data-report hand-off token — finding

**What our side does (read in the repository, live on 5.18.40).** When a signed-in customer opens the
usage report, the portal calls `app_data_report_token`, which mints a token for **that customer's own
`sub`**, ten minutes long, claims `sub / kind / phone / name / accounts / aud=data-report`, signed with
the **legacy derivation** `sha256(webhook_secret | crm_app_key-or-crm_auth_token | constant)`, and the
browser is sent to `dishnet-data-report/public.php?clientId=<id>&kit=…&token=<that token>`.

**Why it is legacy-signed.** Before Phase 2 the portal appended the customer's **session JWT** itself
to that URL (`portal.php` of 5.18.37: `if (this._token) url += '&token=' + …`). The sibling plugin was
therefore built to accept a token signed under that derivation; Phase 2 kept the shape so the report
kept opening, and pinned it to a separate audience and a short life.

**What is not known from this repository.** `dishnet-data-report` is not in this repository. Whether
it verifies the signature, with which inputs, whether it compares `clientId` with the token's `sub`,
whether it checks `exp` or `aud`, or whether it serves the page on `clientId` alone, cannot be read
from here. Nor can this session read whether `crm_auth_token` / `crm_app_key` / `webhook_secret` are
set on the Uganda install (this session has no server access; `webhook_secret` was recorded empty on
15 September).

**Forgeability, conditional.** If both inputs are empty the key is a constant anyone can read in the
source, and a token for any `sub` can be minted by anyone. Whether that matters depends on the
sibling: if it checks `sub` against `clientId` under that key, a forgery opens another customer's
usage report; if it ignores the token, the token is decorative and the exposure is the sibling's own
access control, not the hand-off.

**Measured next, read-only:** `bash scripts/phase2-verify.sh --data-report` answers, on the server,
with every excerpt masked: whether the sibling mentions the token, verifies a signature
(`hash_hmac`/`hash_equals`/`JwtAuth`), references the hybrid plugin's key inputs or constant, reads
`sub`/`accounts`, checks `exp`, checks `aud`, and which lines read `clientId`; and whether the three
inputs are set or empty in the store copy the hand-off actually hashes (presence only).

**Recommended fix, contingent on that reading:**
- sibling verifies signature + `sub` with the shared derivation, inputs empty → forgeable → give the
  hand-off its own generated shared secret (a change in both plugins), or, as an interim on this
  install only, set `crm_auth_token` so the derivation has entropy — an operator decision, because
  it is also the uCRM API credential;
- sibling ignores the token → drop the token from the URL (one line in `portal.php`) and raise the
  sibling's own access control as a separate finding;
- sibling verifies signature and `exp` but not `sub` → the fix is in the sibling, not here.

### 1a. The first reading (26 Sep ~05:00 UTC) — what stood, what was invalid, and the classification

**What stood.**

- D1: `dishnet-data-report` **2.8.80 is installed** (top level: `backup.php`, `client.php`, `cron*.php`,
  `dr_wifi_change.php`, `data/`, …; `lib/KitRegistryWriter.php`).
- K: in the store row `public.php` loads as `$config` — exactly what `app_data_report_token` hashes —
  **`webhook_secret` EMPTY, `crm_auth_token` EMPTY, `crm_app_key` EMPTY.** The hand-off key on this
  install is therefore `sha256('||' . constant)`, the constant being public in this repository: **a
  hand-off token for any `sub` can be minted by anyone.** (The snippet's `PluginConfig::read` line
  failed with an `ArgumentCountError` — a wrong call, fixed; it does not touch the store reading, which
  is the one that matters, because `public.php` never calls `PluginConfig::load()`.)
- The `clientId` lines of its `public.php`, read directly: `drFetchClientServices(string $clientId)`
  calling uCRM `GET /api/v1.0/clients/services?clientId=X` (≈216–232); `?action=dr_raw_services&clientId=X`
  dumping the raw uCRM response (≈243–246); `getDishnetKitPlanMap(clientId)` (≈419–426); and at
  **643–647** the comment *"JWT is valid. SECURITY: if ?clientId= was also passed in the URL … own valid
  JWT could set clientId=SOMEONE_ELSE in the URL and read"* followed by
  `$urlClientId = trim($_GET['clientId'] ?? '')`.

**What was INVALID, and why.** The run's mechanical lines D3–D7 read *no signature verification, no
key inputs, never reads sub, no exp, no aud*, while its own occurrence table read `JwtAuth 2`,
`hash_hmac 1`, the constant `1`, `webhook_secret 5`, `crm_auth_token 5`, `'sub' 4`, `'exp' 2`, `'aud' 1`
— and the "token-handling lines" section printed nothing. The cause is the container's `grep`: it does
not support `--include`. Every command that passed `--include='*.php'` as an option failed (stderr was
discarded) and counted 0; the occurrence loop passed it after `--`, i.e. as a file name, so it searched
**every** file recursively, data files included (hence `accounts 1122`, `token 267`). **The D3–D7
verdicts and the occurrence counts are withdrawn.** The two direct-file reads (the `token` count on
`public.php`, the `clientId` listing) used no `--include` and stand.

**Classification (operator, 26 Sep): SECURITY BLOCKER.** With the key a public constant, whether a
forged token opens another customer's report depends only on what the sibling does with `clientId` and
the token — and the 643–647 comment says it trusts the token and binds `clientId` to it, the branch in
which the forgery matters most. The analysis (exploitability, affected data, root cause, the
recommended architecture, changes, rollout, tests) is **docs/36**; nothing is patched, deployed or
changed until the remediation is approved.

**The command is rewritten** (`scripts/phase2-verify.sh --data-report`). The inspection now runs **in
PHP inside the container**, over PHP files only, with no shell grep, and prints — masked — the PHP
inventory with per-file counts and content hashes; `public.php`'s includes; every request parameter it
reads; the action names it dispatches on; **every function that computes an HMAC, in full**; ±15 lines
around every `JwtAuth`/`verify(` mention; ±12 around every read of the token parameter; **±70 lines
around "JWT is valid"**; every `clientId` line; where its key inputs come from (±2 lines); the claim
keys it reads; its gates; the same for `client.php`, `dr_wifi_change.php` and `lib/`; the sibling's own
uCRM credential (presence) and its data-directory names; then D2–D8 computed in PHP. K additionally
reads the files-plus-vault view and reports whether a plain `kyc_config.json` (or `.migrated`) exists in
either hybrid data directory — the sibling may read such a file rather than our store. Masking: the
shared constant → `<the shared constant>`; a credential-shaped assignment loses its value; any run of
40+ characters, any 24–39-character letters-and-digits run, any e-mail and any 7+-digit number →
`<redacted>` / `<email>` / `<digits>`; no data file is opened. Rehearsed against two fake siblings —
one modelled on the evidence (constant derivation read from the hybrid's settings, the 643–647 gate,
`dr_raw_services`, an internal-auth gate, planted key literals, an e-mail, a phone number) and one that
ignores the token: **35/35 in two consecutive runs**; the block is asserted to contain no shell grep and
no `--include=`.

**The other two production tests did not run.** The `--email <address>` and `--lead <+2567…>` lines were
pasted with the placeholders literally; bash reads `<` and `>` as redirections and answered
`syntax error near unexpected token '2'`. Nothing was sent to anyone. Re-issued in §7 with the
substitution spelled out.

### 1b. The source reading — 26 Sep 05:28 UTC (`verify-data-report-20260926T052852Z.log`, masked)

**The forgery is NOT exploitable on this host — confirmed from the sibling's code.** Its verifier
`drVerifyHybridJwt()` (`public.php:792–855`, file sha256 `5d10b30ae54a…`) opens
**`<plugins dir>/dishnet-hybrid-telecom/data/plugin.sqlite3`** — the South Sudan plugin's directory
and the pre-upgrade data location — and returns `null` when that file is absent (798–799); on this host
the hybrid is `dishnet-hybrid-sudan` with its store in `.dishnet-hybrid-sudan-data` (K). And even at a
store it can open, it **refuses to derive a key when either input is empty** (830–832: *"Guard: if
either is empty, don't attempt — would collapse to attacker-predictable constant secret"*). The
"JWT is valid" block (642–713) is live code, executed only when the verifier returns claims — which on
this install it never does. So a forged token is refused, **and so is our legitimate hand-off token**:
the portal's usage-report link answers "Report not found" for every Uganda customer (its own comment
614–615: *"If neither works, only THEN do we drNotFound()"*).

What the verifier does check: three segments; HMAC-SHA256 always (the header's `alg` is never trusted);
`hash_equals`; `exp` with 5 s leeway; the caller requires `sub` and `kind = app` (642) and binds the
URL's `clientId` to `sub` (647–652 — the first run's mechanical D5 "none" was a heuristic miss). **No
`aud`, `iss`, `kid` or `jti`.** The token is read once (622). `dr_raw_services` is inside
`drFetchClientServices()` behind `clientId == sub` on the token path (245–246) and in the admin
whitelist (1101). The sibling already reads a shared secret from `_dishnet_shared/internal_auth.json`
with `hash_equals` (1002–1010), so a hand-off key file under `_dishnet_shared/` is a pattern its own
code already has. Installation A (South Sudan) is described by the sibling's comment (771–777) as
holding a 32-character `webhook_secret` and a 64-character `crm_auth_token`: entropy there, no forgery;
a replayed legacy `kind = app` JWT within its `exp` is accepted there. Not audited.

**Reclassification, for the operator to confirm:** the exploit as named — a forged token opening another
customer's report — does not exist on Uganda. What stands: the usage-report feature is **non-functional
here** (404 for everyone), the cross-plugin design keys trust on our live credentials and another
installation's directory, and two of the sibling's *other* paths still need reading (docs/36 §H):
whether a customer with a uCRM client-zone session can use the MODE 2 `?clientId=` override, and whether
an anonymous `?action=dr_wifi_…` request is refused before `dr_wifi_change.php` (which contains no gate
word at all) runs. The command gained those regions (D-VI-b/c/d, the dispatch of `dr_wifi_change.php`,
and D-XII-b: which hybrid directories exist on this host); rehearsed **41/41 in two consecutive runs**.
The full analysis is **docs/36**.

**The e-mail and lead runs (05:29 UTC) used the example values literally** (`you@yourdomain.com`,
`+2567XXXXXXXX`) and stopped correctly at E1/L1 — *matches no record, nothing was sent*. Still pending;
§7 says what to type. The lead mode printed a `sed` error (a look-ahead in a POSIX expression, on an
unused variable) — fixed.

## 2. Production e-mail sign-in — PENDING

Code path proved end to end in a sandbox with a real SMTP dialogue (the checkpoint, §8). The
production test is prepared: `bash scripts/phase2-verify.sh --email <address>`, where the address is
on a customer record the operator controls. It sends one e-mail, the code is typed on the server and
never printed, and it asserts the cookie flags, the portal (or its consent step), logout revocation,
and the code's absence from every log and row. It stops before sending if the address matches no
eligible record. Rehearsed.

## 3. Production eligibility refusal — PENDING

Prepared: `bash scripts/phase2-verify.sh --lead <phone of a LEAD>`. It first asks the staff lookup
whether the record is a lead the gate would refuse and **stops if it is not**, so no customer can be
contacted. Then it knocks on the door once, expects the uniform answer, and proves in a store copy:
one `otp_ineligible` audit row, no `otp_sent`, no pending code, no notification, nothing queued.
Rehearsed, including the guard.

## 4. Website deployment status

Committed: `466e4fc` (176 links on 57 pages → the portal sign-in; guard and README updated; the
site's own checks pass). **Live status unknown from this session**: `dishnetuganda.com` is not
reachable from here. `bash scripts/phase2-verify.sh --website` reports it from the server (the
5.18.40 command's stage W does the same). Until the EasyPanel redeploy (project `web`, app
`web-uganda`) the live site still sends customers to uCRM's login.

**LIVE — confirmed 26 Sep 05:02 UTC** by `--website` on the server: the home page carries 3 links to
the DishNet portal sign-in and 0 to uCRM's login; last website commit `466e4fc`.

## 5. Remaining security issues

- The data-report hand-off token (§1, §1a, §1b) — classified a **security blocker**; the source reading
  shows the forgery is **not exploitable on this host** (the sibling refuses to verify under a constant
  key and looks for another installation's store). Open: the feature is dead here; the design (docs/36
  §D) awaits approval; two other sibling paths await the extended read (docs/36 §H). No change until
  approved.
- Recorded, not Phase 2: the staff app's service worker is scoped to the plugin directory, so on a
  staff member's own browser it also fronts the customer pages. Staff devices only.
- Nothing else: the checkpoint's fourteen other security checks are green and live.

## 6. Final Phase 2 status

**Updated 26 Sep 05:45 UTC.** Authentication: green (5.18.38–5.18.40 live, L1–L8 passed). Website:
green (LIVE 05:02 UTC). Test suite: green (204 suites / 8,051 checks / 0 failed, two complete runs).
Data-report trust boundary: the named exploit is **not possible on this host** (§1b, from source); the
feature is non-functional here; the design fix (docs/36 §D) awaits the operator's decision; two other
sibling paths await the extended read (docs/36 §H). Production e-mail sign-in and lead refusal:
**pending — both runs used the example values** (§1b). 16 of 19 items complete, 3 partial, 0 code
defects. Data-report mode rehearsed 41/41 in two consecutive runs.

## 7. Exact next action required from the operator

1. **The extended source read** — read-only, one command; the log file is written to
   `/root/dnb-verify/` (the path is the `tee` target; pasting the terminal is acceptable for this mode,
   its output is masked):
   ```
   cd /opt/dishnet && git pull origin claude/study-this-jhe2eg && mkdir -p /root/dnb-verify
   bash scripts/phase2-verify.sh --data-report 2>&1 | tee /root/dnb-verify/verify-data-report-$(date -u +%Y%m%dT%H%M%SZ).log
   ```
2. **The two production sign-in tests, with YOUR values** — not the examples. Replace the whole word
   after `--email` with an e-mail address that is on one of your customer records in uCRM (the record you
   used for the 26 Sep 02:59 WhatsApp sign-in test will do, if it has an e-mail), and the whole word after
   `--lead` with the phone number of a record that is a **lead** in uCRM (Clients → filter Leads), in
   international form:
   ```
   bash scripts/phase2-verify.sh --email <your address here>   2>&1 | tee /root/dnb-verify/verify-email-$(date -u +%Y%m%dT%H%M%SZ).log
   bash scripts/phase2-verify.sh --lead  <the lead's +256… number>  2>&1 | tee /root/dnb-verify/verify-lead-$(date -u +%Y%m%dT%H%M%SZ).log
   ```
   Type the value itself, with no `<` `>` around it. One e-mail goes to the address; you type the code
   when asked; it is never printed. The lead command refuses to send unless the record is a lead.
3. **The decision on the remediation** (docs/36 §D) after the extended read. **Nothing is deployed or
   changed until it is approved.**
4. Website: done (§4).
