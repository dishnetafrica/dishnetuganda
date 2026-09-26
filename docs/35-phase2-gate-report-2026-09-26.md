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

- The data-report hand-off token (§1, §1a) — **SECURITY BLOCKER** (the operator's classification): the
  key is a public constant on this install (measured); whether a forgery opens another customer's report
  awaits the source reading; the analysis is docs/36. No change until the remediation is approved.
- Recorded, not Phase 2: the staff app's service worker is scoped to the plugin directory, so on a
  staff member's own browser it also fronts the customer pages. Staff devices only.
- Nothing else: the checkpoint's fourteen other security checks are green and live.

## 6. Final Phase 2 status

**BLOCKED on §1a (updated 26 Sep).** Authentication: green (5.18.38–5.18.40 live, L1–L8 passed).
Website: green (LIVE 05:02 UTC). Test suite: green (204 suites / 8,051 checks / 0 failed, two complete
runs). Data-report trust boundary: **security blocker** — the hand-off key is a public constant here;
the sibling's handling of it is read next. Production e-mail sign-in and lead refusal: **pending — the
commands did not run** (§1a). 16 of 19 items complete, 3 partial, 0 code defects. Verification command
rehearsed 15/15 across seven scenarios before the first run; the rewritten data-report mode 35/35 in
two consecutive runs.

## 7. Exact next action required from the operator

1. **The source reading** — read-only, one command; send back **the log file**, not a copy of the
   terminal (it prints code, masked: no key, code, token, e-mail or long number can appear):
   ```
   cd /opt/dishnet && git pull origin claude/study-this-jhe2eg && mkdir -p /root/dnb-verify
   bash scripts/phase2-verify.sh --data-report 2>&1 | tee /root/dnb-verify/verify-data-report-$(date -u +%Y%m%dT%H%M%SZ).log
   ```
2. **The two production sign-in tests**, each with a REAL value typed in place of the example — no
   angle brackets anywhere on the line:
   ```
   bash scripts/phase2-verify.sh --email you@yourdomain.com 2>&1 | tee /root/dnb-verify/verify-email-$(date -u +%Y%m%dT%H%M%SZ).log
   bash scripts/phase2-verify.sh --lead +2567XXXXXXXX      2>&1 | tee /root/dnb-verify/verify-lead-$(date -u +%Y%m%dT%H%M%SZ).log
   ```
   `you@yourdomain.com` is an e-mail address on a customer record you control: one e-mail goes to it,
   you type the code when asked, it is never printed. `+2567XXXXXXXX` is the phone number of a **lead**:
   the command checks that first and refuses to send if it is not one. (The pasted `<address>` and
   `<+2567…>` caused the `syntax error near unexpected token '2'`: bash treats `<` and `>` as
   redirections.)
3. **The decision on the remediation** (docs/36 §D) after the reading. **Nothing is deployed or
   changed until it is approved.**
4. Website: done (§4).
