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

## 5. Remaining security issues

- The data-report hand-off token (§1) — the only open item; a decision after the reading.
- Recorded, not Phase 2: the staff app's service worker is scoped to the plugin directory, so on a
  staff member's own browser it also fronts the customer pages. Staff devices only.
- Nothing else: the checkpoint's fourteen other security checks are green and live.

## 6. Final Phase 2 status

**READY FOR FINAL VERIFICATION.** 16 of 19 items complete, 3 partial (operator inputs, the §1
decision, Phase 4 content readers), 0 failed; 0 code defects; 204 suites / 8,051 checks / 0 failed
in two complete runs. Verification command rehearsed 15/15 across seven scenarios (fake sibling
plugin, SMTP sink, fake Evolution; docker as a passthrough stub), including the two guards that stop
before anything is sent.

## 7. Exact next action required from the operator

1. On the server, pull once, then run the read-only inspection first and the other three modes after
   it, each as one command, and send back the four log files:
   ```
   cd /opt/dishnet && git pull origin claude/study-this-jhe2eg && mkdir -p /root/dnb-verify
   bash scripts/phase2-verify.sh --data-report      2>&1 | tee /root/dnb-verify/verify-data-report-$(date -u +%Y%m%dT%H%M%SZ).log
   bash scripts/phase2-verify.sh --website          2>&1 | tee /root/dnb-verify/verify-website-$(date -u +%Y%m%dT%H%M%SZ).log
   bash scripts/phase2-verify.sh --email <address>  2>&1 | tee /root/dnb-verify/verify-email-$(date -u +%Y%m%dT%H%M%SZ).log
   bash scripts/phase2-verify.sh --lead <+2567…>    2>&1 | tee /root/dnb-verify/verify-lead-$(date -u +%Y%m%dT%H%M%SZ).log
   ```
   The e-mail address must be on a customer record the operator controls; the lead number must be a
   lead's (the command refuses anything else before sending).
2. Decide the §1 fix from the reading; no change is made until approved.
3. Redeploy the website in EasyPanel when ready; `--website` confirms it.
