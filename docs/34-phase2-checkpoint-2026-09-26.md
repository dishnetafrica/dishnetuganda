# Phase 2 status checkpoint — 26 September 2026

Scope of this checkpoint: the customer-login remediation plan (`remediation-plan-phase0-2026-09-25.md`),
Phase 2 "Authentication", compared with the repository at `HEAD c2f2368` and with what is live on
the Uganda server. **Nothing was rolled back, nothing was deployed, no new implementation was started
during this checkpoint.** Every number below is from a run made for this checkpoint; nothing is
carried over from intermediate figures.

The plugin's test suite is **204 suites / about 8,050 checks** (`tests/run.sh`, one process per
suite, a fresh vault per suite). The "23/25 suites" figure in the request does not describe this
suite; the exact figures are in §2.

---

## 1. Phase 2 implementation inventory

Legend for *Production status*: **live** = deployed on the Uganda uCRM and exercised there;
**live, not exercised** = deployed, no production observation yet; **repo only** = committed, not
deployed. The live plugin is **5.18.40 (`4a2f41c`)**, which is the latest plugin commit.

| Phase 2 item | Status | Code / files | Tests | Production status |
|---|---|---|---|---|
| `TenantProfile` (core: selector → currency → default; explicit key → profile → literal) | ✅ | `lib/TenantProfile.php`; `ConfigVault::VAULT_KEYS` + `tenant_profile` | `test_tenant_profile.php` 108 | live: resolves **uganda / +256** on the server from the vaulted currency (5.18.38 log, stage P) |
| South Sudan profile | ✅ | `profiles/south-sudan.json` (53 leaves, 3 null) | golden pins in `test_tenant_profile`, `test_customer_message_identity` 33, `test_customer_emails` 105, `test_dunning_gate` 27 | n/a on Uganda; the Sudan install has not been updated to any Phase 2 build |
| Uganda profile | 🟡 | `profiles/uganda.json` (53 leaves, **4 null**: office.hours, jurisdiction.courts, legal_texts, payment_instructions — the plan's §D.6 operator inputs) | `test_tenant_profile` (values equal their repository sources) | live |
| `PhoneNumber` (one rule, no dial code of its own) | ✅ | `lib/PhoneNumber.php`; `ca_phone_intl()` delegates | `test_phone_number.php` 39 (literal guard + weakened copy) | live: the operator's `+211…` number kept as typed; a `0772…` would become `+256772…` (sandbox-proved) |
| OTP transport (Evolution when mapped, else WASender, else refusal) | ✅ | `NotificationService::phoneTransport()`, `lastSendResult()`; `app_send_otp` | `test_customer_otp_transport.php` 41 (fake Evolution; Sudan path) | **live and exercised**: a code reached the operator's phone through Evolution and signed them in (5.18.38 log L1–L8) |
| Structured customer index / e-mail identifier | ✅ | migration `074` (e-mail + 6 flags); `lib/ClientSearchIndex.php`; writers `cron_sync.php`, `webhook.php` client.add/edit; `ca_find_clients_by_email()` | `test_login_eligibility.php` 51; e-mail path: sandbox probe (§8) | live: 90 rows carried the columns at deploy, flags empty until `cron_sync`; the client born in uCRM at 06:06 entered through the webhook path. **E-mail sign-in not yet exercised in production** |
| Eligibility gates (leads no, archived refused, service not required; NULL flags allowed) | ✅ | `ca_login_eligibility()`, keys `portal_login_allow_leads`, `portal_login_require_service` | `test_login_eligibility` 51 (both keys flip the rule) | live, **not exercised**: only the operator's own (eligible) account has signed in |
| Customer JWT secret (generated, vaulted, separate from `webhook_secret`) | ✅ | `lib/CustomerJwtKeys.php` (`ensure/rotate/prune`), `public.php` boot | `test_customer_jwt.php` 54 | live: key set provisioned `k1`, values in the vault (5.18.38 stage P) |
| `kid` / `iss` / `aud` | ✅ | `JwtAuth::forCustomers()`, `verify()` requires known kid, iss `dishnet-hybrid:<dir>`, aud `customer-portal`, alg pinned HS256 | `test_customer_jwt` (§3, §4) | live |
| JWT rotation | ✅ | `tools/customer_jwt_key.php --ensure/--rotate/--prune` | `test_customer_jwt` (rotation keeps k1 valid; tool prints no secret) | live, **not exercised** (no rotation has been run; not needed yet) |
| HttpOnly / Secure / SameSite cookie | ✅ | `CustomerSession::setCookie()`; `Secure` when HTTPS or `X-Forwarded-Proto` | `test_customer_session.php` 80 (real HTTP) | **live and exercised**: `HttpOnly; SameSite=Lax; Secure` observed on the server (L4) |
| Removal of the JWT from URLs | ✅ | `login_web.php`, `portal.php`, `portal_data.php`; API refuses `?token=` | `test_customer_session`, `test_customer_login_security` 68 | **live and exercised**: URL token → 302 on the portal, 401 on the API (L6) |
| Logout / revocation | ✅ | `app_logout` revokes the row + clears the cookie; `staff_revoke_customer_sessions`; `app_logins` tab | `test_customer_session` (token and cookie refused after logout) | **live and exercised**: token and old cookie refused after logout (L7) |
| Download tokens | 🟡 | portal downloads ride the session cookie (no ticket); the **data-report hand-off** is a 600 s purpose-bound token (`app_data_report_token`, aud `data-report`) — **signed with the legacy derivation** on purpose, because the sibling plugin's verifier is not in this repository | `test_customer_session` (hand-off), `test_preauth_allowlist` 48 actions | live, not exercised. **See §6, the one open security item** |
| Tenant/profile readers | 🟡 | converted: `CustomerContact`, `EmailTemplate`, `OverdueDunningHelpers`, `timezone.php`, `PortalLocale`, `login_web.php`, `api_customer_app.php`; **not converted**: `lib/currency.php` (3 literals), `lib/PluginQuotePdf.php` FROM block (6), `legal_page.php` (2), the portal's hard-coded support numbers and coverage text (≈20 `+211`/Juba occurrences), AI prompts — Phase 4 content per the plan | pins on the converted readers | live for the converted readers |
| Configuration / manifest | ✅ | manifest keys `tenant_profile`, `portal_login_allow_leads`, `portal_login_require_service`, `app_jwt_ttl_days` (38 keys); `tools/set_config.php` registry | `test_set_config_tool.php` 32 | live |
| Session storage | ✅ | migration `073` `customer_sessions` (jti, client_id, identifier, login_mode, kid, issued/expires/revoked, ip, ua) | `test_customer_session` | live: rows written and revoked during the operator's sign-in |
| Canonical `/customer-login` | ✅ | canonical target `public.php?page=customer_login`; short address `crm.dishnetuganda.com/customer-login` → 302 (one Traefik file) | rehearsal 12/12 (fake host) | **live and exercised**: 302 with the exact Location, both schemes (04:30 UTC, 7/7) |
| Website "Customer Login" → the portal (plan §G, Phase 4 half) | ✅ repo / ⚪ live | 176 links on 57 pages; `verify-site.sh` guard | `verify-site.sh`, `verify-address.py`, `stamp-assets.sh --check` all pass | **repo only (`466e4fc`)** until the EasyPanel redeploy |
| `tools/tenant_profile.php --show` (plan §H) | ⚪ | not built; `TenantProfile::describe()` exists, no CLI | — | — |
| Related tests added in Phase 2 | ✅ | `test_phone_number`, `test_tenant_profile`, `test_customer_jwt`, `test_customer_session`, `test_customer_otp_transport`, `test_login_eligibility`; updated `test_preauth_allowlist`, `test_customer_login_security`, `test_set_config_tool`, `test_links_without_port`; since: `test_tick_auto_pull_log` 18, `test_customer_pwa` 31 | — | — |

Outside Phase 2 but shipped in the same builds, deliberately and recorded: 5.18.39 (the tick's
`str_pad` cast, `main.php` only), 5.18.40 (the customer manifest + Install row, `routes.php`,
`login_web.php`, `portal.php`), the website repoint, the Traefik file. **Nothing else outside Phase 2
was touched**: `git diff --stat fa2d463..4a2f41c -- dishnet-hybrid-sudan` lists only those files.

## 2. Exact current test state (two complete runs, this checkpoint)

Both runs were made for this checkpoint on the working tree at `c2f2368` (plugin identical to the live
`4a2f41c`), one after the other, `tests/run.sh`, a fresh vault per suite.

| | Run 1 | Run 2 |
|---|---|---|
| Suites | **204 of 204** | **204 of 204** |
| Checks in the 183 suites that report `N passed, M failed` | **8,051 passed / 0 failed** | **8,051 passed / 0 failed** |
| The other 21 suites (their own summary format) | all exited 0 | all exited 0 |
| `FAIL` lines anywhere in the log | 0 | 0 |
| Warnings | **5 PHP warnings**, all one line: `Undefined variable $frag…` at `tests/test_dpo_endpoints.php:91` — a test's own description string where `$frag…` is parsed as one identifier (the ellipsis is a valid identifier byte); no assertion is affected (the check itself uses `$frag` correctly); pre-existing since 13 September (`e7cb79f`, DPO work), **outside Phase 2** | the same 5 |
| Skipped | none — the harness has no skip mechanism (the remaining textual matches of "skip"/"warn" are assertion descriptions and expected service output, identical in both runs) | none |
| `run.sh` exit code | 0 | 0 |

The Phase 2 suites, per run (identical in both):

| Suite | Run 1 | Run 2 |
|---|---|---|
| `test_phone_number` | 39 / 0 | 39 / 0 |
| `test_tenant_profile` | 108 / 0 | 108 / 0 |
| `test_customer_jwt` | 54 / 0 | 54 / 0 |
| `test_customer_session` (real HTTP) | 80 / 0 | 80 / 0 |
| `test_customer_otp_transport` (fake Evolution) | 41 / 0 | 41 / 0 |
| `test_login_eligibility` | 51 / 0 | 51 / 0 |
| `test_customer_login_security` | 68 / 0 | 68 / 0 |
| `test_preauth_allowlist` | 105 / 0 | 105 / 0 |
| `test_otp_log_privacy` | 16 / 0 | 16 / 0 |
| `test_set_config_tool` | 40 / 0 | 40 / 0 |
| `test_links_without_port` | 47 / 0 | 47 / 0 |
| `test_tools_smoke` | 42 / 0 | 42 / 0 |
| `test_customer_message_identity` (Sudan golden) | 33 / 0 | 33 / 0 |
| `test_customer_emails` (Sudan golden) | 105 / 0 | 105 / 0 |
| `test_dunning_gate` (Sudan golden) | 27 / 0 | 27 / 0 |
| `test_portal_account` (cross-account isolation) | 36 / 0 | 36 / 0 |
| `test_tick_auto_pull_log` (5.18.39) | 18 / 0 | 18 / 0 |
| `test_customer_pwa` (5.18.40) | 31 / 0 | 31 / 0 |

## 3. Every remaining failure

**None.** Zero failing assertions in either run; no group A–D entry has a member.

| Group | Count |
|---|---|
| A. Real code defects | **0** |
| B. Test defects / fixtures | **0** in Phase 2; **1 pre-existing outside Phase 2**: the `$frag…` description string in `tests/test_dpo_endpoints.php:91` (5 PHP warnings per run, no assertion affected) — proposed fix `{$frag}…`, one character pair; **not applied**, per the instruction to fix only Phase 2 defects |
| C. Environment / configuration issues | **0** |
| D. Intentional failures because the expected behaviour changed | **0** |

For the record, the two test-side defects seen earlier on 25–26 September, both resolved before this
checkpoint: `test_links_without_port` flagged the new same-origin check in `CustomerSession` as a
link-building site (an allow-list entry with its reason; the guard was not weakened), and the first
version of `test_customer_pwa` followed the portal's redirect and read a 200 where a 302 was the
answer (`follow_location` off, the 302 and its Location now asserted). Neither was a plugin defect.

## 4. Phase 2 acceptance matrix (plan §A.2 exit gate + §E/§D items)

| Exit criterion / item | Mark | Evidence |
|---|---|---|
| Suite green twice | ✅ (8,051 / 0 in both runs) | §2 |
| Sudan golden test byte-identical with an empty configuration | ✅ | `test_tenant_profile` 108 (south-sudan.json equals the old literals; default profile south-sudan; weakened copies M15/M16 caught), `test_customer_message_identity` 33, `test_customer_emails` 105, `test_dunning_gate` 27, `test_phone_country` 26, `test_portal_locale` 25 — all green in this checkpoint's own run |
| Migration rehearsal on a copy of the data directory | ✅ | 073/074 applied to a data directory built by 5.18.37: rows intact, key provisioned, old code still opens it (`docs/07`, 5.18.38 entry) |
| E.1 signing key generated once, vaulted, redacted, never in the manifest | ✅ | `CustomerJwtKeys`, `ConfigVault::VAULT_KEYS`, `PluginConfig::SECRET_KEYS`; `test_customer_jwt` §1–§2 |
| E.1 claims `iss`/`aud`, `kid` in the header, verify requires them | ✅ | `test_customer_jwt` §3–§4 |
| E.2 rotation keeps old keys until pruned; tool prints no secret | ✅ | `test_customer_jwt` §5–§6, `test_tools_smoke` |
| E.3 cut-over: E3-a immediate, tokens without `kid` refused | ✅ | `test_customer_jwt` §4 (legacy and constant-key tokens refused); production: nobody was signed out because no session existed |
| E.4 server-set cookie, no token in body for the web flow, native client only | ✅ | `test_customer_session`; production L4 |
| E.4 `completeLogin()` and the cookie-valid redirect carry no token | ✅ | `test_customer_login_security`; production L6 |
| E.4 `portal_data.php` cookie-only, `ca_require_auth` refuses `?token=` | ✅ | production L6: 302 / 401 |
| E.4 one-time download tokens | 🟡 | portal downloads use the cookie; the data-report hand-off token is purpose-bound and 600 s but legacy-signed — §6 |
| E.4 `Referrer-Policy: same-origin`, `Cache-Control: no-store` on portal pages | ✅ | `portal_data.php` headers; `test_customer_session` |
| E.5 logout revokes; the portal checks the revocation source | ✅ | `customer_sessions` is the single source; production L7 |
| E.5 revoke-all from the staff tab | ✅ | `staff_revoke_customer_sessions`, `app_logins.php`; `test_customer_session` |
| E.5 TTL 30 days, revocable | ✅ | `app_jwt_ttl_days` default 30 |
| E.6 WASender-only gate removed; Evolution used; result read back | ✅ | `test_customer_otp_transport`; production L1–L3 |
| E.6 destination = `PhoneNumber::international(raw, profile)`; identifier canonical | ✅ | `test_customer_otp_transport` (identifier `+256…` stored) |
| E.6 eligibility gates, uniform refusal, configurable | ✅ | `test_login_eligibility` (lead refused with the uniform body, no message; keys flip it) |
| D.1–D.3 profile core, storage, resolution order | ✅ | `test_tenant_profile` |
| D.4 the phone rule | ✅ | `test_phone_number` |
| D.5 `?page=tenant&format=json` feed for the website | ⚪ | not built (Phase 4 content) |
| D.6 operator inputs left null | ✅ by design | 4 nulls in `uganda.json`; §9 |
| §H `tools/tenant_profile.php --show` | ⚪ | not built |
| §H readers: `lib/currency.php` defaults → profile | ⚪ | not converted; PortalLocale answers the portal's currency from the selector |
| §H `lib/PluginQuotePdf` FROM block, legal pages, AI facts → profile | ⚪ | Phase 4 content per the plan's own phase table |

## 5. Production versus repository

| Component | Built in Git | Committed | Deployed to production | Exercised in production |
|---|---|---|---|---|
| Plugin 5.18.38 (Phase 2 core) | yes | `fa2d463` | yes, 02:59 UTC | sign-in L1–L8 with the operator's number; posture P |
| Plugin 5.18.39 (tick fix) | yes | `e333261` | yes, 03:58 UTC | tick completed, 0 crashes (T1–T3) |
| Plugin 5.18.40 (installable portal) | yes | `4a2f41c` | yes, 04:21 UTC | manifest checks M1–M5 (11/11); its T1/T2 invalid (clock mix), T3/W/summary **pending the operator's log** |
| Short address (Traefik file) | yes | `4fd6b30` | yes, 04:30 UTC | 302 both schemes, 7/7 |
| Website repoint | yes | `466e4fc` | **no** (EasyPanel redeploy not reported) | — |
| E-mail sign-in | yes | `fa2d463` | yes (same build) | **not exercised**; sandbox probe only (§8) |
| Eligibility refusal (lead / archived) | yes | `fa2d463` | yes | **not observed** in production |
| JWT rotation | yes | `fa2d463` | tool deployed | never run (by design) |
| Data-report hand-off token | yes | `fa2d463` | yes | **not exercised** |

Production plugin commit **`4a2f41c` = the latest plugin-scoped commit**. `HEAD c2f2368` differs from
it only in `docs/`, `scripts/` and the website. There is no plugin code in the repository that is not
live, and nothing live that is not in the repository.

## 6. Security checks

| Check | Result | Evidence |
|---|---|---|
| Customer JWT secret separate from `webhook_secret` | ✅ | `CustomerJwtKeys` generates 32 random bytes; nothing derives it |
| No constant fallback | ✅ | `JwtAuth::forCustomers()` throws when no key is provisioned (`test_customer_jwt` §1); a token under the constant is refused (§4) |
| `kid` | ✅ | header required; unknown kid refused before any signature check |
| `iss` | ✅ | `dishnet-hybrid:<plugin dir>`; mismatch refused |
| `aud` | ✅ | `customer-portal`; `data-report` audience refused by the portal |
| Legacy-token handling | ✅ | tokens without `kid` refused (E3-a); `fromConfig()` remains only for `api/v2/router.php` (unreachable: uCRM serves `public.php` only) and the data-report hand-off |
| HttpOnly | ✅ | production L4 |
| Secure | ✅ | production L4 (HTTPS; `X-Forwarded-Proto` honoured) |
| SameSite | ✅ | `Lax`, production L4 |
| No `?token=` | ✅ | API 401, portal 302 (production L6); the only `&token=` left in `portal.php` is the data-report hand-off |
| Logout invalidation | ✅ | production L7: token 401 "Token revoked", old cookie 401 |
| Download-token isolation | 🔴 **open** | see below |
| Customer cross-account isolation | ✅ | every endpoint resolves the account from the token's `sub`/`accounts` claims; a requested account outside the token's set is 403 (`ca_resolve_active_client_id`); `test_portal_account` proves another customer's kit, receipt and invoice never appear; no customer endpoint reads a client id from the body |
| No secret in logs | ✅ | `test_otp_log_privacy` 122 lines; production L8: the code in no log, notification, queue, conversation, pending or audit row |
| No secret in tool output | ✅ | `test_customer_jwt` §6 (`customer_jwt_key.php` prints kids and dates only), `test_tools_smoke` |

**The one open security item — the data-report hand-off token.** When a customer opens the usage
report, the portal asks `app_data_report_token` for a ten-minute token bound to audience
`data-report` and passes it in the URL to the sibling plugin `dishnet-data-report`. That token is
signed with **the legacy derivation** (`sha256(webhook_secret | crm_auth_token | constant)`), kept
deliberately because the sibling plugin's verifier is not in this repository and its expected shape
is unknown. On the Uganda install `webhook_secret` was recorded empty on 15 September; if
`crm_auth_token` is also empty, that key is a constant anyone can read in the source, and the
hand-off token is forgeable for any `clientId`. Whether that matters depends entirely on what
`dishnet-data-report` does with the token, which this checkpoint cannot read. **Decision required**
(not made here): (a) confirm from the server whether `crm_auth_token` is set (a high-entropy key
makes the legacy derivation acceptable for a 600 s purpose-bound token), and (b) read the
data-report plugin's verifier; then either give the hand-off its own shared secret or drop the
token if the sibling ignores it. Until then this is the only Phase 2 item marked 🔴.

## 7. Uganda OTP — the exact current state

`+256` phone → normalisation → OTP destination → Evolution → verification → session:

1. **Input** `0772 123 456` / `+256772123456` / `256772123456` / `772123456` → `PhoneNumber::international()` under the uganda profile → `+256772123456` (`test_phone_number`, 8 spellings; a 10-digit number with no trunk 0 → null, never a guess).
2. **Lookup** by the last nine digits in the customer index (`ca_find_clients_by_phone`); the canonical number becomes the identifier stored in `app_otp_pending` and the audit rows (`test_customer_otp_transport`: identifier `+256772123456`).
3. **Transport** `NotificationService::phoneTransport()` → **evolution** when the support instance is mapped (production: `in_use=evolution`, WASender unconfigured); the fake Evolution in the test receives number `256772123456`.
4. **Code** never in clear at rest (HMAC), never in the retry queue or the conversation store (mutants M11–M13 caught), text withheld in the notification log (production L8).
5. **Verification** by canonical identifier + hash compare → `JwtAuth::forCustomers()` token, `customer_sessions` row, HttpOnly cookie (production L4).
6. **Session** portal on the cookie only (production L6), logout revokes (L7).

**Production**: exercised once, with the operator's own `+211927797217` (kept as typed, sent through
Evolution, verified, cookie set, portal opened, logout revoked). A `+256` customer has not yet signed
in on production; the `+256` completion rule is proved in the sandbox only.

**South Sudan**: with no `tenant_profile` and no UGX currency the profile resolves to south-sudan
(`+211`), `0921…` → `+211921…` (`test_phone_number` Sudan table), the OTP message is addressed with
`211…` (`test_customer_otp_transport` line 171), WASender still works when configured. Mutants M15 and
M16 (default profile flipped to uganda) are caught by 18 and 9 assertions. **The Sudan install has
not received any Phase 2 build**, so nothing changed there yet.

## 8. E-mail login

Traced and **exercised end to end in a sandbox** for this checkpoint (a fake SMTP sink on loopback,
the real plugin under `php -S`):

```
e-mail → index (ClientSearchIndex::rowFor: contacts[].email, lower-cased; migration 074 column)
      → lookup (ca_find_clients_by_email: case-insensitive exact match)
      → OTP (OtpEmail::send → MailService: SMTP, subject "DishNet Login Code: ******")
      → verification (app_verify_otp {email, code} → 200 "Login successful.")
      → session cookie set → app_me answers with the customer's own account (id 21)
      → the same code again: refused ("No code on file"); an unknown e-mail: the uniform answer
audit: otp_sent · login_success · otp_verify_no_record · otp_no_account_email
```

**Functional in code.** What it depends on in production: the index rows must carry the e-mail
(`cron_sync` writes it every minute; the webhook writes it on client.add/edit — both live), and the
plugin's mailer must be configured (the Uganda install sends invoice and quotation e-mails already,
so a mailer exists; whether `system_from` is set is not known from here). **Not exercised in
production**: no customer has signed in by e-mail. No remaining code or data issue was found; one
controlled sign-in with a staff-owned e-mail on the customer's record would close it.

## 9. Tenant profile

**Uganda values populated (49 of 53)**, all from repository sources: country UG/+256/9 digits,
UGX, `Africa/Kampala`, legal entity *DishNet Africa Limited*, trading name, positioning, office
(Acacia Mall, Kampala), website, e-mail `accounts@dishnetuganda.com`, the five contact numbers and
three WhatsApp numbers (all `+256 705 993 348`), pay URL, **app URL `https://dishnetuganda.com/app`**
(a repository preset the plan flags as possibly premature — the APK is not published), e-mail brand
(company, locality, website, support, reply-to, legal line TIN/Reg. No., badge line), dunning
(from-name, phone, accounts e-mail, company line, website), login title/footer, jurisdiction law,
regulator UCC, products `[starlink]`.

**Null (4)**: `office.hours`, `jurisdiction.courts`, `legal_texts`, `payment_instructions` — exactly
the plan's §D.6 operator inputs; dependent readers fall back to their previous literals or render
nothing.

**Still hard-coded (not converted, by phase)**: `lib/currency.php` (USD/SSP book defaults, 3),
`lib/PluginQuotePdf.php` FROM block (6), `tabs/customer_app/legal_page.php` (2), the customer portal's
support buttons and coverage text (`+211921443002`, "Juba, South Sudan", ≈20 occurrences), AI
prompt facts. These are Phase 4 content by the plan's own phase table.

**Readers converted**: `CustomerContact` (config → disk → profile → DEFAULTS), `EmailTemplate::brand()`,
`OverdueDunningHelpers` (five footer defaults), `timezone.php`, `PortalLocale` (explicit selector before
the currency rule), `login_web.php` (title/footer), `api_customer_app.php` (phone rule).

**South Sudan customer-visible output**: unchanged with an empty configuration — the golden run of
this checkpoint: `test_tenant_profile` 108/108, `test_customer_message_identity` 33/33,
`test_customer_emails` 105/105, `test_dunning_gate` 27/27, `test_phone_country` 26/26,
`test_portal_locale` 25/25, `test_phone_number` 39/39.

## 10. Fixes made during this checkpoint

None. No failure in scope required a fix (§3). The one pre-existing test-side warning (§3 B) is outside Phase 2 and is left for instruction. The one 🔴 item (§6) needs a decision, not a patch.

---

## PHASE 2 STATUS

**16 / 19** Phase 2 items complete (the 19 named in the checkpoint request); **3 partial** — the Uganda
profile (4 operator inputs null by design), download tokens (the data-report hand-off decision, §6),
tenant readers (Phase 4 content readers not yet converted); **0 failed**; **0 not started** among the
19 (one plan extra, `tools/tenant_profile.php --show`, not started).
**0** real code defects remaining
**0** test/fixture defects remaining in Phase 2 (1 pre-existing, outside Phase 2, §3 B)
**0** environment issues
**0** tests failing
**8,051** checks passing in each of two complete runs (204 suites; the 21 suites with their own format
all exit 0)
**1** open security decision (the legacy-signed data-report hand-off token, §6) — a decision, not a
defect: it needs the sibling plugin's verifier read and `crm_auth_token`'s state confirmed on the server.

**Current Phase 2 readiness: READY FOR FINAL VERIFICATION**, on these terms: the two production
exercises still missing are an e-mail sign-in by a real customer record and one eligibility refusal
(a lead); the website redeploy in EasyPanel and the 5.18.40 run's final log are the operator's
pending acts; the §6 decision is the only item that could send a Phase 2 component back to work.

Waiting for the next instruction. No implementation is started.
