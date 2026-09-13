-- 071_dpo_payments.sql — invoice payments taken online through DPO Pay.
--
-- ── WHY A TABLE OF ITS OWN ──────────────────────────────────────────────
--
-- payment_collections.json looks like a payment ledger and is not: every row
-- carries retailer_id and handover_id, and it feeds agent cash balances, cash
-- handover reconciliation and the staff ledger. An online card payment has no
-- agent and no cash. Writing one there would put money into somebody's cash
-- position that they never held, and the next handover would demand it.
--
-- ── WHAT THIS TABLE IS NOT ──────────────────────────────────────────────
--
-- It is not the invoice. uCRM owns invoice settlement: we post a payment with
-- applyToInvoicesAutomatically and uCRM decides what it clears. This table
-- records OUR side of one attempt to collect — so finance can reconcile
-- DishNet against DPO, and so a replayed callback has something to collide
-- with.
--
-- ── IDEMPOTENCY IS STRUCTURAL ───────────────────────────────────────────
--
-- Four unique indexes, following the pattern established by 070_followups.sql.
-- Application logic alone loses races; an index does not.

CREATE TABLE IF NOT EXISTS dpo_payments (
    id                  INTEGER PRIMARY KEY AUTOINCREMENT,

    -- OUR reference, sent to DPO as CompanyRef. The idempotency key, and the
    -- only thing a callback is allowed to tell us.
    reference           TEXT    NOT NULL,

    crm_client_id       INTEGER NOT NULL,
    crm_invoice_id      INTEGER NOT NULL,
    invoice_number      TEXT    NOT NULL DEFAULT '',

    -- Read LIVE from uCRM at initiation: total − amountPaid. Never from the
    -- portal cache, never from the browser. This is also the amount the
    -- verified transaction must match, to the cent, or it does not settle.
    amount              REAL    NOT NULL,
    currency            TEXT    NOT NULL,

    status              TEXT    NOT NULL DEFAULT 'CREATED',
    -- CREATED PENDING REDIRECTED SUCCESS FAILED CANCELLED EXPIRED REFUNDED
    -- QUARANTINED — money moved but it is not a clean settlement (underpaid,
    --               overpaid, or the verified figures disagree with ours).
    --               A person decides. Nothing is posted to uCRM.

    -- Which DPO account took this. Test and live share one URL and differ only
    -- by company token, so this stamp IS the separation between a rehearsal
    -- and revenue. Recorded per row, never re-read from current config.
    environment         TEXT    NOT NULL,           -- 'test' | 'live'

    -- OUR expiry, not DPO's. DPO's PTL is advisory and their own modules do
    -- not even send it; this column is what actually expires an attempt, so
    -- correctness never depends on a remote system honouring a hint.
    attempt_expires_at  TEXT,

    dpo_trans_token     TEXT,                       -- TransToken
    dpo_trans_ref       TEXT,                       -- TransRef
    dpo_result          TEXT,                       -- last Result code seen
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

-- Our reference is unique. A double-submitted Pay Now, a replayed callback and
-- a racing reconcile cron cannot produce two rows.
CREATE UNIQUE INDEX IF NOT EXISTS idx_dpo_reference ON dpo_payments(reference);

-- A transaction token identifies exactly one payment, so a replayed push
-- cannot be attached to a second row.
CREATE UNIQUE INDEX IF NOT EXISTS idx_dpo_token
    ON dpo_payments(dpo_trans_token) WHERE dpo_trans_token IS NOT NULL;

-- One uCRM payment per DPO payment. THIS is what makes "never pay an invoice
-- twice" a fact of the database rather than an intention in the code.
CREATE UNIQUE INDEX IF NOT EXISTS idx_dpo_crm_payment
    ON dpo_payments(crm_payment_id) WHERE crm_payment_id IS NOT NULL;

-- At most ONE live attempt per invoice. Clicking Pay Now twice reuses the open
-- attempt rather than opening a rival one against the same money.
CREATE UNIQUE INDEX IF NOT EXISTS idx_dpo_open_invoice
    ON dpo_payments(crm_invoice_id)
    WHERE status IN ('CREATED','PENDING','REDIRECTED');

CREATE INDEX IF NOT EXISTS idx_dpo_status  ON dpo_payments(status, created_at);
CREATE INDEX IF NOT EXISTS idx_dpo_client  ON dpo_payments(crm_client_id, created_at);
CREATE INDEX IF NOT EXISTS idx_dpo_invoice ON dpo_payments(crm_invoice_id);
-- The reconcile cron's working set: open attempts, oldest first.
CREATE INDEX IF NOT EXISTS idx_dpo_open_age
    ON dpo_payments(created_at) WHERE status IN ('CREATED','PENDING','REDIRECTED');

-- A settled payment is a financial record. It may be refunded. It may not be
-- quietly rolled back into something else.
CREATE TRIGGER IF NOT EXISTS dpo_settled_is_history
BEFORE UPDATE ON dpo_payments
WHEN OLD.status = 'SUCCESS' AND NEW.status NOT IN ('SUCCESS','REFUNDED')
BEGIN
    SELECT RAISE(ABORT, 'a settled payment cannot be un-settled — refund it');
END;

-- The money figures are what we asked DPO to collect and what we checked the
-- verification against. Editing either after the fact would invalidate the
-- check that was already performed.
CREATE TRIGGER IF NOT EXISTS dpo_amount_is_fixed
BEFORE UPDATE ON dpo_payments
WHEN OLD.amount <> NEW.amount OR OLD.currency <> NEW.currency
BEGIN
    SELECT RAISE(ABORT, 'the amount and currency of an attempt are fixed at creation');
END;

-- Which account took the money is not editable either — that stamp is the only
-- thing separating a test transaction from revenue.
CREATE TRIGGER IF NOT EXISTS dpo_environment_is_fixed
BEFORE UPDATE ON dpo_payments
WHEN OLD.environment <> NEW.environment
BEGIN
    SELECT RAISE(ABORT, 'the environment of an attempt is fixed at creation');
END;

CREATE TRIGGER IF NOT EXISTS dpo_never_deleted
BEFORE DELETE ON dpo_payments
BEGIN
    SELECT RAISE(ABORT, 'payments are never deleted');
END;

-- ── Audit ───────────────────────────────────────────────────────────────
-- Every step of D2, appended and never rewritten:
--   initiated · token_created · redirected · callback_received ·
--   verify_requested · verify_succeeded · verify_failed · marked_success ·
--   crm_payment_created · quarantined · expired · cancelled · refunded ·
--   disabled_by_flag
--
-- detail carries result codes, references and timestamps. Never a company
-- token. Never card data — we never receive card data, because checkout is
-- hosted by DPO.
CREATE TABLE IF NOT EXISTS dpo_payment_events (
    id             INTEGER PRIMARY KEY AUTOINCREMENT,
    dpo_payment_id INTEGER NOT NULL,
    event          TEXT    NOT NULL,
    detail         TEXT,
    actor          TEXT    NOT NULL DEFAULT 'system',
    created_at     TEXT    NOT NULL DEFAULT (datetime('now'))
);

CREATE INDEX IF NOT EXISTS idx_dpoe_pay  ON dpo_payment_events(dpo_payment_id, id);
CREATE INDEX IF NOT EXISTS idx_dpoe_when ON dpo_payment_events(created_at);

CREATE TRIGGER IF NOT EXISTS dpoe_never_deleted
BEFORE DELETE ON dpo_payment_events
BEGIN
    SELECT RAISE(ABORT, 'payment history is never deleted');
END;

CREATE TRIGGER IF NOT EXISTS dpoe_never_edited
BEFORE UPDATE ON dpo_payment_events
BEGIN
    SELECT RAISE(ABORT, 'payment history is never edited');
END;
