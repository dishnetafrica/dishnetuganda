-- ═══════════════════════════════════════════════════════════════════
-- 066: Purchases get line items, tax, and a payable.
--
-- stock_purchases was a header: supplier, one total, a payment_method and a
-- cb_ledger_id that nothing ever filled in. What was bought, how many, at
-- what unit cost, with what VAT — none of it was stored. The receiving code
-- read an items[] array off the request, created the stock, and threw the
-- array away, so the cost of a single router could not be answered from the
-- database even though it had just been typed in.
--
-- Nor was there a payable. A purchase was 'received' and that was the end of
-- it: no amount paid, no balance, no way to ask what DishNet owes a supplier.
--
-- The status vocabulary deliberately matches fiber_supplier_invoices
-- (received → verified → approved → paid), which is the one purchase flow on
-- this system that was already finished. Two vocabularies for the same idea
-- is how a dashboard ends up reporting two different payables.
-- ═══════════════════════════════════════════════════════════════════

CREATE TABLE IF NOT EXISTS stock_purchase_items (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    purchase_id     INTEGER NOT NULL REFERENCES stock_purchases(id),
    category_id     INTEGER REFERENCES stock_categories(id),
    description     TEXT    NOT NULL DEFAULT '',
    quantity        REAL    NOT NULL DEFAULT 0,
    unit_cost       REAL    NOT NULL DEFAULT 0,
    tax_rate        REAL    NOT NULL DEFAULT 0,     -- percent, e.g. 18 for Uganda VAT
    tax_amount      REAL    NOT NULL DEFAULT 0,
    line_total      REAL    NOT NULL DEFAULT 0,     -- net + tax
    serials         TEXT    NOT NULL DEFAULT '',    -- JSON array, serial-tracked lines
    created_at      TEXT    NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_spi_purchase ON stock_purchase_items(purchase_id);
CREATE INDEX IF NOT EXISTS idx_spi_category ON stock_purchase_items(category_id);

-- Money the header could not previously hold. total_cost already exists and
-- keeps its meaning: the gross the supplier billed.
ALTER TABLE stock_purchases ADD COLUMN subtotal     REAL NOT NULL DEFAULT 0;
ALTER TABLE stock_purchases ADD COLUMN tax_total    REAL NOT NULL DEFAULT 0;
ALTER TABLE stock_purchases ADD COLUMN amount_paid  REAL NOT NULL DEFAULT 0;
ALTER TABLE stock_purchases ADD COLUMN supplier_ref TEXT NOT NULL DEFAULT '';
ALTER TABLE stock_purchases ADD COLUMN due_date     TEXT;

-- Outstanding is NOT stored. total_cost - amount_paid is one subtraction, and
-- a stored copy is a number that can disagree with the two it came from.

-- The idempotency key. Receiving stock is a form submitted by a person on a
-- bad connection: without this, a double-tap files the same delivery twice
-- and doubles the stock. Empty means "not supplied", so old rows are exempt.
ALTER TABLE stock_purchases ADD COLUMN idem_key TEXT NOT NULL DEFAULT '';
CREATE UNIQUE INDEX IF NOT EXISTS idx_sp_idem ON stock_purchases(idem_key)
    WHERE idem_key != '';

-- Supplier payments. A purchase can be paid in instalments, and each one is
-- its own event with its own actor, date and cash-book link.
CREATE TABLE IF NOT EXISTS stock_purchase_payments (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    purchase_id     INTEGER NOT NULL REFERENCES stock_purchases(id),
    paid_on         TEXT    NOT NULL,
    amount          REAL    NOT NULL,
    currency        TEXT    NOT NULL DEFAULT 'UGX',
    method          TEXT    NOT NULL DEFAULT 'cash',
    reference       TEXT    NOT NULL DEFAULT '',
    cb_ledger_id    INTEGER,
    paid_by         INTEGER,
    paid_by_name    TEXT    NOT NULL DEFAULT '',
    note            TEXT    NOT NULL DEFAULT '',
    created_at      TEXT    NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_spp_purchase ON stock_purchase_payments(purchase_id);
CREATE INDEX IF NOT EXISTS idx_spp_date     ON stock_purchase_payments(paid_on);

-- Bulk stock had no cost at all. stock_quantities carried qty_on_hand and
-- nothing else, so inventory value counted serialised units and treated
-- every cable, mount and router as worth zero — "stock says we have it but
-- accounting does not know what it cost", exactly.
--
-- Weighted average, because consumables arrive at different prices and
-- nobody is going to track which specific metre of cable came from which
-- delivery. Maintained on receipt — consumption leaves the average alone.
ALTER TABLE stock_quantities ADD COLUMN avg_cost REAL NOT NULL DEFAULT 0;
