-- 088_bank_statements.sql — the Bank screen v1 (5.18.97, its own release after P3b). Ecobank statements checked and kept,
-- never posted: nothing here is read by the Cashbook, and nothing here writes cb_ledger. Uganda only reads or writes
-- these tables; on every other install they stay empty. No seed. No foreign key: cb_accounts is created by
-- CashbookService at runtime, not by a migration, and cb_ledger rows are hard-deleted by the Cashbook. Trigger bodies hold
-- one RAISE each; every condition sits in WHEN, before BEGIN, so the runner's splitter keeps each trigger whole.

-- Which book account is which Ecobank account: confirmed once, by an admin, from the first file. The last four digits only.
CREATE TABLE IF NOT EXISTS bank_account_profiles (
    cb_account_id    INTEGER PRIMARY KEY,                  -- cb_accounts.id
    bank             TEXT    NOT NULL,                     -- 'ecobank' (BankStatementStore::BANKS)
    profile          TEXT    NOT NULL,                     -- 'ecobank_account_details_csv'
    currency         TEXT    NOT NULL,                     -- the account's currency at confirmation
    acct_last4       TEXT    NOT NULL,                     -- four digits; never the full number
    confirmed_by_id  INTEGER NOT NULL,                     -- retailers.id, re-read
    confirmed_at     TEXT    NOT NULL DEFAULT (datetime('now')),
    CHECK (length(acct_last4) = 4 AND acct_last4 NOT GLOB '*[^0-9]*'),
    CHECK (length(currency) = 3 AND currency NOT GLOB '*[^A-Z]*')
);
CREATE UNIQUE INDEX IF NOT EXISTS uq_bank_profiles_acct ON bank_account_profiles(bank, currency, acct_last4);
CREATE TRIGGER IF NOT EXISTS bank_profiles_no_update BEFORE UPDATE ON bank_account_profiles FOR EACH ROW
BEGIN SELECT RAISE(ABORT, 'bank_account_profiles: a confirmed account link is never changed'); END;
CREATE TRIGGER IF NOT EXISTS bank_profiles_no_delete BEFORE DELETE ON bank_account_profiles FOR EACH ROW
BEGIN SELECT RAISE(ABORT, 'bank_account_profiles: a confirmed account link is never deleted'); END;

-- Every check of a file, passed or refused. A passed check waits to be kept or discarded; nothing else ever changes.
CREATE TABLE IF NOT EXISTS bank_checks (
    id               INTEGER PRIMARY KEY AUTOINCREMENT,
    cb_account_id    INTEGER NOT NULL,
    sha256           TEXT    NOT NULL,
    bytes            INTEGER NOT NULL,
    profile          TEXT    NOT NULL,
    parser_version   INTEGER NOT NULL,
    outcome          TEXT    NOT NULL,                     -- passed | nothing_new | already_kept | refused
    code             TEXT    NOT NULL DEFAULT '',          -- the refusal or the note, from a fixed list
    param            TEXT    NOT NULL DEFAULT '',          -- a line number, a bank line no., a date, a currency, last four
    currency         TEXT    NOT NULL DEFAULT '',
    acct_last4       TEXT    NOT NULL DEFAULT '',
    line_count       INTEGER NOT NULL DEFAULT 0,
    lines_new        INTEGER NOT NULL DEFAULT 0,
    lines_known      INTEGER NOT NULL DEFAULT 0,
    period_from      TEXT    NOT NULL DEFAULT '',
    period_to        TEXT    NOT NULL DEFAULT '',
    opening_stated   INTEGER,                              -- minor units (cents)
    closing_stated   INTEGER,
    opening_derived  INTEGER,
    credit_count     INTEGER NOT NULL DEFAULT 0,
    credit_units     INTEGER NOT NULL DEFAULT 0,
    debit_count      INTEGER NOT NULL DEFAULT 0,
    debit_units      INTEGER NOT NULL DEFAULT 0,
    warnings         TEXT    NOT NULL DEFAULT '',          -- comma-separated codes
    pending_rel      TEXT    NOT NULL DEFAULT '',          -- bank_statements/.pending/<server name> while waiting
    status           TEXT    NOT NULL,                     -- waiting | kept | discarded | expired | closed
    checked_by_id    INTEGER NOT NULL,
    checked_at       TEXT    NOT NULL DEFAULT (datetime('now')),
    closed_by_id     INTEGER,
    closed_at        TEXT,
    statement_id     INTEGER,
    CHECK (outcome IN ('passed', 'nothing_new', 'already_kept', 'refused')),
    CHECK (status IN ('waiting', 'kept', 'discarded', 'expired', 'closed')),
    CHECK (outcome = 'passed' OR status = 'closed'),
    CHECK (status <> 'kept' OR statement_id IS NOT NULL),
    CHECK (length(param) <= 24 AND param NOT GLOB '*[^0-9A-Z|-]*'),
    CHECK (length(code) <= 32 AND code NOT GLOB '*[^a-z_]*')
);
CREATE INDEX IF NOT EXISTS idx_bank_checks_acct ON bank_checks(cb_account_id, id);
CREATE INDEX IF NOT EXISTS idx_bank_checks_waiting ON bank_checks(status, checked_at);
CREATE TRIGGER IF NOT EXISTS bank_checks_fixed BEFORE UPDATE ON bank_checks FOR EACH ROW WHEN (
       NEW.id IS NOT OLD.id OR NEW.cb_account_id IS NOT OLD.cb_account_id OR NEW.sha256 IS NOT OLD.sha256
    OR NEW.bytes IS NOT OLD.bytes OR NEW.profile IS NOT OLD.profile OR NEW.parser_version IS NOT OLD.parser_version
    OR NEW.outcome IS NOT OLD.outcome OR NEW.code IS NOT OLD.code OR NEW.param IS NOT OLD.param
    OR NEW.currency IS NOT OLD.currency OR NEW.acct_last4 IS NOT OLD.acct_last4 OR NEW.line_count IS NOT OLD.line_count
    OR NEW.lines_new IS NOT OLD.lines_new OR NEW.lines_known IS NOT OLD.lines_known OR NEW.period_from IS NOT OLD.period_from
    OR NEW.period_to IS NOT OLD.period_to OR NEW.opening_stated IS NOT OLD.opening_stated
    OR NEW.closing_stated IS NOT OLD.closing_stated OR NEW.opening_derived IS NOT OLD.opening_derived
    OR NEW.credit_count IS NOT OLD.credit_count OR NEW.credit_units IS NOT OLD.credit_units
    OR NEW.debit_count IS NOT OLD.debit_count OR NEW.debit_units IS NOT OLD.debit_units OR NEW.warnings IS NOT OLD.warnings
    OR NEW.pending_rel IS NOT OLD.pending_rel OR NEW.checked_by_id IS NOT OLD.checked_by_id
    OR NEW.checked_at IS NOT OLD.checked_at)
BEGIN SELECT RAISE(ABORT, 'bank_checks: what a check found is fixed once it is recorded'); END;
CREATE TRIGGER IF NOT EXISTS bank_checks_moves BEFORE UPDATE ON bank_checks FOR EACH ROW WHEN NOT (
       OLD.status = 'waiting' AND NEW.status IN ('kept', 'discarded', 'expired')
       AND OLD.closed_at IS NULL AND NEW.closed_at IS NOT NULL)
BEGIN SELECT RAISE(ABORT, 'bank_checks: a waiting check is kept, discarded or expired, once; nothing else changes'); END;
CREATE TRIGGER IF NOT EXISTS bank_checks_no_delete BEFORE DELETE ON bank_checks FOR EACH ROW
BEGIN SELECT RAISE(ABORT, 'bank_checks: a check is never deleted'); END;

-- A kept statement: the file, where it is stored, and what it proved. Every v1 column is fixed once written.
CREATE TABLE IF NOT EXISTS bank_statements (
    id                  INTEGER PRIMARY KEY AUTOINCREMENT,
    cb_account_id       INTEGER NOT NULL,
    check_id            INTEGER NOT NULL,
    sha256              TEXT    NOT NULL,
    bytes               INTEGER NOT NULL,
    file_rel            TEXT    NOT NULL,                  -- bank_statements/<cb_account_id>/<server name>.csv
    profile             TEXT    NOT NULL,
    parser_version      INTEGER NOT NULL,
    currency            TEXT    NOT NULL,
    acct_last4          TEXT    NOT NULL,
    period_from         TEXT    NOT NULL,                  -- first and last posting dates, ISO
    period_to           TEXT    NOT NULL,
    line_count          INTEGER NOT NULL,
    lines_added         INTEGER NOT NULL,
    lines_known         INTEGER NOT NULL,
    opening_stated      INTEGER NOT NULL,                  -- Opening Available, minor units
    closing_stated      INTEGER NOT NULL,                  -- Closing Available
    opening_derived     INTEGER NOT NULL,                  -- first running − first credit + first debit, SEQUENCE order
    closing_derived     INTEGER NOT NULL,                  -- last running, SEQUENCE order
    cheque_first        TEXT    NOT NULL,
    cheque_last         TEXT    NOT NULL,
    file_order_differs  INTEGER NOT NULL DEFAULT 0,
    warnings            TEXT    NOT NULL DEFAULT '',
    kept_by_id          INTEGER NOT NULL,
    kept_at             TEXT    NOT NULL DEFAULT (datetime('now')),
    CHECK (lines_added >= 1 AND lines_known >= 0 AND lines_added + lines_known = line_count),
    CHECK (closing_derived = closing_stated)
);
CREATE UNIQUE INDEX IF NOT EXISTS uq_bank_statements_sha ON bank_statements(cb_account_id, sha256);
CREATE UNIQUE INDEX IF NOT EXISTS uq_bank_statements_check ON bank_statements(check_id);
CREATE TRIGGER IF NOT EXISTS bank_statements_fixed BEFORE UPDATE ON bank_statements FOR EACH ROW WHEN (
       NEW.id IS NOT OLD.id OR NEW.cb_account_id IS NOT OLD.cb_account_id OR NEW.check_id IS NOT OLD.check_id
    OR NEW.sha256 IS NOT OLD.sha256 OR NEW.bytes IS NOT OLD.bytes OR NEW.file_rel IS NOT OLD.file_rel
    OR NEW.profile IS NOT OLD.profile OR NEW.parser_version IS NOT OLD.parser_version OR NEW.currency IS NOT OLD.currency
    OR NEW.acct_last4 IS NOT OLD.acct_last4 OR NEW.period_from IS NOT OLD.period_from OR NEW.period_to IS NOT OLD.period_to
    OR NEW.line_count IS NOT OLD.line_count OR NEW.lines_added IS NOT OLD.lines_added OR NEW.lines_known IS NOT OLD.lines_known
    OR NEW.opening_stated IS NOT OLD.opening_stated OR NEW.closing_stated IS NOT OLD.closing_stated
    OR NEW.opening_derived IS NOT OLD.opening_derived OR NEW.closing_derived IS NOT OLD.closing_derived
    OR NEW.cheque_first IS NOT OLD.cheque_first OR NEW.cheque_last IS NOT OLD.cheque_last
    OR NEW.file_order_differs IS NOT OLD.file_order_differs OR NEW.warnings IS NOT OLD.warnings
    OR NEW.kept_by_id IS NOT OLD.kept_by_id OR NEW.kept_at IS NOT OLD.kept_at)
BEGIN SELECT RAISE(ABORT, 'bank_statements: a kept statement is never changed'); END;
CREATE TRIGGER IF NOT EXISTS bank_statements_no_delete BEFORE DELETE ON bank_statements FOR EACH ROW
BEGIN SELECT RAISE(ABORT, 'bank_statements: a kept statement is never deleted'); END;

-- One bank line, kept once per account by the bank's own line identity (Cheque Number). Every column verbatim.
CREATE TABLE IF NOT EXISTS bank_statement_lines (
    id                     INTEGER PRIMARY KEY AUTOINCREMENT,
    statement_id           INTEGER NOT NULL,               -- the statement that first brought it
    cb_account_id          INTEGER NOT NULL,
    cheque_number          TEXT    NOT NULL,               -- the bank's line identity, 10 digits
    seq_nmbr               INTEGER NOT NULL,               -- SEQUENCE_NMBR in that statement
    sr_no                  TEXT    NOT NULL,
    file_row               INTEGER NOT NULL,               -- the row in that file (the file order can differ)
    posting_date           TEXT    NOT NULL,               -- ISO, read from posting_raw
    value_date             TEXT    NOT NULL,
    posting_raw            TEXT    NOT NULL,               -- DD-MON-YY as the bank wrote it
    value_raw              TEXT    NOT NULL,
    type_code              TEXT    NOT NULL,
    type_desc              TEXT    NOT NULL,
    txn_code               TEXT    NOT NULL,
    credit_text            TEXT    NOT NULL,               -- as the bank wrote it, surrounding spaces removed ('' when a debit)
    debit_text             TEXT    NOT NULL,
    credit_units           INTEGER NOT NULL,               -- minor units, exact
    debit_units            INTEGER NOT NULL,
    running_text           TEXT    NOT NULL,               -- with the bank's padding: for display only, never compared
    running_units          INTEGER NOT NULL,
    currency               TEXT    NOT NULL,
    cust_ref               TEXT    NOT NULL,
    bank_ref               TEXT    NOT NULL,
    remarks                TEXT    NOT NULL,               -- names counterparties: restricted, bank.view only
    loan_outstanding       TEXT    NOT NULL,
    total_amount           TEXT    NOT NULL,
    reference              TEXT    NOT NULL,               -- shared by a transfer and its fee lines
    customer_reference     TEXT    NOT NULL,
    transaction_reference  TEXT    NOT NULL,
    fingerprint            TEXT    NOT NULL,               -- sha256 of (posting date, direction, units, running units, remarks)
    compare_digest         TEXT    NOT NULL,               -- sha256 of the compared values (EcobankDetailsCsv::COMPARED), recomputable
    warnings               TEXT    NOT NULL DEFAULT '',
    status                 TEXT    NOT NULL DEFAULT 'imported',  -- v1 writes only 'imported'; P3c gives it a lifecycle
    created_at             TEXT    NOT NULL DEFAULT (datetime('now')),
    CHECK (length(cheque_number) = 10 AND cheque_number NOT GLOB '*[^0-9]*'),
    CHECK ((credit_text <> '') + (debit_text <> '') = 1),
    CHECK (credit_units >= 0 AND debit_units >= 0 AND (credit_units = 0 OR debit_units = 0)),
    CHECK (length(compare_digest) = 64 AND compare_digest NOT GLOB '*[^0-9a-f]*')
);
CREATE UNIQUE INDEX IF NOT EXISTS uq_bank_lines_chq ON bank_statement_lines(cb_account_id, cheque_number);
CREATE INDEX IF NOT EXISTS idx_bank_lines_date ON bank_statement_lines(cb_account_id, posting_date, cheque_number);
CREATE INDEX IF NOT EXISTS idx_bank_lines_ref ON bank_statement_lines(cb_account_id, reference);
CREATE INDEX IF NOT EXISTS idx_bank_lines_stmt ON bank_statement_lines(statement_id, seq_nmbr);
CREATE TRIGGER IF NOT EXISTS bank_lines_fixed BEFORE UPDATE ON bank_statement_lines FOR EACH ROW WHEN (
       NEW.id IS NOT OLD.id OR NEW.statement_id IS NOT OLD.statement_id OR NEW.cb_account_id IS NOT OLD.cb_account_id
    OR NEW.cheque_number IS NOT OLD.cheque_number OR NEW.seq_nmbr IS NOT OLD.seq_nmbr OR NEW.sr_no IS NOT OLD.sr_no
    OR NEW.file_row IS NOT OLD.file_row OR NEW.posting_date IS NOT OLD.posting_date OR NEW.value_date IS NOT OLD.value_date
    OR NEW.posting_raw IS NOT OLD.posting_raw OR NEW.value_raw IS NOT OLD.value_raw OR NEW.type_code IS NOT OLD.type_code
    OR NEW.type_desc IS NOT OLD.type_desc OR NEW.txn_code IS NOT OLD.txn_code OR NEW.credit_text IS NOT OLD.credit_text
    OR NEW.debit_text IS NOT OLD.debit_text OR NEW.credit_units IS NOT OLD.credit_units OR NEW.debit_units IS NOT OLD.debit_units
    OR NEW.running_text IS NOT OLD.running_text OR NEW.running_units IS NOT OLD.running_units OR NEW.currency IS NOT OLD.currency
    OR NEW.cust_ref IS NOT OLD.cust_ref OR NEW.bank_ref IS NOT OLD.bank_ref OR NEW.remarks IS NOT OLD.remarks
    OR NEW.loan_outstanding IS NOT OLD.loan_outstanding OR NEW.total_amount IS NOT OLD.total_amount
    OR NEW.reference IS NOT OLD.reference OR NEW.customer_reference IS NOT OLD.customer_reference
    OR NEW.transaction_reference IS NOT OLD.transaction_reference OR NEW.fingerprint IS NOT OLD.fingerprint OR NEW.compare_digest IS NOT OLD.compare_digest
    OR NEW.warnings IS NOT OLD.warnings OR NEW.created_at IS NOT OLD.created_at)
BEGIN SELECT RAISE(ABORT, 'bank_statement_lines: an imported line is never changed'); END;
CREATE TRIGGER IF NOT EXISTS bank_lines_no_delete BEFORE DELETE ON bank_statement_lines FOR EACH ROW
BEGIN SELECT RAISE(ABORT, 'bank_statement_lines: an imported line is never deleted'); END;

-- Append-only: who did what on the Bank screen, when. detail holds codes, counts, a sha256 and last four — never a cell.
CREATE TABLE IF NOT EXISTS bank_line_events (
    id             INTEGER PRIMARY KEY AUTOINCREMENT,
    event          TEXT    NOT NULL,                       -- BankStatementStore::EVENTS
    cb_account_id  INTEGER,
    check_id       INTEGER,
    statement_id   INTEGER,
    line_id        INTEGER,
    actor_kind     TEXT    NOT NULL,                       -- staff | system
    actor_id       INTEGER,                                -- retailers.id for staff
    detail         TEXT    NOT NULL DEFAULT '',
    created_at     TEXT    NOT NULL DEFAULT (datetime('now')),
    CHECK (actor_kind IN ('staff', 'system')),
    CHECK (actor_kind <> 'staff' OR actor_id IS NOT NULL)
);
CREATE INDEX IF NOT EXISTS idx_bank_events_acct ON bank_line_events(cb_account_id, id);
CREATE INDEX IF NOT EXISTS idx_bank_events_check ON bank_line_events(check_id);
CREATE TRIGGER IF NOT EXISTS bank_events_no_update BEFORE UPDATE ON bank_line_events FOR EACH ROW
BEGIN SELECT RAISE(ABORT, 'bank_line_events: the Bank trail is append-only; events are never changed'); END;
CREATE TRIGGER IF NOT EXISTS bank_events_no_delete BEFORE DELETE ON bank_line_events FOR EACH ROW
BEGIN SELECT RAISE(ABORT, 'bank_line_events: the Bank trail is append-only; events are never deleted'); END;
