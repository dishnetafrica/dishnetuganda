# DPO Pay integration — architecture assessment

**Status: proposal. No code written. Awaiting approval.**

Scope: let a DishNet Uganda customer pay an outstanding uCRM invoice from the
customer portal, by card or Uganda mobile money, through DPO Pay by Network.

Two things this document is careful about, because getting either wrong is
expensive:

1. **What is confirmed and what is not.** Everything in section B marked
   *confirmed* is read from DPO Group's own published production source —
   `DPO-Pay-Common` and the DPO WooCommerce plugin, both authored by DPO Group,
   current release, © 2025. Everything marked **UNCONFIRMED** is a question for
   DPO onboarding. Nothing is guessed.
2. **What already exists here.** DishNet already has an invoice system, a
   payment system, a portal, an audit trail and a cash reconciliation chain.
   The integration attaches to them; it does not duplicate them.

---

## A. Current architecture assessment

### A1. Where the money actually lives

**uCRM is the invoice and payment source of truth.** The plugin never sets an
invoice's status. It posts a *payment* with `applyToInvoicesAutomatically` and
uCRM decides what that settles:

```php
// includes/post/post_field.php:934 — the established pattern
$crmPayload = [
    'clientId'     => (int)$custId,
    'methodId'     => PaymentUuids::resolve($method),
    'amount'       => $amount,
    'currencyCode' => dn_payload_currency($currency, $config ?? null),
    'note'         => $crmNote,
    'applyToInvoicesAutomatically' => true,
];
$crmResult = $crmForPayment->createPaymentSafe($crmPayload, $paymentRef);
```

This is the single most important constraint on the design. **"Invoice becomes
PAID" is not a write we perform.** We record a verified payment; uCRM marks the
invoice. Anything else creates a second opinion about what a customer owes.

### A2. The portal, and why its invoice figures cannot be trusted for payment

`tabs/customer_app/portal.php` (7,669 lines) already renders an invoice list and
an invoice detail screen. It gets them from `tabs/customer_app/portal_data.php`,
which reads a **cache**:

```
ucrm_invoices_cache.json  →  $portalInvoices[] = [
    'id', 'number', 'total', 'due' (= total - paid), 'currency',
    'status', 'created', 'due_date', 'description'
]
```

That cache is right for *display*. It is not safe as the basis of a charge: it
can be minutes or hours stale, and in that window the customer may have paid at
an agent. **The payable amount must be re-read live from `GET invoices/{id}` at
the moment Pay Now is pressed** — never from the cache, and never from the
browser.

Portal authentication is a Bearer JWT verified in `portal_data.php`:
`$portalClaims['sub']` is the uCRM client id, with an `accounts` claim allowing
one login to hold several client ids. `ca_require_auth()`
(`includes/api/api_customer_app.php:318`) is the equivalent for API actions, and
`ca_audit($pdo, $clientId, $action, $phone, $details)` (line 292) is the existing
customer-side audit trail — already used for invoice PDF downloads.

### A3. The payment-recording sprawl, and the one honest shared path

There is **no** `processSuccessfulPayment()`. Nineteen separate call sites post
payments to uCRM — `includes/post/post_field.php`, `includes/api/api_retailer.php`,
`lib/KycService.php`, `cron/payment_catchup_sync.php`, `includes/routes.php`,
`tabs/admin/settings.php` and others. The nearest thing to a shared path is
`CrmApiClient::createPaymentSafe($payload, $uniqueRef)`, which guards duplicates
by searching the last 30 days of that client's payments for a matching amount and
note fragment.

That heuristic is adequate for a human agent typing a collection. **It is not
adequate for a payment gateway that may deliver the same callback three times in
four seconds.** A note-substring search over an API listing is racy and
amount-collision-prone. DPO needs a real idempotency key with a database
constraint behind it (see C).

The right move for section 18 of the brief is therefore: **do not invent a
twentieth payment path, and do not try to refactor the other nineteen.** Add one
`DpoPaymentService::settle()` that is the only thing DPO code calls, and have it
finish through the existing `createPaymentSafe()` + uCRM.

### A4. What DPO must NOT touch — `payment_collections.json`

`payment_collections.json` looks like a general payment ledger. It is not. Every
row carries `retailer_id`, `handover_id`, `crm_payment_id` and feeds:

- agent cash balances (`api_retailer.php`, `agent_balance`)
- cash handover reconciliation (`includes/post/post_field.php:511`)
- the staff ledger and cashbook (`lib/StaffLedgerService.php`, `lib/CashbookService.php`)
- the void/refund path (`includes/api/api_payments_admin.php`)

An online card payment has no agent, no cash, and no handover. Writing DPO
transactions into `payment_collections.json` would put money into an agent's
cash position that the agent never held, and the next handover reconciliation
would demand it. **DPO gets its own table.**

### A5. Where secrets go

`lib/ConfigVault.php` holds an allowlist `VAULT_KEYS` of config keys that survive
a plugin re-install, written `0600` outside the data directory. EFRIS lives
there, including `efris_private_key`. DPO credentials go in exactly the same
place, by the same mechanism. They never enter `kyc_config.json` in the clear,
never reach a template, and never reach the browser.

### A6. Schema, and the precedent for doing idempotency properly

`lib/MigrationRunner.php` applies `migrations/*.sql` in order on every boot,
tracked in `_migrations`. The most recent migration, `070_followups.sql`, is the
precedent to copy — it already solves "two workers must not both create one":

```sql
-- THE structural guarantee ... so two workers racing on the same quiet customer
-- cannot both create one. Application logic alone would lose that race.
CREATE UNIQUE INDEX IF NOT EXISTS idx_fu_open_conv
    ON followups(conversation_id) WHERE closed_at IS NULL;

CREATE TRIGGER IF NOT EXISTS fus_never_deleted
BEFORE DELETE ON followup_sends
BEGIN
    SELECT RAISE(ABORT, 'sent messages are never deleted');
END;
```

Partial unique indexes for the guarantee, `RAISE(ABORT)` triggers for
append-only history. DPO payments get the same treatment.

### A7. Routing conventions (this overrides the paths suggested in the brief)

The brief proposes `/api/payments/dpo/initiate`. This application does not route
that way, and section 13 of the brief says to follow existing conventions. The
conventions are:

| Need | Convention | Existing examples |
|---|---|---|
| Public, unauthenticated | `public.php?page=<name>` | `crm_webhook`, `evo_webhook`, `wa_webhook`, `web_chat` |
| Customer-authenticated (Bearer JWT) | `public.php?page=api&action=<name>` | `app_invoice_pdf_download`, `app_invoices` |
| Admin screen | tab registered in `public.php` route map + permission map + `includes/navigation.php` | `starlink_accounts` |
| Background work | entry in the `cron/master.php` interval table | `efris`, `starlink_usage` |

`dn_plugin_public($config)` (`lib/crm_url.php`) builds this install's public URL
— it is the one source of truth for the hostname, with a documented override for
when uCRM reports an internally-correct but externally-wrong address. The DPO
redirect, back and push URLs must be built from it, never hardcoded.

### A8. Two operational blockers that exist today

**Blocker 1 — there is no payment method in uCRM to book this against.**
`lib/PaymentUuids.php` says so explicitly:

```
── Not available (no mobile money) ─────────────────────────────────────
  Mobile Money — no UUID exists in this UCRM instance.
  All "mobile_money" slugs must fall back to Cash UUID.
```

If DPO payments are posted today they are booked as **Cash**. Cash in uCRM feeds
the agent cash reconciliation, so every online payment would appear as physical
cash somebody has to hand over. A **"DPO Pay" payment method must be created in
uCRM first** and its UUID added to `PaymentUuids`. This is a prerequisite, not a
detail.

**Blocker 2 — currency.** `dn_payload_currency()` and the currency rules
established in the finance audit forbid silent conversion and mixed-currency
rows. DPO's transaction currency must equal the invoice currency exactly. If an
invoice is in UGX and DPO will not accept UGX (see B9), the correct behaviour is
to **refuse to create the transaction and say so**, not to convert.

---

## B. DPO integration assessment

**Sources.** DPO Group's own published production code, current release:

- `DPO-Pay-Common` — the shared class every official DPO module uses:
  https://github.com/DPO-Group/DPO-Pay-Common (`src/Dpo.php`)
- `DPO_WooCommerce` v1.3.1, © 2025 DPO Group, author "DPO Group":
  https://github.com/DPO-Group/DPO_WooCommerce (`classes/dpo.class.php`,
  `classes/WCGatewayDpoCron.php`)

`dpogroup.com`, `docs.dpopay.com` and DPO's Confluence are **blocked by this
environment's network egress policy** and could not be read from here. Every
technical statement below therefore comes from DPO's own source, which is
authoritative for what their integration actually does, and every item that
source cannot answer is marked **UNCONFIRMED** rather than filled in.

### B1. Endpoints — *confirmed*

```php
public static string $testApiUrl = 'https://secure.3gdirectpay.com/API/v6/';
public static string $testPayUrl = 'https://secure.3gdirectpay.com/payv2.php';
public static string $liveApiUrl = 'https://secure.3gdirectpay.com/API/v6/';
public static string $livePayUrl = 'https://secure.3gdirectpay.com/payv2.php';
```

**The test and live URLs are identical.** There is no sandbox host. See B10.

`verifyToken` in the same class posts to a *different* version:

```php
CURLOPT_URL => 'https://secure.3gdirectpay.com/API/v7/',
```

So: **createToken → `/API/v6/`, verifyToken → `/API/v7/`.** Both are POSTs of a
raw XML body with header `cache-control: no-cache` and no content-type set.

### B2. Authentication — *confirmed*

There is none, in the HTTP sense. No header, no signature, no HMAC, no OAuth.
The credential is `<CompanyToken>` **inside the XML body** of every request.

The consequence matters more than the mechanism: **a DPO company token is a
bearer secret in a request body.** It must be vaulted, never logged, never in a
URL, never in a template.

### B3. createToken request — *confirmed, verbatim structure*

```xml
<?xml version="1.0" encoding="utf-8"?>
<API3G>
  <CompanyToken>…</CompanyToken>
  <Request>createToken</Request>
  <Transaction>
    <PaymentAmount>…</PaymentAmount>
    <PaymentCurrency>…</PaymentCurrency>
    <CompanyRef>…</CompanyRef>
    <customerDialCode>…</customerDialCode>
    <customerZip>…</customerZip>
    <customerCountry>…</customerCountry>
    <customerFirstName>…</customerFirstName>
    <customerLastName>…</customerLastName>
    <customerAddress>…</customerAddress>
    <customerCity>…</customerCity>
    <customerPhone>…</customerPhone>        <!-- digits only: preg_replace('/\D/','') -->
    <RedirectURL>…</RedirectURL>
    <BackURL>…</BackURL>
    <customerEmail>…</customerEmail>
    <CompanyAccRef>…</CompanyAccRef>
    <TransactionSource>…</TransactionSource>  <!-- optional -->
    <PTL>…</PTL><PTLtype>…</PTLtype>          <!-- optional: payment time limit -->
  </Transaction>
  <Services>
    <Service>
      <ServiceType>…</ServiceType>
      <ServiceDescription>…</ServiceDescription>
      <ServiceDate>Y/m/d H:i</ServiceDate>
    </Service>
  </Services>
</API3G>
```

`PTL` / `PTLtype` is how a transaction is given an expiry — the mechanism behind
the EXPIRED state. **Note there is no NotifyURL or PushURL field**; see B6.

### B4. createToken response and result codes — *confirmed*

Success is `Result` = `000`, returning `TransToken`, `TransRef`,
`ResultExplanation`.

| Code | Meaning |
|---|---|
| 000 | Transaction created |
| 801 | Request missing company token |
| 802 | Company token does not exist |
| 803 | No request or error in Request type name |
| 804 | Error in XML |
| 902 | Request missing transaction level mandatory fields |
| 904 | Currency not supported |
| 905 | Amount exceeds your allowed transaction limit |
| 906 | Exceeded monthly transactions limit |
| 922 | Provider does not exist |
| 923 | Allocated money exceeds payment amount |
| 930 | Block payment code incorrect |
| **940** | **CompanyRef already exists and paid** |
| 950 | Request missing mandatory fields |
| 960 | Tag has been sent multiple times |

**940 is a gift for idempotency.** DPO itself enforces that a CompanyRef cannot
be paid twice. Our internal reference becomes `CompanyRef`, so DPO becomes a
second line of defence behind our own database constraint.

### B5. Redirect to checkout — *confirmed*

```php
$paymentURL = $dpoCommon->getPayUrl() . '?ID=' . $response['transToken'];
// → https://secure.3gdirectpay.com/payv2.php?ID=<TransToken>
```

This is DPO's **hosted payment page**. Card details and mobile-money prompts are
handled entirely by DPO. We never see or store a PAN. This satisfies section 20
of the brief and is the option to take.

### B6. Result delivery — *confirmed: three independent channels*

DPO's own production plugin implements all three, and it needs all three.

**(1) Browser return — `RedirectURL`.** The customer's browser lands back with
the token in the query string:

```php
$transactionToken = sanitize_text_field( wp_unslash( $_GET['TransactionToken'] ) );
// … then, always:
$response = $dpoCommon->verifyToken([...]);
```

DPO's own code **never trusts the redirect** — it always re-verifies
server-to-server. `BackURL` is where DPO sends a cancelled or errored customer.

**(2) Server push / notify.** DPO POSTs raw XML to a configured URL:

```php
$pushData = file_get_contents( 'php://input' );
if ( str_contains( $pushData, '<API3G>' ) ) {
    $data = new SimpleXMLElement( $pushData );
    if ( ! empty( $data->TransactionToken ) && ! empty( $data->CompanyRef ) ) {
        echo 'OK';                      // DPO expects this acknowledgement
        …
        if ( $order_paid ) { exit; }    // their own idempotency guard
        switch ( $result ) {
            case '000': …
            case '904': case '901': default: …
        }
```

Fields delivered: `TransactionToken`, `CompanyRef`, `Result`,
`ResultExplanation`. The handler must echo `OK`.

**There is no signature on this push, and DPO's own plugin acts on `Result=000`
from the push alone.** We will not. See F2.

**(3) A polling cron — the leg most integrations forget.**
`WCGatewayDpoCron::dpo_order_query_cron()` re-verifies every still-pending DPO
order older than a cutoff:

```php
$orders = wc_get_orders([ 'post_status' => 'wc-pending',
                          'payment_method' => $gatewayId,
                          'date_created' => '<' . $cutoff ]);
foreach ( $orders as $order ) {
    $transactionToken = self::get_transaction_token( $wpdb, $order_id );
    if ( $transactionToken === null ) { /* never reached DPO → failed */ }
    $response = $dpoCommon->verifytoken([...]);
```

This is what catches the customer who paid on their phone and then closed the
browser. **Without it, money arrives at DPO and never reaches the invoice.**

### B7. verifyToken request and result codes — *confirmed*

```xml
<?xml version="1.0" encoding="utf-8"?>
<API3G>
  <CompanyToken>…</CompanyToken>
  <Request>verifyToken</Request>
  <TransactionToken>…</TransactionToken>
</API3G>
```

| Code | Meaning | Our state |
|---|---|---|
| **000** | Transaction Paid | SUCCESS |
| **001** | Authorized | **PENDING — not paid.** Do not settle |
| **002** | Transaction overpaid/underpaid | needs handling — see F5 |
| 900 | Transaction not paid yet | PENDING |
| 901 | Transaction declined | FAILED |
| 903 | Passed the Payment Time Limit | EXPIRED |
| 904 | Transaction cancelled | CANCELLED |
| 902 | Data mismatch in one of the fields | FAILED (investigate) |
| 801/802/803/804/950 | request/credential/XML faults | our bug — alert, never a customer state |

DPO's client retries the verify call up to 10 times on transport error before
giving up. DPO's own API documentation (Confluence, reachable from your network but not
from this one) is reported to state that a token must be verified **within 30
minutes** of completion or the merchant is sent an alert email. Treat that as
secondary until you read the page — it does not change the design, which
verifies immediately and again on a cron.

Response fields DPO's plugin reads: `Result`, `ResultExplanation`, `CompanyRef`,
`CustomerCreditType`. It cross-checks `CompanyRef` against the order:

```php
if ( $response->Result == '000' && $order->get_id() == (int) $response->CompanyRef ) {
```

**It does not cross-check the amount.** We will (see F4).

### B8. Refunds — **UNCONFIRMED**

Neither `DPO-Pay-Common` nor the WooCommerce gateway implements a refund call.
Whether refunds are an API method or a merchant-portal action must be confirmed
with DPO. Until then the REFUNDED state exists in our model but is set only by an
admin recording a refund that was performed in the DPO portal — we do not invent
an endpoint.

### B9. Uganda, UGX and mobile money — **UNCONFIRMED (must confirm before build)**

DPO's public positioning is Uganda support including MTN Mobile Money and Airtel
Money, and the hosted page is where those options are presented. But the code
gives result code **904 "Currency not supported"**, which means currency support
is a per-merchant, per-account fact, not a universal one. Confirm with DPO:

- Is **UGX** an accepted `PaymentCurrency` on our account?
- Do MTN MoMo and Airtel Money appear automatically on `payv2.php` for a UGX
  transaction, or must they be enabled per account?
- Is there a minimum/maximum transaction amount for UGX? (code 905)

### B10. Test vs production — *confirmed mechanism, credentials UNCONFIRMED*

Because the test and live URLs are identical, **there is no sandbox host and no
"test mode" flag in the request.** Test mode is entirely a function of *which
company token you send*. DPO issues a test company token.

This has a sharp consequence for us: **the only thing standing between a test
transaction and a real charge is one config value.** The design must make that
value loud — the environment is displayed on the admin screen and stamped on
every stored transaction row, so a test payment can never be silently counted as
revenue.

**UNCONFIRMED and required from DPO onboarding:**

| Item | Why we cannot guess it |
|---|---|
| Test company token | issued by DPO |
| Live company token | issued by DPO |
| `ServiceType` value(s) | assigned per merchant account |
| `CompanyAccRef` semantics | merchant account reference |
| **How the push/notify URL is registered** | it is **not** a createToken field, so it must be a portal or onboarding setting |
| UGX acceptance + mobile money enablement | see B9 |
| Production activation requirements | DPO's go-live checklist |

---

## C. Database changes

One new migration, `migrations/071_dpo_payments.sql`. No existing table is
altered. No existing JSON store is touched.

```sql
-- 071_dpo_payments.sql — online payments taken through DPO Pay.
--
-- Separate from payment_collections.json deliberately: that file is AGENT CASH,
-- and it feeds agent balances, handovers and the staff ledger. An online card
-- payment has no agent and no cash. Putting one there would invent cash a
-- person has to account for.

CREATE TABLE IF NOT EXISTS dpo_payments (
    id                  INTEGER PRIMARY KEY AUTOINCREMENT,

    -- Our reference. Sent to DPO as CompanyRef. The idempotency key.
    reference           TEXT    NOT NULL,

    crm_client_id       INTEGER NOT NULL,
    crm_invoice_id      INTEGER NOT NULL,
    invoice_number      TEXT    NOT NULL DEFAULT '',

    -- Read LIVE from uCRM at creation. Never from the portal cache,
    -- never from the browser.
    amount              REAL    NOT NULL,
    currency            TEXT    NOT NULL,

    status              TEXT    NOT NULL DEFAULT 'CREATED',
    -- CREATED PENDING REDIRECTED SUCCESS FAILED CANCELLED EXPIRED REFUNDED

    environment         TEXT    NOT NULL,           -- 'test' | 'live'

    dpo_trans_token     TEXT,                       -- TransToken
    dpo_trans_ref       TEXT,                       -- TransRef
    dpo_result          TEXT,                       -- last Result code
    dpo_result_text     TEXT,                       -- last ResultExplanation
    payment_method      TEXT,                       -- as DPO reported it
    checkout_url        TEXT,                       -- payv2.php?ID=…

    crm_payment_id      INTEGER,                    -- the uCRM payment we created
    settled_at          TEXT,                       -- verified success
    verified_at         TEXT,                       -- last server-to-server verify
    callback_at         TEXT,                       -- last push received
    failure_reason      TEXT,

    created_by          TEXT    NOT NULL DEFAULT 'portal',
    created_at          TEXT    NOT NULL DEFAULT (datetime('now')),
    updated_at          TEXT    NOT NULL DEFAULT (datetime('now'))
);

-- THE structural guarantee. Our reference is unique, so a double-submitted
-- Pay Now, a replayed callback and a racing cron cannot produce two rows.
CREATE UNIQUE INDEX IF NOT EXISTS idx_dpo_reference ON dpo_payments(reference);

-- A transaction token identifies exactly one payment. A replayed push
-- therefore cannot be attached to a second row.
CREATE UNIQUE INDEX IF NOT EXISTS idx_dpo_token
    ON dpo_payments(dpo_trans_token) WHERE dpo_trans_token IS NOT NULL;

-- One uCRM payment per DPO payment. This is the constraint that makes
-- "never pay an invoice twice" a database fact rather than a hope.
CREATE UNIQUE INDEX IF NOT EXISTS idx_dpo_crm_payment
    ON dpo_payments(crm_payment_id) WHERE crm_payment_id IS NOT NULL;

-- At most ONE live attempt per invoice at a time. Clicking Pay Now twice
-- reuses the open attempt instead of opening a rival one.
CREATE UNIQUE INDEX IF NOT EXISTS idx_dpo_open_invoice
    ON dpo_payments(crm_invoice_id)
    WHERE status IN ('CREATED','PENDING','REDIRECTED');

CREATE INDEX IF NOT EXISTS idx_dpo_status  ON dpo_payments(status, created_at);
CREATE INDEX IF NOT EXISTS idx_dpo_client  ON dpo_payments(crm_client_id, created_at);
CREATE INDEX IF NOT EXISTS idx_dpo_invoice ON dpo_payments(crm_invoice_id);

-- A settled payment is a financial record.
CREATE TRIGGER IF NOT EXISTS dpo_settled_is_history
BEFORE UPDATE ON dpo_payments
WHEN OLD.status = 'SUCCESS' AND NEW.status NOT IN ('SUCCESS','REFUNDED')
BEGIN
    SELECT RAISE(ABORT, 'a settled payment cannot be un-settled — refund it');
END;

CREATE TRIGGER IF NOT EXISTS dpo_never_deleted
BEFORE DELETE ON dpo_payments
BEGIN
    SELECT RAISE(ABORT, 'payments are never deleted');
END;

-- ── Audit ───────────────────────────────────────────────────────────────
-- initiated redirected callback_received verify_requested verify_succeeded
-- verify_failed marked_success crm_payment_created invoice_settled
-- receipt_available refunded expired cancelled
CREATE TABLE IF NOT EXISTS dpo_payment_events (
    id             INTEGER PRIMARY KEY AUTOINCREMENT,
    dpo_payment_id INTEGER NOT NULL,
    event          TEXT    NOT NULL,
    detail         TEXT,                    -- never a credential, never a PAN
    actor          TEXT    NOT NULL DEFAULT 'system',
    created_at     TEXT    NOT NULL DEFAULT (datetime('now'))
);

CREATE INDEX IF NOT EXISTS idx_dpoe_pay ON dpo_payment_events(dpo_payment_id, id);

CREATE TRIGGER IF NOT EXISTS dpoe_never_deleted
BEFORE DELETE ON dpo_payment_events
BEGIN
    SELECT RAISE(ABORT, 'payment history is never deleted');
END;
```

**Credentials are not in this schema and never will be.** They live in
`ConfigVault`, added to `VAULT_KEYS`:

```
dpo_environment      test | live
dpo_company_token    the bearer secret (vaulted, never logged, never rendered)
dpo_service_type     issued by DPO
dpo_company_acc_ref  issued by DPO
dpo_payment_method_uuid   the uCRM "DPO Pay" method (see A8, Blocker 1)
dpo_ptl_hours        payment time limit, drives EXPIRED
```

`RedirectURL`, `BackURL` and the push URL are **derived** at call time from
`dn_plugin_public($config)`, not stored — so they cannot drift from the install's
real address.

---

## D. API flow

### D1. Initiating a payment

```
Portal "Pay Now"
  │  POST public.php?page=api&action=dpo_initiate   { invoice_id }
  │  Authorization: Bearer <portal JWT>             ← no amount from the browser
  ▼
DishNet backend
  ├─ ca_require_auth()            → client id from JWT claims (never from body)
  ├─ GET uCRM invoices/{id}       → LIVE. not the portal cache
  ├─ assert invoice.clientId == claim  → else 403, and audit the attempt
  ├─ assert status payable, not void/cancelled/paid
  ├─ payable = total − amountPaid   → assert > 0
  ├─ assert invoice.currencyCode is acceptable  → else refuse, never convert
  ├─ reuse any open attempt for this invoice (idx_dpo_open_invoice)
  ├─ INSERT dpo_payments (CREATED, reference = DPO-{invoice}-{client}-{rand})
  ▼
POST https://secure.3gdirectpay.com/API/v6/    <Request>createToken</Request>
  ▼
DPO → Result 000, TransToken, TransRef
  │   (940 "CompanyRef already exists and paid" → treat as already settled,
  │    reconcile rather than charge again)
  ▼
UPDATE dpo_payments → PENDING, token stored, checkout_url built
  ▼
Browser receives ONLY { checkout_url }         ← no token secret, no credentials
  ▼
UPDATE → REDIRECTED, event 'redirected'
  ▼
Customer completes payment on DPO's hosted page (card / MTN / Airtel)
```

### D2. Learning the result — three ways in, one way to settle

```
(1) BROWSER RETURN          (2) DPO SERVER PUSH        (3) RECONCILE CRON
public.php?page=dpo_return   public.php?page=dpo_push   cron/dpo_reconcile.php
  ?TransactionToken=…          XML <API3G> body           every N minutes
        │                      echo 'OK'                  picks up PENDING /
        │                            │                    REDIRECTED older than
        │                            │                    the cutoff
        └───────────┬────────────────┴──────────────────────────┘
                    ▼
        ALL THREE CONVERGE ON ONE FUNCTION
        DpoPaymentService::verifyAndSettle($reference)
                    │
                    ├─ POST /API/v7/  <Request>verifyToken</Request>   ← always
                    │
                    ├─ Result 000 → cross-check CompanyRef == our reference
                    │               cross-check amount == our stored amount
                    │               cross-check currency == our stored currency
                    │               │
                    │               ├─ BEGIN IMMEDIATE
                    │               ├─ re-read row; already SUCCESS → return, no-op
                    │               ├─ createPaymentSafe() → uCRM payment
                    │               ├─ UPDATE → SUCCESS, crm_payment_id, settled_at
                    │               │     (idx_dpo_crm_payment makes a second
                    │               │      settlement impossible, not unlikely)
                    │               └─ COMMIT
                    │
                    ├─ 001 / 900 → PENDING   (001 is authorized, NOT paid)
                    ├─ 901       → FAILED
                    ├─ 903       → EXPIRED
                    ├─ 904       → CANCELLED
                    └─ 002       → quarantine for admin review (over/underpaid)
                    ▼
        uCRM applies the payment to the invoice  ← uCRM marks it paid, not us
                    ▼
        Receipt = the uCRM invoice PDF, already served by
        action=app_invoice_pdf_download
                    ▼
        Existing business workflow runs unchanged: uCRM's own
        payment→service handling, plus our existing reconciliation crons
```

The single most important property of this diagram: **there is exactly one
function that can mark a payment successful, and it always calls DPO first.**
A browser cannot reach it with a forged result, because it does not take a result
— it takes a reference and goes and asks.

---

## E. UI changes

| Screen | File | Change |
|---|---|---|
| Portal invoice detail | `tabs/customer_app/portal.php` (~line 1273) | Add the outstanding-amount block and **Pay Now**. Button disabled when `due <= 0`. On click → "Processing payment…" → redirect. |
| Portal return — success | new view | "Payment successful", invoice number, amount, our reference. **Rendered only when the stored row is SUCCESS** — never from the URL. Then *View Receipt* (existing invoice PDF action) and *Return to Dashboard*. |
| Portal return — pending | new view | "Payment is being confirmed." Never says successful. Polls `action=dpo_status` a few times, then tells the customer it will complete on its own. |
| Portal return — failed/cancelled | new view | "Payment was not completed." + **Try Again**. |
| Admin — Payments (DPO) | new tab `tabs/admin/dpo_payments.php` | Filter by status, date, customer, invoice, reference, environment. Row → full transaction detail. Shows `dpo_payment_events` as a timeline. **Never renders the company token.** |
| Admin — Settings | `tabs/admin/settings.php` | DPO card: environment selector, credential entry (write-only — displays "configured", never the value), push/redirect URLs shown read-only for copying into the DPO portal, connection test. |
| Sidebar | `includes/navigation.php` | Link the new admin tab. The sidebar is hand-written links, not generated — a registered tab that is not linked is one only its author can find. |

---

## F. Security assessment

**F1. Credentials.** Company token lives in `ConfigVault` (0600, outside the data
dir, survives re-install), exactly like `efris_private_key`. It is sent only in a
server-side POST body. It is never rendered, never logged, never put in a URL,
never in a `dpo_payments` row, and never reaches the browser. The admin screen
shows "configured / not configured", not the value. The browser receives exactly
one thing from the initiate call: the DPO checkout URL.

**F2. The push endpoint has no signature, so it is treated as a doorbell, not a
message.** DPO provides no HMAC or signature (B6), and their own plugin acts on
`Result=000` straight from the push. We will not — because that endpoint is
public, and anyone who learns a CompanyRef could otherwise post
`<Result>000</Result>` and settle an invoice for free. Our handler takes only the
reference from the push, answers `OK`, and then **independently asks DPO what
happened** via verifyToken. The push tells us *when* to look; DPO's API tells us
*what is true*. The endpoint is also rate-limited and logs unknown references.

**F3. The browser redirect is treated the same way.** `?TransactionToken=` is
a hint to go and verify. No status is ever taken from a query string, which is
what section 9 of the brief requires.

**F4. Amount and ownership.** The amount is read live from uCRM at initiation and
stored on the row; the browser never supplies it. At settlement, the verified
transaction's amount and currency are compared with the stored row, and
`CompanyRef` with our reference — a mismatch settles nothing and raises an alert.
Invoice ownership is checked against the JWT claim, not a body field, so one
customer cannot pay (or probe) another's invoice.

**F5. Idempotency is a database property, not a code path.** Four constraints do
the work: unique `reference`, unique `dpo_trans_token`, unique `crm_payment_id`,
and one open attempt per invoice. Settlement runs inside `BEGIN IMMEDIATE` and
re-reads the row before acting. So a duplicate callback, a refreshed return page,
a double Pay Now and the reconcile cron racing all converge on "already settled,
nothing to do" — and if application logic somehow lost the race, the unique index
would still refuse the second write. DPO's own code 940 backs this up on their
side. Over/underpayment (002) is quarantined for a human rather than guessed at,
because a partial payment against an invoice is a finance decision.

**F6. Test money never becomes real money.** `environment` is stamped on every
row from the config in force at creation; test rows are visually separated in the
admin screen and excluded from revenue reporting. Given that test and live share
one URL and differ only by token (B10), this stamp is the only thing that keeps
the two apart — so it is recorded per row, not read from current config at
display time.

**F7. Audit without leakage.** Every event in D2 is appended to
`dpo_payment_events`, which cannot be deleted. Details are result codes,
references and timestamps. Never the company token, never card data — we never
receive card data, because checkout is hosted (B5).

---

## G. Implementation plan

Each step is independently testable and independently revertable. Nothing before
step 6 can move a shilling.

| # | Step | Deliverable | Risk |
|---|---|---|---|
| **0** | **Confirm with DPO** the B8/B9/B10 unknowns, and create the **"DPO Pay" payment method in uCRM** (A8) | answers + method UUID | **blocks everything** |
| 1 | `migrations/071_dpo_payments.sql` + `lib/DpoPaymentStore.php` | schema and its guard rails, tested against the constraints | none — additive |
| 2 | `lib/DpoClient.php` — createToken / verifyToken, XML build and parse, full result-code tables | pure client, no business logic | none — no callers yet |
| 3 | `tests/fixtures/fake_dpo_server.php` + `tests/test_dpo_client.php` | every result code from B4/B7 exercised, as `fake_efris_server.php` does for EFRIS | none |
| 4 | `lib/DpoPaymentService.php` — initiate, verifyAndSettle, state machine | the one place a payment can settle | none — no entry points yet |
| 5 | `tests/test_dpo_payment_service.php` — all 14 scenarios from the brief | duplicate callback, double Pay Now, already-paid, partial, expired ref, provider timeout, verify unavailable | none |
| 6 | Endpoints: `dpo_initiate`, `dpo_status`, `page=dpo_return`, `page=dpo_push` | reachable, admin-gated where relevant | **first live path — test token only** |
| 7 | `cron/dpo_reconcile.php` + `cron/master.php` entry | the third leg; abandoned browsers recovered | low |
| 8 | Portal UI: Pay Now + three return states | customer-facing | low |
| 9 | Admin tab + settings card + navigation link | reconciliation and configuration | none |
| 10 | End-to-end on the **test** company token: card, MTN, Airtel, failure, cancel, pending, duplicate callback | signed-off test evidence | none — test environment |
| 11 | Go-live: live token in vault, DPO portal push URL set, one small real payment reconciled by hand | production | **switch of one config value** |

**Testing note.** Because DPO has no sandbox host (B10), steps 3–5 run against a
local fake DPO server in the test suite — the same approach already used for
EFRIS (`tests/fixtures/fake_efris_server.php`) and Evolution
(`tests/fixtures/fake_evo_server.php`). That gives deterministic coverage of every result
code including the ones DPO's own test account will rarely produce. Step 10 then
exercises the real thing with DPO's test token.

---

## Open questions for approval

1. **uCRM payment method** — confirm creating a "DPO Pay" method in uCRM.
   Without it these book as Cash and pollute agent cash reconciliation (A8).
2. **Which invoices are payable online** — all outstanding, or only ones past a
   threshold / not already with an agent?
3. **Partial payment** — may a customer pay part of an invoice, or only the full
   outstanding amount? The schema supports either; the policy is yours.
4. **DPO fees** — are DPO's charges absorbed, or added to the customer? This
   changes the amount sent to DPO and must be decided before step 4.
5. **Payment time limit** — what `PTL` should a DishNet invoice payment have?
   This is what drives the EXPIRED state.
