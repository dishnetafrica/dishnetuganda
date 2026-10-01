# 48 — Security & financial-integrity verification, and the smallest-safe remediation plan (2026-09-30)

**Status of this document:** verification and proposal. It records what was
**confirmed by code inspection** at the current repository state, distinguishes
what is **already fixed** (and whether it is deployed) from what **remains open**,
and proposes the **smallest safe remediation batches** with their tests. It does
**not** authorise deployment, production data changes, external messaging,
payments, or any tax/accounting-policy decision. Those remain gated on explicit
approval.

This document does **not** overwrite earlier audit evidence. It builds on
`docs/45` (notification audit), `docs/46` (notification-reliability fixes) and
`docs/47` (distribution-management audit), and records what changed and why.

---

## §0 — Method, scope and status labels

**Scope.** The uCRM plugin at `dishnet-hybrid-sudan/` (version 5.18.54). The
PostgreSQL MikroTik control plane at `dishnet-hybrid-sudan/dishnet-mikrotik-control-plane/`
(Domain B, governed by the repository-root `CLAUDE.md`) is **out of scope** and
was not touched.

**Method.** Read-only code inspection at commit `ffb154c` (which adds only
`docs/47` on top of `fe6471e` = the 5.18.54 code). Three independent verification
passes were run and each finding was re-checked against the source. **No
production system was contacted; no server logs were read; nothing was probed or
exploited.** Where a finding's realized impact depends on production data or
configuration, it is labelled accordingly and is **not** asserted as fact.

**Evidence discipline (repository rule).** Grants/routes/buttons are the weakest
evidence; a claim of "prevented" or "occurred" requires stronger evidence. A
finding below is stated as *confirmed in code* only where the code path is
certain; realized production impact that would need live evidence is labelled
**Not verified against the live environment**.

**Status labels (used verbatim throughout):**
- **Verified working**
- **Verified defective**
- **Implemented but not adequately tested**
- **Not verified against the live environment**
- **Proposed but not implemented**
- **Blocked pending a business or accounting decision**

---

## §1 — Baseline and the deploy gap (the crux)

| Fact | Value |
|---|---|
| Repository code | **5.18.54** (`fe6471e`; HEAD `ffb154c` adds only `docs/47`) |
| Live server | **5.18.53** (per `docs/07`: "5.18.53 deployed, PASSED 49/0/2") |
| 5.18.54 | **built, NOT deployed** |
| Branch of this work | `claude/study-this-jhe2eg` |

**Two different populations of defect, which must not be conflated:**

1. **Notification/communication defects (`docs/45`).** Every fix exists in the
   **5.18.54 code** and is test-pinned, but 5.18.54 is **not deployed**. So the
   **live 5.18.53 server still exhibits these defects**; the repository does not.
   → Handling = **deploy** (gated on approval).

2. **Security (PD-1…PD-13) and stock/migration/financial defects.** Confirmed in
   code and — except one file's unrelated edit — **byte-identical between 5.18.53
   and 5.18.54**. So these are **open in BOTH the live server and the current
   repository**. → Handling = **fix in code, then deploy** (fix authorised for
   PD-1 now; the rest proposed).

This is why the security and financial-integrity review comes first, and why the
collections-export exposure and the payment-webhook failure are handled as their
own narrow items rather than inside a distribution release.

---

## §2 — Notification & communication verification matrix (A1)

Per-defect: repository (5.18.54) state, and the live (5.18.53) consequence.
"Fixed in code" = the fix is present and a named regression test targets it (the
tests were read, not run this pass — see §8 for the suite run). Live 5.18.53
consequence is a **code-based inference** (the fix commits post-date the 5.18.53
tag and are Uganda-gated additions), **not a live observation** — so the live
column is **Not verified against the live environment**, with the strong
expectation stated.

| # (docs/45) | Defect | Repository 5.18.54 | Live 5.18.53 (inferred) | Evidence |
|---|---|---|---|---|
| D-1 | Payment webhook crash after receipt | Fixed in code, test-pinned | Expected defective | webhook.php:1434-1439; test_payment_webhook_flow.php |
| D-2/D3 | Duplicate payment receipts | Fixed in code, test-pinned | Expected defective | ReceiptOnce.php:34-39; test_receipts_once.php |
| D-4/D5 | Duplicate reminders, two paths | Fixed in code, test-pinned | Expected defective | webhook.php:3303,3377; InvoiceReminders.php; test_reminders_one_path.php |
| C1/C2 | Wrong suspension wording to prepaid | Fixed in code, test-pinned | Expected defective | webhook.php:1842; test_reminders_one_path.php:303-334 |
| D-3/C6 | WhatsApp switches not respected | **Screen made honest/inert only**; switches still not wired to senders (deferred, docs/46 §E) | Misleading screen live; switches non-functional in both | whatsapp.php:24-27,1186-1194 |
| D-8 | Quote emails for KYC quotes | **Prepared opt-in switch**; default still calls uCRM send | Duplicate risk in both (config-gated) | KycService.php:84-98 |
| — | Inconsistent opt-outs | Plugin-internal fixed; **cross-system uCRM↔plugin does not sync** | Cross-system gap in both | WinBack.php; NotificationService.php:2121 |
| S-1 | Failure-queue API no role check | Fixed in code (Uganda) | Live Uganda API still unprotected | api_notifications.php:712-719 |
| S-3 | Missing daily staff summary | Fixed in code | Brief never sends on live | staff_jobs_summary.php:66; master.php:257 |
| S-4 | Admin/watchdog recipients | Code fixed; **delivery depends on operator config** (whatsapp_admin_phone/alert_whatsapp) | Config-dependent in both | NotifyWatchdog.php; master.php:153 |
| M1 | Customers without phone | Phone-handling improved; **email-only customer still gets no lifecycle email** | Gap in both | webhook.php:1057,1264,1589,1845 |

**Carried-forward gaps still open after 5.18.54 deploys:** cross-system opt-out
sync; email-only-customer lifecycle emails (M1); Event-Map switches not wired
(D-3); KYC quote default duplicate (D-8, + decision O6); watchdog delivery
depends on operator config (S-4). These are **Proposed but not implemented** or
**Blocked pending a business/accounting decision**, not part of any code fix here.

---

## §3 — Payment processing verification (A2)

- **D-1 (payment webhook failure after receipt) — Verified defective on live;
  fix Implemented in 5.18.54, awaiting deploy.** The Uganda `PAYMENT_FLOW` gate
  (webhook.php:1434-1439) carries past the crash and reaches the marker, FCM and
  Starlink-restore steps; the buggy `flock` now runs only on South Sudan. **No
  new code is needed — this is a deploy decision** (treated as a separate narrow
  item; see §7).
- **Idempotency of payment posting — Verified defective (code) / Not verified
  against the live environment (realized count).** See FI1, FI3, FI4 in §4:
  there is no database-level duplicate guard on the cashbook, and one of the
  four uCRM payment-post paths does not deduplicate.
- **No real payments, refunds, or settlements were initiated.** Reconciliation
  crons (`cron_crm_payment_gap.php`, `cron_crm_payment_reconcile.php`) exist but
  were **not** independently exercised this pass → **Not verified**.

---

## §4 — Cashbook / collections / financial-invariant matrix (A3)

The ten invariants from the instruction, mapped to confirmed findings.

| # | Invariant | Verdict | Basis |
|---|---|---|---|
| 1 | No duplicate entries | **Verified defective** | FI1 (KYC cash double-post), FI3 (no DB unique guard), FI4 (a retry path w/o dedup) |
| 2 | No missing payment | **Not verified** | reconciliation crons exist, not exercised this pass |
| 3 | Non-cash must not inflate physical cash | **Verified defective** | FI2 (mobile money booked as Cash UUID) |
| 4 | Correct account + currency | **Verified defective** (inventory value) | FI5 (cross-currency SUM + blank currency label); per-currency figure rule elsewhere is sound |
| 5 | Transfers reconcile | **Not verified** | TRANSFER type exists; not assessed |
| 6 | Reversals preserve audit trail | **Verified defective** | PD-13 (approve/reject/pair-void/reseed skip FinAudit) |
| 7 | Balances reconcile | **Not verified** | not assessed this pass |
| 8 | uCRM balances must not silently diverge | **Not verified** (flagged) | U1 (clientType flip may change billing treatment); cron_wallet_sync overwrites wallet from uCRM Org-7 every 6h |
| 9 | Failed processing must not leave misleading success | **Verified defective** | S5 (migration marked applied on partial failure); FI4 |
| 10 | Preserve accounting policies | **Blocked pending accounting decision** | FI3 (the unique-index drop is a policy tension); FI2 (is mobile money a distinct account?) |

Financial-invariant findings (FI1–FI5), stock/schema (S1–S5) and uCRM
classification (U1–U2) are detailed in §6.

---

## §5 — Security findings register (A4)

All PD items **confirmed in code** and, except where noted, present in **both**
5.18.53 and 5.18.54 (only `api_retailer.php` differs between versions, and its
edit is far from the affected handler). Severity is this reviewer's assessment.

| PD | Severity | Finding (evidence under `dishnet-hybrid-sudan/`) | Impact | Remediation (proposed) |
|---|---|---|---|---|
| **PD-1** | **Critical** | Unauthenticated collections CSV export. `includes/routes.php:265-304` runs at `public.php:739`, keyed only on `tab=all_collections&col_export=csv`, **no requireLogin/role**; login gate `public.php:1027` runs after. Emits 11 columns (customer name, uCRM client id, amount, currency, method, note, CRM-synced, uCRM payment id, source, date) for **all** agents. | Anonymous full dump of every customer payment collection. | **Add requireLogin + admin gate as first lines of the block** (mirror staff-cashbook `routes.php:307-311`). **Done this session** — see §8. |
| PD-3 | High | Login returns whole account record. `includes/api/api_public.php:16-17` returns `$found` minus password only — still carries `api_token`, `pwd_reset_token`, `ucrm_app_key`. | Privileged per-agent uCRM key + 90-day bearer + reset token handed to the client. | Whitelist the login response (token already returned separately). |
| PD-6 | High | `customer_360` any client by id. `includes/api/api_retailer.php:612-625` reads arbitrary `cid`, no role/ownership check; pulls full uCRM 360. | Any signed-in staff pulls any client's full profile; crosses partner boundaries once scoping exists. | Gate the handler by permission before the CRM call. |
| PD-12 | High | Anonymous BlueCard feed proxy. `tabs/lte/bc_portal.php:38-77` proxy branch runs **before** the login check (~L80); forwards GET/POST incl. multipart with the server token; `SSL_VERIFYPEER=false`. | Anonymous read/write proxy to the LTE feed authenticated by a shared token — the partner-facing pattern *not* to copy. | Move the login check above the proxy branch; enable TLS verification. |
| PD-11 | High (exploitability = **Not verified against the live environment** / NV-13) | Emergency-repair hard-coded fallback key. `public.php:238-240`: if `emergency_repair_key.txt` is absent, a built-in key opens a page offering drop_table/vacuum/clear_wal. | If the key file is absent on a host, a known key opens destructive DB ops. | Require the key file; fail closed (no fallback). Confirm file presence via an authorised read-only host check first. |
| PD-9 | High | Reflected XSS. `tabs/admin/stock_dashboard.php:9,868`: `$_GET['stock_tab']` emitted inside an inline script via `addslashes` (does not neutralise `</script>`). | Reflected XSS on an authenticated staff page; with PD-4, steals the bearer. | Properly encode the value for a JS/script context (json_encode). |
| PD-8 | High | CORS `*` + no CSRF on the JSON API. `includes/api_handlers.php:2-8`; cookie SameSite=None (`public.php:62`); the day-global form token guards form POSTs only. | Cross-origin, CSRF-unprotected mutating JSON actions. | Tighten CORS; add an Origin/CSRF check to mutating JSON actions. |
| PD-7 | Medium-High | Stock/staff API no role check + over-wide "privileged" list. `api_stock.php:9,141,350`; priv list `routes.php:1123` includes sales/field_agent/collection/support. | Unauthorised stock movement; staff-roster disclosure. | Add `$_stockIsPriv` guards; tighten the role list. |
| PD-2 | Medium-High | JSON API authorization ignores RBAC. `api_handlers.php:94-98` = is_admin OR account.modules[] only; pages use RbacService. | Split authorization; new distribution API actions inherit it. | Delegate the API check to the same RBAC path the pages use. |
| PD-5 | Medium | Tab permissions default-**open**. `public.php:2919-2921`: unlisted tab ⇒ allowed. | Any tab not listed is reachable by any signed-in user; new distribution tabs open unless listed. | Flip default to deny **and ship a complete allowlist** (or it locks out legit tabs). |
| PD-4 | Medium (needs XSS to exploit) | 90-day bearer in the DOM. `public.php:1683,1777`. | Any script on a staff page reads the bearer (pairs with PD-9). | Larger: move staff auth to a cookie session (as the customer portal already did). Flagged, not a one-liner. |
| PD-13 | Medium (financial integrity) | Cashbook mutations skip FinAudit. approve/reject `CashbookService.php:2297-2306`; pair-void returns before FinAudit `:806` vs `:818-822`; reseed `post_cashbook.php:1350-1390` (3 bulk DELETE, no FinAudit). | Approve/reject/pair-void/reseed leave no financial audit trail. | Add `FinAudit::record` to each path. |

---

## §6 — Stock, migration & uCRM-classification detail (A5), and the financial invariants

**Stock / schema — all Verified defective (confirmed in code); no regression test guards any of them.**
- **S1** serial index non-unique — `036_stock_tables.sql:48`; `StockService.php:108`. Two units can share a serial.
- **S2** bulk balance clamped to 0 — `StockService.php:670` `MAX(0, qty_on_hand + ?)`. Oversell silently floored; shortfall lost.
- **S3** stock_units hard-deleted — `StockService.php:621`. Only a free-text note survives.
- **S4** stock_movements append-only by habit only — no UPDATE/DELETE/TRIGGER anywhere; no CHECK/trigger in migration 036.
- **S5** MigrationRunner marks a partially-failed file applied — `MigrationRunner.php:236,243,244-248` (status 'partial', never re-run); duplicate `048_*` prefix (two files). `test_migration_integrity.php` **codifies** this rather than guarding it.

**uCRM classification — Verified defective (confirmed in code); authoritative uCRM semantics = Not verified against the live environment.**
- **U1** payment flips `clientType 1→2` treating 1 as "lead" — `CrmApiClient.php:95-107`; all four creates send `clientType 1`. First payment reclassifies a residential/individual client as a company. The repo itself labels type 1 both "individual" and "lead". **Correcting it touches billing/tax classification → a business decision, plus live uCRM confirmation.**
- **U2** new client/invoice get retail treatment regardless of type — `webhook.php:808` (client.add), `:932` (invoice.add); no partner/company branching. **Ties into the distribution module's partner classification.**

**Financial invariants (FI1–FI5) — Verified defective (confirmed in code); FI1/FI4 realized double-count = Not verified against the live environment.**
- **FI1** KYC staff cash sale can double-post to the cashbook: `KycService::createCashbookEntry` (`:1857`) plain INSERT, ref `CRM#<clientId>`, no `crm_payment_id`; the webhook auto-post (`webhook.php:1159-1256`) dedups on a **disjoint** namespace (`PAY-{id}`/`crm_payment_id`). Two 'in' rows for one cash sale.
- **FI2** mobile money booked as physical Cash: `PaymentUuids` maps `mobile_money → Cash UUID`; `webhook.php:1156-1158` books it as cash. Inflates physical-cash custody. **Whether mobile money should be a distinct cashbook account is an accounting-policy decision.**
- **FI3** the cashbook `validation_ref` UNIQUE index is **dropped every boot** (`CashbookService.php:1052-1065`) — deliberately, because exchange pairs and webhook/PWA races share refs. **=> the fix is not simply re-adding UNIQUE; it needs a claim-key/dedup design → accounting decision.**
- **FI4** four payment-post paths; `cron_sync.php:266,376` post raw with **no** uCRM-level dedup while `cron/crm_payment_retry.php` (same queue) dedups. Can double-post to uCRM.
- **FI5** `PurchaseService.php:484-500` sums inventory value **across currencies** with no GROUP; `ReportingService.php:246` labels it via a **wrong config key** (`book_base_currency` vs the canonical `cashbook_base_currency`) → blank currency label in production. `test_reporting.php` **masks** both.

---

## §7 — Confirmed defects → smallest-safe remediation batches

Each batch is local, reversible, and **deployment is held pending approval**.
The two priority narrow items are called out separately, as instructed.

### Priority narrow item 1 — PD-1 collections export (AUTHORISED, done this session)
- Fix: add `requireLogin()` + admin gate as the first statements of the export
  block, before any data is read. Regression test asserting the gate precedes the
  data load, with a control proving the test fails if the gate is removed.
- Version **5.18.55**. **Deployment held.** See §8.

### Priority narrow item 2 — D-1 payment webhook (already fixed; needs deploy)
- No new code. The fix is in 5.18.54. Handling = **deploy** (a targeted 5.18.54
  release, or a hotfix carrying only D-1). **Blocked pending deploy approval.**

### Batch S-A — authentication-boundary leaks (Proposed but not implemented)
- PD-3 whitelist the login response.
- (PD-4 grouped here but larger: staff cookie session — flagged, not a one-liner.)
- Tests: login-response whitelist; token-not-in-body.

### Batch S-B — authorization gaps (Proposed but not implemented)
- PD-6 gate `customer_360`; PD-7 stock/staff API guards + tighten priv list;
  PD-2 API→RBAC; PD-5 tab-perms default-deny + complete allowlist.
- Tests: per-endpoint 401/403/200 (the `test_preauth_allowlist.php` pattern).

### Batch S-C — web hardening (Proposed but not implemented)
- PD-8 CSRF/Origin + CORS; PD-9 XSS encode; PD-12 auth-before-proxy + TLS verify;
  PD-11 require the key file, no fallback.
- Tests: CSRF rejection; XSS-encoding; proxy-requires-login; no-fallback-key.

### Batch F-A — financial audit trail (Proposed but not implemented)
- PD-13 add `FinAudit::record` to approve/reject/pair-void/reseed.
- Test: each path writes exactly one FinAudit row.

### Batch F-B — duplicate prevention & dedup (partly Blocked pending accounting decision)
- FI1 give `createCashbookEntry` a dedup/claim aligned with the webhook namespace.
- FI4 route all payment-post paths through `createPaymentSafe` (dedup).
- FI3 the cashbook claim-key design, and FI2 (mobile money as a distinct account)
  → **Blocked pending an accounting-policy decision** (reserved by the user).
- FI5 use `cashbook_base_currency`; make inventory value per-currency (no cross-currency SUM).
- Tests: cashbook row-count under a KYC cash sale + webhook; per-currency inventory; retry double-post.

### Batch M-A — migration integrity (Proposed; needs care)
- S5 stop marking a partially-failed file applied (record 'failed', re-run).
  **Care:** must not re-run already-applied statements; needs live-schema awareness.
- The duplicate `048_*` prefix **cannot be fixed by renaming an applied file**
  (the ledger keys on filename) → needs a migration-aware approach. Flagged.

### Batch ST-A — stock integrity (Proposed; some data-dependent / schema)
- S1 UNIQUE on serial — **data-dependent**: existing duplicate serials on live
  would block the index → needs an authorised read-only live-data check first.
- S2 make oversell an error, not a silent floor (with test).
- S3 soft-delete/tombstone; S4 append-only trigger — schema changes (migrations).

### Batch U-A — uCRM classification (Blocked pending business decision)
- U1 stop auto-flipping `clientType` on payment — **needs the intended
  classification confirmed (business) and live uCRM semantics confirmed.**
- U2 partner/company handling → **defer to the distribution module design.**

---

## §8 — What is implemented in this session

- **PD-1 fix + regression test — Implemented (this session), deployment held.**
  Suite run before/after recorded in the change-control entry and in §8.1 once
  complete. Version bumped to **5.18.55**.
- Everything else in §7 is **Proposed but not implemented** or **Blocked**.
- **No distribution-module code was written.** Per the instruction, the
  distribution build does not start until the security and financial-integrity
  review is complete and approved.

### §8.1 — Test evidence
- Command: `bash tests/run.sh` (dependency-free, per-test vault).
- **Before** the change: **247 files, 11,295 ok, 0 failed, exit 0.**
- New test `tests/test_collections_export_auth.php`: **8/8**, standalone —
  including the control-on-control (it fails if the gate is removed).
- **After** the change: the full suite is re-run **twice** (248 files with the
  new test). Result recorded in `docs/07` and reported to the operator.

---

## §9 — Outstanding production / live-evidence checks (read-only), and approvals

**Read-only checks (identify each, with its exact procedure and expected effect,
before execution — none creates records):**
- **PD-1 access review (NV):** review available web access logs for requests
  containing `col_export=csv`, via an authorised read-only procedure. **Do not
  probe the endpoint.** Do not claim unauthorised access occurred unless the logs
  establish it. *(Requires the log file, not a terminal copy.)*
- **PD-11 (NV-13):** confirm whether `emergency_repair_key.txt` exists on the
  Uganda host (authorised read-only file check).
- **S1 (data-dependent):** count duplicate serials in `stock_units` before any
  UNIQUE-index migration.
- **U1 (semantics):** confirm the intended `clientType` for these clients and the
  uCRM meaning of type 1 vs 2 (one uCRM screen).

**Approvals required before anything below happens:** deploy (any release,
including 5.18.54 for D-1); any production data/config change; any migration
against production; any real customer/staff message; any real payment/refund;
any accounting-policy decision (FI2, FI3, mobile-money account, tax); the
distribution build.

---

## §10 — Deployment / rollback posture

**Nothing is deployed by this document.** When a batch is approved, it ships as
its own pinned release with a backup-first deploy script and a **separate** typed
rollback (never handed over in one copyable block, per the repository rule). The
per-release deploy/rollback plan is produced at approval time, not here.

---

## §11 — Ready-for-approval vs blocked

**Ready for your decision now:**
- Deploy **D-1** (payment webhook) — via 5.18.54 or a targeted hotfix.
- Approve **PD-1** (5.18.55, already implemented) for deployment.
- Approve building **Batches S-A, S-B, S-C, F-A** (local reversible code fixes + tests).

**Blocked pending a business or accounting decision:**
- FI2 (mobile money as a distinct account), FI3 (cashbook dedup/claim policy),
  U1 (client classification), and any tax treatment.

**Not started, by instruction:**
- The distribution module (waits on this review being approved).

---

## §12 — Distribution module

Held. `docs/47` remains the spec and the decision gates (D-2, D-6, D-7, D-9a and
the rest) are unchanged and unresolved. No distribution code was written. The
security findings that would otherwise be inherited by new distribution tabs and
API actions (PD-2 split authorization, PD-5 default-open tabs, PD-6 unscoped
customer lookup, PD-12 partner-proxy pattern) are exactly why the review precedes
the build.
