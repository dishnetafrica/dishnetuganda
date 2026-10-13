# 68 — Cashbook bank statement import and reconciliation: discovery and plan

**Date:** 13 October 2026 · **Status: DISCOVERY ONLY.** Nothing was built, migrated, changed or deployed. No production
data was read: this is a read of the code (live `f4b4c96` = 5.18.94, and the development branch) and of the supplied
Ecobank PDF, opened in an isolated folder and read with every digit and name masked. **Coding waits for your approval of
the decisions in §K.**

Method: four read-only reviewers mapped the account model, posting and controls, the existing bank import, and the
infrastructure; a fifth checked their load-bearing claims against the code and corrected seven. Every fact below carries
a `file:line` (paths are under `dishnet-hybrid-sudan/` unless they start with `docs/`, which is the repository root's).

---

## A. What you asked for, in one paragraph

Separate bank accounts (Ecobank UGX/USD, Centenary UGX/USD, once confirmed), a statement upload (CSV, Excel, PDF) into a
review area, protection against duplicate imports and postings, matching to existing Cashbook entries, a reviewer who
classifies each unmatched line — for example *purchase*, *expense*, *transport* — and approves its posting, transfers
between bank and cash that never become income or expense, a reconciliation report with period sign-off, and a full audit
trail. The existing Cashbook stays the only ledger.

## B. The short answer — ten things the design has to start from

1. **A first version is already live.** `lib/BankStatement.php`, `lib/BankImport.php` and `tools/bank_statement.php`
   (11 Sep, commits `c2f0a34f`, `d2ed193b`, `1b745f99`) read an Ecobank CSV, refuse a file whose running balance does
   not add up, book bank charges and supplier payments, and compare the statement's closing balance with the book. It is
   command-line only: no upload, no review area, no statuses. **The new module extends it; it does not run beside it.**
2. **It conflicts with your 4 October decision.** For Uganda you chose *"cash in hand only"* — no bank or working-capital
   figures in the plugin — and the record says `tools/bank_statement.php` *"should not be run again on Uganda"*
   (`docs/07-change-control.md:3580-3583`, `:3778`). A bank module reverses part of that. **Decision D1.**
3. **"Cash in hand" today counts every row of a currency, bank rows included.** `cashInHand()` sums every non-voided row
   of the currency whatever its account (`lib/CashbookService.php:671-686`). It feeds the Uganda Cashbook hero, the
   accounts dashboard and the evening WhatsApp summary. So **every bank charge or deposit posted from a statement would
   change the "UGX CASH IN HAND" figure** you read as physical cash. **Decision D2** — and see point 4.
4. **The book already holds the earlier import, and an adjustment that cancels it.** The 11 Sep import put 34 bank rows
   (card purchases) into the Ecobank UGX account; an *"ADJUSTMENT ENTRY"* of 12 Sep cancels their effect on the running
   balance. The 5.18.68 record measured that *cash rows alone, with the adjustment left out, give the same figure*
   (`docs/07-change-control.md:3536-3542`). So "cash in hand = physical cash only" plus retiring that adjustment could
   leave today's figure unchanged — **to be measured on the live book first (step P0), never assumed. Decision D3.**
5. **Customer bank payments are not in the Cashbook.** Only cash uCRM payments are posted (`webhook.php:1187-1194`,
   `lib/CashbookService.php:2423-2427`, `:2478-2482`). Most credits on a bank statement have no Cashbook entry to match,
   so the bank account will not reconcile unless they are brought in. **Decision D4.**
6. **The ledger cannot hold a unique key.** Every `new CashbookService` drops every UNIQUE index on `cb_ledger`
   (`lib/CashbookService.php:1077-1090`), and `validation_ref` — the only duplicate key — is editable by any logged-in
   user (`:1849-1862`, `:1918`). **Duplicate protection must live in new tables of the module's own.**
7. **There is no maker-checker, no UGX threshold and no period lock today.** Almost every writer posts `approved`
   directly; `approveEntry()` has no state check and can reinstate a voided row (`:2322-2326`); the integrity guard's
   large-amount rules cover USD and SSP only (`lib/TransactionIntegrityGuard.php:211-262`); nothing locks a reconciled
   period. **The module brings its own approval and lock. Decisions D5, D10.**
8. **Your Ecobank PDF can be read, but not with the reader as it is.** It has a full text layer (7 pages, no undecodable
   characters), but its table is drawn column by column on at least one page, and the plugin's PDF reader returns text
   without positions (`lib/PdfReader.php:690-718`), so rows cannot be rebuilt reliably from it. A positional mode is
   needed (§E2). The existing CSV path was built for Ecobank's export and works today.
9. **Centenary appears nowhere in the repository.** The seeded chart has Ecobank UGX and USD only
   (`lib/CashbookService.php:501-524`). **D7: confirm which accounts exist, in which currency.**
10. **Defects found on the way — reported, not fixed** (each its own decision, §H): the Opening Balances screen's forms
    post no security token, so adding an account there may not save at all (`tabs/accounts/opening_balances.php:111-281`,
    `$_csrf` undefined); bank-import transfer legs can be voided one leg at a time (`lib/CashbookService.php:818`); the
    cashbook API and CSV export show every entry to almost every staff role (`includes/api/api_cashbook.php:11-29`,
    `includes/routes.php:223-236`); the CEO dashboard still shows account positions on Uganda
    (`tabs/admin/ceo_dashboard.php:108-116`); FinAudit silently drops any action it does not know (`lib/FinAudit.php:27`).

---

## C. How the Cashbook works today

### C1. Accounts and currency
- **One ledger, `cb_ledger`,** single-entry: a positive `amount` and a `direction` in/out (`lib/CashbookService.php:1012-1091`).
  Each row may carry an `account_id` into **`cb_accounts`** (id, name, currency, kind, active), both tables created in
  PHP at runtime, not by a migration (`:131-142`). Names unique in PHP only; no foreign key (`:158-182`).
- **Kinds:** bank, cash, momo, receivable, payable, inventory, asset, director, equity (`:120-125`). Only bank, cash and
  momo are *money* (`MONEY_KINDS`, `:552`).
- **One currency per account, enforced:** `addEntryRaw()` and `assignAccount()` refuse a row whose currency differs from
  its account's (`:1792-1806`, `:215-221`); transfers, funding and openings take each leg's currency from its account.
  A row with `account_id = 0` can be any currency — **that is every manual, CRM, webhook and expense row today**: no
  entry screen sets an account (`:1742-1788`).
- **Base currency:** `cashbook_base_currency` via `dn_book_base()` (`lib/currency.php:108-133`) — UGX on Uganda (UGX, USD),
  USD on South Sudan (USD, SSP). Unknown codes are silently coerced to the base by `dn_entry_currency()` and `addEntry()`
  (`lib/currency.php:155-163`) — **imported lines must refuse, never coerce.**

### C2. Balances — two arithmetics
- **Account readers** (`accountBalance`, `accountLedger`, `balancesByAccount`, `currencyPositions`, `plByPeriod`) count
  approved rows only, per account, in its currency (`:254-261`, `:580-805`). **The reconciliation's book side must come
  from these.**
- **Stream readers** (`cashInHand`, the `getEntries` running balance) count every non-voided row of a currency, pending
  and rejected included, whatever the account (`:671-686`, `:1950-2031`). Consequences measured by the reviewers: a
  funding's two "in" legs both count; a director or payable opening counts as cash; a cash↔bank transfer nets to zero.
- **Legacy readers hard-wired to USD** (`getBothBalances`, `getBalance`, `getLedger`, `getSummary`, `:2062-2200`) must not be
  used.

### C3. Entries, categories, parties, documents
- **More than twenty writers** post to `cb_ledger`, nearly all `approved` directly (manual entry, uCRM webhook and sync, field
  expenses, advances, payroll, refunds, exchanges, openings, transfers, funding, the bank import…).
- **Categories are free text.** The vocabulary is `CATEGORIES_IN` / `CATEGORIES_OUT` (`:21-41`) and the editable tile list
  `cb_categories.json` (`includes/api/api_cashbook.php:580-745`). Nothing validates a category on write (`:1753`). There is
  **no plain "Purchase" or "Transport"**: the list has *Local Purchase*, *Capital Purchase*, *Transport Allowance*,
  *Travel & Field*, *Vehicle*, *Bank Charges*, *Airtime*, *Bandwidth*, *Site Expense*, *Tax*, *Govt Fees*, *Misc Expense*…
  and the field-expense code maps "transport" two different ways (`lib/ExpenseAdvanceService.php:53` vs
  `includes/post/post_field.php:82`).
- **P&L:** typed rows by `txn_type`, untyped by category; `TRANSFER`, `DIRECTOR_FUNDING`, `OPENING_BALANCE`, `ADJUSTMENT` and
  the capital categories stay out (`:540-559`, `:739-799`). *Capital Purchase* is **not** a capital category — it counts as
  an expense.
- **Parties:** free text (`person`), plus `crm_client_id`; suppliers exist only on `stock_purchases` (`migrations/036`).
- **Documents:** `cb_ledger` has no file column; manual-entry photos are silently dropped (`includes/post/post_cashbook.php:391-395`).
  Real receipt storage exists only for staff expenses (`lib/ExpenseAdvanceService.php:1494-1550`).

### C4. Transfers
`recordAccountTransfer(from, to, amountFrom, amountTo, date, desc, rateSource, admin, ref, source)` writes an out leg and an
in leg, both `TRANSFER`, category *Bank Transfer*, one shared reference; same-currency must be equal, cross-currency needs a
rate source and records `fx_*` on the receiving leg (`:329-395`). **No fee leg** — fees are separate *Bank Charges* rows.
Pair protection (void both legs, refuse edits) covers only sources `account_transfer`, `funding`, `fx_exchange`
(`:818`, `:1854`, `:1894`) — **not `bank_import`.** No transfer is written in a database transaction (no `BEGIN` in
`CashbookService.php`).

### C5. Corrections and audit
- **`voidEntry()`** is the sanctioned correction: stamps and keeps the row (`:808-849`). But `updateEntry` and hard
  `deleteEntry` are open to any logged-in user with a token (`includes/post/post_cashbook.php:1237-1301`), and 27 direct
  UPDATE/DELETE statements exist elsewhere.
- **`FinAudit`** (`fin_audit`, migration 065) is append-only by convention, records update / single void / delete of
  ledger rows, **never a creation**, and silently writes nothing for an action outside create/update/void/delete
  (`lib/FinAudit.php:27`, `:57-90`).

### C6. People and permissions
Staff log in to the plugin (`RetailerAuth`, `retailers.json`), with a role and `is_admin` (`lib/RetailerAuth.php:140-215`).
Tabs are gated in `public.php` (`$_tabFiles` / `$_tabPerms`, `:2837-2855`); POST handlers check their own role. The account
screens use `accounts_dash` or admin; the *cashbook* module is granted to most roles. Forms carry a daily token
(`public.php:90-110`), but several Cashbook actions do not check it.

### C7. The existing bank import, precisely
- Reads **CSV only**, header on the first line, columns by alias (Ecobank's export), dates **month-first when ambiguous**
  (`lib/BankStatement.php:38-103`, `:206-228`). The file's currency is never read: the account's is used (`lib/BankImport.php:87-92`).
- **Proof:** the running-balance chain must hold for every row, to half a cent, or the file is refused
  (`lib/BankStatement.php:113-139`). Opening = first row's balance + debit − credit (derived).
- **Duplicate key:** `BANK-<bank reference>` or a hash of the row, looked up before insert (`lib/BankImport.php:282-315`) —
  not scoped by account, ignores status, no lock, no transaction.
- **Postings:** charges → *Bank Charges* EXPENSE; card/Starlink purchases → TRANSFER to inventory when matched to a recorded
  stock-purchase payment (exact amount, posted 0–3 days after), else to a suspense *asset* account; deposits → one answer for
  the whole file (capital / director / receipt), booked one-legged; withdrawals and unknown lines → left for a person.
- **Gaps:** dry run creates accounts; one leg of a transfer can be voided alone; the suspense "moves itself to inventory"
  promise is not implemented; no human identity recorded (`'bank import'`); reconcile() is all-time, not period-bounded.

### C8. Infrastructure the module must follow
- **Migrations:** numbered files applied on every boot by `MigrationRunner`, statement by statement, marked applied even
  when a statement failed (`lib/MigrationRunner.php:228-247`) — so **a deploy check must verify the objects themselves**.
  Next free number: **088** (081–083 and 085 are branch-only).
- **Uploads:** the model is `JobPhotos` + `?page=job_photo` — real type check, size cap, server-made name under the data
  folder, `realpath` containment, sha256, server-side access check (`lib/JobPhotos.php:118-219`, `includes/routes.php:87-116`).
  Never copy the Cashbook photo code (client extension, predictable name).
- **Readers:** `SheetReader`, `PdfReader`, `TextReader`, `OoxmlArchive`, `DocumentSniffer`, `DocumentDeadline` are
  **branch-only**, bounded by the WhatsApp classifier's limits (2,000 rows per sheet, 1 MiB of text). The live `XlsxReader`
  has no limits and is unused.
- **Uganda gate:** `StaffJobsGate::applies()` / `TenantProfile` (`lib/StaffJobsGate.php:19-35`); switches are default-off keys
  in `tools/set_config.php`.
- **Tests:** plain PHP scripts under `tests/`, throwaway SQLite folders, weakened-copy controls; 291 files, 14,482 passes on
  the branch. **Deploy:** a release cut on the live commit, a pinned `scripts/deploy-5.18.NN.sh` with stages A/B/V/R,
  a rehearsal, the rollback handed over separately.
- **An earlier plan exists, unbuilt:** `docs/UGANDA-ACCOUNTING-CURRENCY-FULL-AUDIT.md` §30–§31 (6 Sep) — per-account
  reconciliation, a `cb_reconciliations` table, differences resolved only by a documented `ADJUSTMENT`. **This design
  follows it rather than inventing a second one.**

---

## D. The Ecobank statement you supplied — format only

Read with the plugin's own PDF reader in an isolated folder; every digit and every word not on a list of banking terms
masked. **Nothing from it is stored in the repository, and it will never be a test fixture** — tests use a synthetic
statement of the same shape.

- PDF 1.4, **7 pages, text layer present, 0 undecodable characters**, not encrypted, not a scan.
- **About 73 transaction lines: 62 debits, 11 credits; every amount prefixed `UGX`; dates `dd/mm/yyyy`.**
- Columns (header repeated on each page): *Sn, Posting Date, Value Date, Type (Debit/Credit), Code, Running Balance,
  Description, Cheque Number, Remarks, Transaction Code, Loan Outstanding Amount, Total Amount, Reference, Customer
  Reference, Transaction Reference.* Amounts are unsigned; the **Type** column gives the direction.
- **No opening or closing balance line and no period line** were found: both must be derived (opening from the first
  line's running balance and amount, closing from the last) and **shown as derived**, never as stated.
- One amount carries four decimals — the parser must flag such a field for review, not round it.
- **The table is drawn column by column on page 1** (all dates, then all serials, then all amounts) and row by row
  elsewhere, so text order alone cannot say which amount belongs to which line.
- **Day-first or month-first cannot be told from the masked pattern alone**; the parser must decide from the file's own
  evidence (a day above 12) and the bank profile, and refuse when they disagree.

---

## E. Proposed design

### E1. Bank accounts — rows in `cb_accounts`, nothing parallel
Each bank account is one `cb_accounts` row, kind `bank`, its own currency: the live *Ecobank Uganda – UGX* and *– USD* if
they are the right ones, and Centenary accounts **only after you confirm they exist and in which currency** (D7). A small
metadata table holds what `cb_accounts` cannot — the bank, the statement profile, and **the last four digits** of the
account number to check an upload against (never the full number). Accounts are created through the existing
`addAccount()` — not through the Opening Balances screen until its token defect is fixed (§H).

### E2. Statement readers — one interface, separately testable
`StatementParser::parse(bytes, profile) → {lines[], statement facts, warnings[], refusals[]}`, with:
- **CSV** — the existing `BankStatement::parse()`, extended: header found below a preamble; the Ecobank *Type + Amount*
  layout as well as debit/credit columns; **an explicit date order per bank**, checked against the file; currency read
  from the amounts (`UGX …`) and compared with the account's.
- **Excel** — the branch `SheetReader` (bounded), with date serials converted per column, never guessed.
- **PDF** — a **positional mode added to `PdfReader`** (each text run with its x/y), columns found from the header's
  positions, rows rebuilt by position. Its own tests, including a column-drawn page like page 1 of your statement.
- **Every parser's result must pass the running-balance proof** (`verifyChain`) before anything is staged. Missing or
  uncertain fields (a date that does not parse, a four-decimal amount, a line without a reference) are **marked for
  review, never filled in**.

### E3. Staging — nothing posts on import
Upload screen (accountant/admin): **choose the bank account first**, then the file. Refused at once: a currency that is not
the account's, an account-number tail that does not match, a broken chain, a file already imported (same sha256). The file
is kept as the audit artefact (server-named, `uploads/bank_statements/<account>/`, sha256, accountant/admin only). Lines go
to a staging table with status **Imported / Unreviewed**. **No Cashbook entry is created by an import.**

### E4. Duplicate protection — in the module's own tables, enforced by the database
- **A statement:** unique by (account, file sha256).
- **A line:** unique by (account, bank reference) where the bank's reference is present and unique within the file; otherwise
  by (account, posting date, direction, amount, **running balance after the line**, normalised description). The running
  balance is what keeps two genuine identical payments on one day apart — their balances differ — and what makes the same
  line in an overlapping download recognisable as the same line.
- **A posting:** a link table, one row per line, unique — a line can be posted or matched once, and a ledger row can be
  claimed by one line only.
- **Lines already booked by the 11 Sep import** are recognised by their `BANK-` reference and shown as *already in the book
  (earlier import)*, never re-posted.
- The deploy script verifies each unique index exists (the migration runner can skip one silently).

### E5. Matching — suggestions with a reason and a confidence, never an automatic post
Candidates are searched in the **same account currency and direction**, by amount, date window, reference and description:
existing Cashbook rows (unassigned rows such as a typed *Bank Charges* entry, or a single-row *Bank Transfer* that records cash
banked), recorded stock-purchase payments (the existing matcher), and — if D4 says so — uCRM bank-method payments.
- One candidate, reference and amount exact → **Suggested match** (high), still confirmed by a person.
- Several candidates → **Ambiguous**; never posted or matched automatically.
- None → **Unmatched**, for classification.
- A confirmed match to an unassigned Cashbook row **moves that row into the bank account** with the audited `assignAccount()`
  — it never writes a second row (that would count the money twice).

### E6. Review, classification and posting — the screen you described
For an unmatched line the reviewer chooses:
- **Type:** expense · income/receipt · bank fee · transfer (to another of our accounts) · stock purchase · funding (capital /
  director loan) · refund · other supported type;
- **Category** from the **fixed, server-checked list** (§K D6 decides it — e.g. *Purchase* → *Local Purchase*, *Capital
  Purchase*, or *Stock purchase* as an inventory transfer; *Transport* → *Transport Allowance*, *Travel & Field* or *Vehicle*);
- party (supplier / customer / other), description, reference, transaction and value dates, supporting document, notes.
The reviewer's identity and time are recorded. **Approved for posting** needs the approval rule of D5 (a second person above
your threshold or for anything ambiguous or unusual). **Posting** writes through the existing service (`addEntryRaw` with the
bank account, `recordAccountTransfer`, `recordFunding`) inside one database transaction together with the line's status, so a
retry cannot post twice. The ledger row's description is the **reviewer's** wording — the raw bank narration, which carries
third parties' names and numbers, stays in the restricted staging table. Statuses: Imported/Unreviewed · Suggested match ·
Matched · Unmatched · Ambiguous · Duplicate/Ignored (with a reason) · Approved for posting · Posted · Reconciled.

### E7. Transfers — never income and expense
A debit on one of our accounts and a credit of the same amount on another (bank↔bank, bank↔cash) within a short window is
offered as a **transfer pair**; confirmed, it posts once through `recordAccountTransfer` (a new source added to the pair
guards so its legs can only be voided together). A difference between the two amounts is a fee: posted **separately** as
*Bank Charges*, explicitly, by the reviewer. UGX↔USD requires a rate source. A transfer whose other side is not yet on a
statement waits as *outstanding*, not as income or expense.

### E8. Reconciliation report and period sign-off
For one account and period: account and currency; statement period, opening and closing (marked *stated* or *derived*); the
book balance from `accountBalance()` at the period's end; matched and unmatched counts and totals; outstanding deposits,
withdrawals, fees and transfers; import errors and duplicate warnings; **the difference, explained line by line — never
adjusted silently.** *Reconciled* is allowed only when every line is decided and the difference is zero or explained by
listed outstanding items. Sign-off is stored in `cb_reconciliations` (the 6 Sep design). Reopening: admin only, with a reason,
audited. While a period is reconciled, its matched and posted rows are protected (D10).

### E9. Audit, permissions, security
- **An append-only event table** (database triggers, as migrations 068/071/086 do) records every act: import, match,
  classification, change, approval, posting, ignore, reconcile, reopen — who, when, before/after. FinAudit gets the matching
  `create`/`update`/`void` row too, but is not relied on alone (it can fail silently).
- **Access:** a new tab under accountant/admin only (`accounts_dash` or admin), Uganda only, behind a switch that is off by
  default. Statement files, raw lines and the report are never shown to other roles.
- **Uploads:** type from the content (not the name), size cap, server-made file names, `realpath` containment, nothing
  executed, temporary files removed, every read bounded in time and size.
- **Corrections:** posted entries are never edited or deleted by the module — only voided through `voidEntry()`, which voids a
  pair together, and the line returns to review.

### E10. What does not change
Domain B; South Sudan (the module does not exist there: Uganda gate + off switch); existing entries, balances and opening
balances; the existing writers and readers (two small, additive changes in `CashbookService` only: the new transfer source
added to the pair guards, and — if you choose D10 — the reconciled-period guard); no bank API, no live bank connection.

---

## F. Schema changes (one additive migration, 088)

| Object | Purpose |
|---|---|
| `bank_account_profiles` | per `cb_accounts.id`: bank, statement profile, account-number last four, date order |
| `bank_statements` | one upload: account, file sha256 (unique per account), stored file, parser, period, opening/closing and whether stated or derived, chain result, uploaded by/at, status |
| `bank_statement_lines` | one line: statement, account, line number, posting and value dates, description, debit/credit, currency, references, running balance, fingerprint (unique per account), status, warnings |
| `bank_line_links` | line → ledger row(s) and how (matched / posted / transfer pair), unique per line and per ledger row, with a snapshot hash of the ledger row at link time |
| `bank_line_events` | the append-only trail (triggers refuse UPDATE and DELETE) |
| `cb_reconciliations` | per account and period: book, statement, difference, explained, signed off by/at, reopened by/at and why |

No column is added to or changed on `cb_ledger` or `cb_accounts`. **No foreign key to `cb_ledger`** — foreign keys are on and
the Cashbook still hard-deletes rows (`lib/SqliteStore.php:161`, `lib/CashbookService.php:1904`); a link is checked by the
module instead, and a linked row that changes or disappears is flagged.

## G. Files

**New:** `migrations/088_bank_reconciliation.sql`; `lib/BankRecon*.php` (service, matcher, parsers, report); a tab under
`tabs/accounts/`; its POST handler under `includes/post/`; the protected file route in `includes/routes.php`; tests.
**Changed, small and additive:** `lib/BankStatement.php` (profiles, header detection, Type+Amount, date order), `lib/PdfReader.php`
(positional mode — branch), `lib/CashbookService.php` (pair-guard source list; reconciled-period guard if D10), `public.php`
(tab registration), `tools/set_config.php` (the switch), `manifest.json` and version pins, `docs/07`.
**Not touched:** Domain B, South Sudan profiles and pages, `webhook.php`, the existing writers' behaviour.

## H. Risks, and the defects found

| # | Risk or defect | How the plan handles it |
|---|---|---|
| R1 | Bank postings move "cash in hand" (C2) | D2 first, measured on the live book (P0) before any posting is possible |
| R2 | The 12 Sep adjustment and the 34 earlier rows | D3; recognised by reference, never re-posted; reconciliation from a cut-off date |
| R3 | No unique key possible on the ledger; editable reference | own tables, database-enforced; deploy verifies the indexes |
| R4 | No transactions in `CashbookService` | each posting wrapped in one transaction with its line's status |
| R5 | Customer bank receipts absent | D4 |
| R6 | Bank narrations visible to most roles once in the ledger | ledger text is the reviewer's wording; raw text stays restricted |
| R7 | PDF rows not recoverable from text order | positional reader + mandatory chain proof; CSV first |
| R8 | Excel/PDF readers branch-only | released deliberately with the module, or CSV-only first (D9) |
| R9 | Matched rows editable after reconciliation | D10 guard; change detection on links regardless |
| X1 | **Opening Balances forms post no token** — saving may fail (`tabs/accounts/opening_balances.php:111-281`) | test first; its own fix, its own approval |
| X2 | **Bank-import transfer legs can be voided singly** (`lib/CashbookService.php:818`) | add the source to the pair guards (in scope) |
| X3 | **`approveEntry()` can reinstate a voided row; callers read the wrong result key** (`:2322-2326`, `includes/api/api_cashbook.php:400`) | not used by the module; its own fix |
| X4 | **Cashbook API / CSV export show every entry to most roles** (`includes/api/api_cashbook.php:11-29`) | reported; its own fix |
| X5 | **CEO dashboard shows account positions on Uganda** despite 4 Oct (`tabs/admin/ceo_dashboard.php:108-116`) | part of D1 |
| X6 | **FinAudit drops unknown actions silently; MigrationRunner marks partial files applied** | the module's own trail; object checks in the deploy |

## I. Test strategy

Every test on throwaway databases and **synthetic statements** (no real statement data), each with a weakened copy that must
fail it:
- each bank × currency combination present; **UGX statement into a USD account refused, and the reverse**;
- CSV, Excel and PDF parsing, malformed files (no header, broken chain, a dropped row, a mistyped digit, wrong encoding,
  oversized, wrong type behind a right extension, a column-drawn PDF page, a scanned PDF refused);
- **re-importing the same statement adds nothing**; an overlapping statement adds only its new lines; **two genuine identical
  payments on one day both kept**;
- debit/credit sign handling for both layouts; opening, running and closing balance checks (stated and derived);
- matching: exact single, ambiguous, none; never automatic; matching an unassigned row moves it, never duplicates it;
- transfer pairs, fee differences, partial matches, cross-currency with and without a rate source;
- existing-entry protection: no existing row's amount, date, category or account changed except by an audited assignment;
- permissions (other roles refused every page, file and action; South Sudan sees nothing), audit rows for every act,
  reversal through `voidEntry()` returning the line to review;
- **repeated approval and posting attempts** (double click, retry, two sessions) post once;
- regression: the existing Cashbook, `test_bank_statement.php`, cash-in-hand figures, South Sudan byte-for-byte, Domain B.
The relevant suites run **twice**, exact counts reported. The sample reconciliation of your Ecobank PDF runs in a test
environment only, posts nothing, and is compared with the statement line by line.

## J. Rollout — your five steps, made concrete

| Step | What | Changes production? |
|---|---|---|
| **P0** | A **read-only measurement tool** you run on the server: which accounts exist (names, kinds, currencies), the earlier import's rows and the 12 Sep adjustment by count, and what "cash in hand" would read under each D2 option. It prints to your terminal and is not sent anywhere | **No** |
| P1 | Build on the development branch, behind an off switch, Uganda only: schema, CSV parser extension, staging, duplicate protection, matching, review, posting, report | No (development only) |
| P2 | Test environment: your Ecobank PDF (positional reader) and an Ecobank CSV of the same period, extracted and compared line by line — **nothing posted** | No |
| P3 | Release cut on the live commit, pinned deploy script, rehearsal, your approval; **then** the pilot: one completed month of Ecobank UGX, every unmatched line decided, opening and closing verified | Yes, only after approval |
| P4 | Centenary UGX once confirmed, then the USD accounts | Yes, after approval |

Each production step is its own deploy, with its own log, its own rollback handed over separately, and the quarantine's
`--check` after any upload. **No real statement transaction is posted before your explicit go.**

## K. Decisions needed before coding (recommendations first)

| | Decision | Recommendation |
|---|---|---|
| **D1** | Reverse "cash in hand only" for bank accounts? | **Yes, but bank balances shown only inside the new Bank Reconciliation screen** (accountant/admin). The Cashbook hero, dashboards and WhatsApp summary stay cash only; the CEO dashboard's position cards follow the same rule |
| **D2** | What "cash in hand" counts | **Physical cash only: rows on cash (and mobile-money?) accounts plus unassigned rows**; bank and non-money accounts excluded. Measured by P0 first; shipped as its own step with the before and after figures |
| **D3** | The 34 earlier rows and the 12 Sep adjustment | **Keep the rows; reconcile Ecobank UGX from a cut-off date after them; void the adjustment in the same step as D2** (it would have nothing left to cancel) — only if P0 shows the figure unchanged |
| **D4** | Customer bank receipts | **Post a bank-account receipt linked to its uCRM payment** (deduplicated on the payment id), behind its own switch — otherwise the bank account can never reconcile. This changes the "Cashbook = cash only" rule and adds bank receipts to the Cashbook P&L |
| **D5** | Who does what; thresholds | Upload and classify: accountant or admin. Post: an approval; **a second, different person above an amount you set (UGX and USD) and for anything ambiguous or unusual.** Sign-off and reopen: admin |
| **D6** | The category list for classification | The existing `CATEGORIES_OUT` / `CATEGORIES_IN`, server-checked. **You choose the mapping for "purchase" (Local Purchase / Capital Purchase / Stock purchase → inventory) and "transport" (Transport Allowance / Travel & Field / Vehicle)** |
| **D7** | Which bank accounts exist | You confirm each bank, currency and the account-number last four; Centenary only if it exists |
| **D8** | Formats per bank | **Ecobank CSV first** (supported today), Ecobank PDF second (positional reader), Excel when a sample exists; Centenary after a redacted sample |
| **D9** | The branch-only readers | **First release CSV-only on the live base; PDF/Excel in a second release** that takes the readers deliberately |
| **D10** | Lock reconciled periods | **Yes:** refuse edit, delete or void of a row linked to a reconciled period until the period is reopened (audited) |
| **D11** | Keeping statement files | Kept, accountant/admin only, no automatic deletion; you set a retention period |
| **D12** | Project for bank rows | `dishnet`, as today's writers do |

## L. What only you can tell me

1. Do the **Centenary** UGX and/or USD accounts exist, and are the live *Ecobank Uganda – UGX / – USD* accounts the ones to
   reconcile? From which date each, and with which opening balance (from the bank)?
2. **Can you download an Ecobank CSV or Excel** for the same period as the PDF? It lets P2 compare the PDF reading against it.
3. When staff bank collected cash, is it entered as a *Bank Transfer* on the Cashbook today?
4. Are customer bank payments recorded in uCRM under the Ecobank methods? Are DPO card payments in use, and paid into which
   account?
5. When you last added an account or recorded a transfer on **Accounts → Opening Balances**, did it save, or say *Security
   token mismatch*?
6. Your amounts for D5, your mapping for D6, and your retention period for D11.

---

*Discovery only. No file outside `docs/` changed; no migration, no production data, no deployment. The supplied statement
stays outside the repository.*
