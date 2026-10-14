# 68 — Cashbook bank statement import and reconciliation: discovery and plan

**Date:** 13 October 2026 · **Status: DISCOVERY ONLY.** Nothing was built, migrated, changed or deployed. No production
data was read: this is a read of the code (live `f4b4c96` = 5.18.94, and the development branch) and of the supplied
Ecobank PDF, opened in an isolated folder and read with every digit and name masked. **Coding waits for your approval of
the decisions in §K and of the exceptions in §M.**

**Update, 13 October (later): P0 is built, rehearsed and reviewed (§O), and approved to run under six gates. The gated
command is in §P; it has NOT been run yet.** Nothing else in this plan has been started.

**Method.** Four read-only reviewers mapped the account model, posting and controls, the existing bank import and the
infrastructure; a fifth checked their claims against the code. The first draft of this plan was then attacked by three more
reviewers (accounting, security, accuracy): 58 findings, all taken in below — among them two errors of mine about the 12 Sep
adjustment and about matching cash banked, now corrected. Everything was **read from the code, not run**. Every fact
carries a `file:line`; paths are under `dishnet-hybrid-sudan/` unless they start with `docs/` (the repository root's).

---

## A. What you asked for, in one paragraph

Separate bank accounts (Ecobank UGX/USD, Centenary UGX/USD, once confirmed), a statement upload (CSV, Excel, PDF) into a
review area, protection against duplicate imports and postings, matching to existing Cashbook entries, a reviewer who
classifies each unmatched line — for example *purchase*, *expense*, *transport* — and approves its posting, transfers that
never become income or expense, a reconciliation report with period sign-off, and a full audit trail. The existing
Cashbook stays the only ledger.

## B. The short answer — what the design has to start from

1. **A first version is already live, and must be stopped from posting.** `lib/BankStatement.php`, `lib/BankImport.php`
   and `tools/bank_statement.php` (commits of 11 Sep: `c2f0a34f`, `d2ed193b`, `1b745f99`) read a CSV, refuse a file whose
   running balance does not add up, and with `--commit` **post a whole file automatically** — bank charges and supplier
   payments, approved, with no review (`lib/BankImport.php:116-157`). The new module reuses its parser and proof, and
   **the old `--commit` is refused on Uganda and whenever the module is switched on** (P1), so the two can never both post.
2. **It conflicts with your 4 October decision.** For Uganda you chose *"cash in hand only"* — no bank or working-capital
   figures in the plugin — and the record says `tools/bank_statement.php` *"should not be run again on Uganda"*; *"the 12
   Sep adjustment and the 34 bank-import rows stay as they are"* (`docs/07-change-control.md:3580-3583`, `:3778`).
   **A bank module reverses part of that: D1.**
3. **"Cash in hand" today counts every row of a currency, bank rows included.** `cashInHand()` and the Cashbook's Balance
   column sum every non-voided row of the currency, whatever its account, pending and rejected rows included
   (`lib/CashbookService.php:671-686`, `:1950-2031`). They feed the Uganda Cashbook hero, the accounts dashboard, the
   evening WhatsApp summary and the CSV export. **Every bank charge or deposit posted from a statement would change the
   "UGX CASH IN HAND" you read as physical cash. Nothing may be posted before D2 is decided and shipped.**
4. **What the 12 Sep adjustment really cancels.** The record: *"The ledger's running balance … is right* because *the
   adjustment cancels the account rows; cash rows alone, adjustment excluded, give exactly [the same figure] as well"*
   (`docs/07-change-control.md:3540-3541`). The 34 card purchases of the earlier import are **transfer pairs** (bank →
   inventory or suspense, `lib/BankImport.php:139-152`), which cancel each other in that sum. So, by the record's own
   figures, the adjustment offsets the **account rows — chiefly the *Cash – Uganda* account — plus the import's one-legged
   bank charges**, not the 34 purchases. **My first draft got this wrong; voiding the adjustment is no longer recommended**:
   it would raise cash in hand by about the *Cash – Uganda* balance. What the right definition is depends on figures only the
   live book holds — **P0 measures them (D2, D3).**
5. **Customer bank payments are not in the Cashbook.** Only cash uCRM payments are posted (`webhook.php:1187-1194`,
   `lib/CashbookService.php:2423-2427`, `:2478-2482`). So credits from customers will usually have no Cashbook entry to
   match (an inference: the statement's narrations were masked). **D4.**
6. **The ledger cannot hold a unique key, and its only key is editable.** Every `new CashbookService` drops every UNIQUE
   index on `cb_ledger` (`:1077-1090`); `validation_ref` can be changed by any logged-in user (`:1849-1862`) and is
   overwritten by *settle* (`:1918`, no token checked). **Duplicate protection must live in the module's own tables.**
7. **No maker-checker, no UGX threshold, no period lock exist today.** Almost every writer posts `approved` directly;
   `approveEntry()` has no state check and can reinstate a voided row (`:2322-2326`); the large-amount guard covers USD
   and SSP only (`lib/TransactionIntegrityGuard.php:211-262`); nothing locks a period. And **any logged-in user can edit or
   hard-delete any Cashbook row** (`includes/post/post_cashbook.php:1237-1301`). **The module brings its own approval, lock
   and protection of its postings (D5, D10).**
8. **Your Ecobank PDF can be read, but not with the reader as it is.** It has a full text layer, but its table is drawn
   column by column on at least one page, and the plugin's PDF reader returns text without positions
   (`lib/PdfReader.php:690-718`), so rows cannot be rebuilt reliably. A positional mode is needed (§E2). **Whether a
   current Ecobank CSV parses is unknown**: the CSV path worked on the September file, but its parser needs the header on
   line 1 and debit/credit columns (`lib/BankStatement.php:57-79`), and no current export has been seen (L2).
9. **Centenary appears nowhere in the repository**; the seeded chart has Ecobank UGX and USD only
   (`lib/CashbookService.php:501-524`). **D7.**
10. **Defects found on the way** (§H): the Opening Balances screen posts no security token *and* can be forged; Cashbook
    rows reach almost every role through the API, the CSV export and the daily e-mail; the CEO dashboard shows account
    positions on Uganda; bank-import transfer legs can be voided singly. **Two of them must be fixed before any bank line is
    posted in production (X1, X4).**

---

## C. How the Cashbook works today

### C1. Accounts and currency
- **One ledger, `cb_ledger`,** single-entry: a positive `amount` and a `direction` in/out (`lib/CashbookService.php:1012-1091`).
  Each row may carry an `account_id` into **`cb_accounts`** (id, name, currency, kind, active), both created in PHP at
  runtime, not by a migration (`:131-142`); names unique in PHP only; no foreign key (`:158-182`).
- **Kinds:** bank, cash, momo, receivable, payable, inventory, asset, director, equity (`:120-125`). Only bank, cash and momo
  are *money* (`MONEY_KINDS`, `:552`).
- **One currency per account, enforced** by `addEntryRaw()` and `assignAccount()` (`:1792-1806`, `:215-221`); transfers,
  funding and openings take each leg's currency from its account. A row with `account_id = 0` can be any currency — **that is
  every manual, CRM, webhook and expense row today**: no entry screen sets an account (`:1742-1788`).
- **Base currency:** `cashbook_base_currency` via `dn_book_base()` (`lib/currency.php:108-133`) — UGX on Uganda (UGX, USD),
  USD on South Sudan (USD, SSP). `dn_entry_currency()` falls back to the base for a code not in the list
  (`lib/currency.php:155-163`) and `addEntry()` coerces a malformed code (`lib/CashbookService.php:1747-1748`) — **imported
  lines must refuse, never coerce.**

### C2. Balances — two arithmetics
- **Approved-only figures:** `accountBalance()` (all-time, per account, **no date argument**, `:254-261`), `balancesByAccount`,
  `currencyPositions` (`:580-661`) and `plByPeriod` (per currency, `:739-799`). `accountLedger()`'s *balance* field is
  `accountBalance()`, but its running balance counts pending and rejected rows too (`:704-710`).
- **Stream figures:** `cashInHand()` and the `getEntries()` running balance (the Cashbook's Balance column and its CSV export)
  count every non-voided row of a currency whatever the account (`:671-686`, `:1950-2031`; `includes/routes.php:270`).
  Read from the code: a funding's two "in" legs both count; a director or payable opening counts as cash; a cash↔bank transfer
  nets to zero. `getEntries()` cannot filter by account (`:2040-2060`).
- **Legacy readers hard-wired to USD** (`getBothBalances`, `getBalance`, `getLedger`, `getSummary`, `:2062-2200`) — never used.

### C3. Entries, categories, parties, documents
- **More than twenty writers** post to `cb_ledger`, nearly all `approved` directly.
- **Categories are free text.** Vocabulary: `CATEGORIES_IN` / `CATEGORIES_OUT` (`:21-41`) and the editable tiles
  `cb_categories.json` (`includes/api/api_cashbook.php:580-745`); nothing validates a category on write (`:1753`). **There is
  no plain "Purchase" or "Transport"**: there are *Local Purchase*, *Capital Purchase*, *Transport Allowance*, *Travel & Field*,
  *Vehicle*, *Bank Charges*, *Airtime*, *Bandwidth*, *Site Expense*, *Tax*, *Govt Fees*, *Misc Expense*… The lists also hold
  **capital and flow categories** (*Bank Transfer*, *Exchange*, *Loan Received/Given*, *Interco*, *Staff Advance*,
  *Opening Balance*, `:540-545`).
- **P&L:** a typed row by `txn_type` alone, an untyped row by category (`:554-559`). `TRANSFER`, `DIRECTOR_FUNDING`,
  `OPENING_BALANCE`, `ADJUSTMENT` stay out (`:547`). *Capital Purchase* is **not** a capital category — it counts as an
  expense. Every `REFUND` row is contra-revenue (`:764-772`). **Nothing writes `txn_type ADJUSTMENT`** (`:296-299`).
- **Staff categories have side effects outside the service:** a cash out in *Staff Advance*, *Transport Allowance*,
  *Commission*… credits the staff member's cash bag only in the web handler (`includes/post/post_cashbook.php:397-500`).
- **Parties:** free text (`person`) plus `crm_client_id`; suppliers exist only on `stock_purchases` (`migrations/036`).
- **Documents:** `cb_ledger` has no file column; manual-entry photos are dropped (`includes/post/post_cashbook.php:391-395`).

### C4. Transfers
`recordAccountTransfer(from, to, amountFrom, amountTo, date, desc, rateSource, admin, ref, source)` writes an out leg and an in
leg, both `TRANSFER`, category *Bank Transfer*, one shared reference; same currency must be equal, cross-currency needs a rate
source and records `fx_*` on the receiving leg (`:329-395`). **No fee leg; no FX gain or loss line.** Pair protection covers
only sources `account_transfer`, `funding`, `fx_exchange` (`:818`, `:1854`, `:1894`). **No transaction** anywhere in
`CashbookService.php`.

### C5. Corrections and audit
- **`voidEntry()`** stamps and keeps the row (`:808-849`); a pair void writes no audit row. `updateEntry` and hard
  `deleteEntry` are open to any logged-in user with a token (`includes/post/post_cashbook.php:1237-1301`); 27 more direct
  UPDATE/DELETE statements exist.
- **`FinAudit`** (`fin_audit`, migration 065) is append-only by convention; for ledger rows it records update, single void and
  delete, **never a creation**; an action outside create/update/void/delete writes no row and tells the caller nothing (an
  `error_log` line only, `lib/FinAudit.php:27`, `:57-90`).

### C6. People, permissions and CSRF
- Staff log in to the plugin (`RetailerAuth`), with a role and `is_admin` (`lib/RetailerAuth.php:140-215`); the record is
  cached for 300 s (`:131-176`). Tabs are gated in `public.php` (`$_tabFiles` / `$_tabPerms`, `:2837-2855`); **a tab with no
  `$_tabPerms` entry is open to every signed-in user** (`:2969-2971`). `accounts_dash` can be granted to any role and also gates
  HR and payroll (`:2869-2872`); the API evaluates permissions differently (`includes/api_handlers.php:103-107`); POST handlers
  run before `$can` exists (`public.php:739`, `:978` vs `:2363`).
- **The form token is weak:** an HMAC of the date alone, the same for every user (`public.php:90-96`), valid for two days,
  printed on the anonymous login page (`:2075`); the session cookie is `SameSite=None` (`:57-63`); the global gate is skipped by
  any POST carrying `cb_action` (`includes/post_handlers.php:100-115`). A cross-site check exists on live:
  `CustomerSession::crossSite()` (`lib/CustomerSession.php:99`). The JSON API's guard `StaffApiCsrf` is branch-only.

### C7. The existing bank import, precisely
- **CSV only**, header on line 1, columns by alias (built for Ecobank's export), **ambiguous dates read month-first** because
  the September Ecobank file was (`lib/BankStatement.php:38-103`, `:206-228`). The file's currency is never read; the account's
  is used (`lib/BankImport.php:87-92`).
- **Proof:** the running-balance chain must hold for every row (`lib/BankStatement.php:113-139`). Opening and closing are
  derived from the first and last rows — **so a file missing its first or last lines still passes.**
- **Key:** `BANK-<reference>` (the first matching column in file order) or a hash of the CSV-parsed row
  (`lib/BankImport.php:282-315`) — editable, not scoped by account, no lock, no transaction.
- **Postings:** charges → *Bank Charges* EXPENSE; card/Starlink purchases → TRANSFER to inventory when matched to a recorded
  stock-purchase payment, else to a suspense *asset* account; deposits → one answer per file, one-legged; withdrawals and unknown
  lines → left. **Nothing ever moves an inventory or suspense balance into the P&L** (`lib/BankImport.php:17-20` promises it;
  no code does).

### C8. Infrastructure
- **Migrations:** applied on every boot by `MigrationRunner`, statement by statement, **marked applied even when a statement
  failed** (`lib/MigrationRunner.php:228-247`) — a deploy check must verify every object. Next free number: **088**.
- **Uploads:** `JobPhotos` + `?page=job_photo` is the nearest model (server-made names, `realpath`, sha256, server-side check:
  `lib/JobPhotos.php:118-219`, `includes/routes.php:87-116`), but its type safety comes from re-encoding images, and it serves
  inline. Statements need their own checks (§E9). **Google Drive backups zip the whole `uploads` folder and the database**
  (`lib/GoogleDriveBackup.php:574`, `:720-721`).
- **Readers:** `SheetReader`, `PdfReader`, `TextReader`, `OoxmlArchive`, `DocumentSniffer`, `DocumentDeadline` are
  **branch-only** and **truncate rather than refuse** at the WhatsApp limits (`lib/MediaPolicy.php:44-57`).
- **Uganda gate:** `StaffJobsGate::applies()` / `TenantProfile` (`lib/StaffJobsGate.php:19-35`); switches are default-off keys in
  `tools/set_config.php`. **Tests:** plain PHP under `tests/`, throwaway folders, weakened copies; 291 files, 14,482 passes on the
  branch. **Deploy:** a release cut on the live commit, a pinned script, a rehearsal, the rollback separate.
- **An earlier plan exists, unbuilt:** `dishnet-hybrid-sudan/docs/UGANDA-ACCOUNTING-CURRENCY-FULL-AUDIT.md` §30–§31 (6 Sep) —
  per-account reconciliation, a `cb_reconciliations` table, differences resolved only by a documented `ADJUSTMENT`. This plan
  follows it.

---

## D. The Ecobank statement you supplied — format only

Read with the plugin's own PDF reader in an isolated folder; every digit and every word not on a list of banking terms masked.
**Nothing from it is stored in the repository, and it will never be a test fixture.**

- PDF 1.4, **7 pages, text layer present, 0 undecodable characters**, not encrypted, not a scan.
- **About 73 transaction lines: 62 debits, 11 credits; every amount prefixed `UGX`; dates written `nn/nn/yyyy`.** Whether they
  are day-first or month-first cannot be told from the masked pattern; the code says Ecobank's September CSV was month-first
  (`lib/BankStatement.php:209-213`). The parser decides from the file's own evidence (a number above 12) and the bank profile,
  and **refuses when they disagree or the file cannot settle it**.
- Columns (header on each page): *Sn, Posting Date, Value Date, Type (Debit/Credit), Code, Running Balance, Description, Cheque
  Number, Remarks, Transaction Code, Loan Outstanding Amount, Total Amount, Reference, Customer Reference, Transaction
  Reference.* Amounts are unsigned; **Type** gives the direction. **Sn** numbers the lines — a completeness check.
- **No opening balance, closing balance or period line** was found: opening and closing are derived and **shown as derived**.
- One amount carries four decimals — flagged for review, never rounded.
- **The table is drawn column by column on page 1** and row by row elsewhere.

---

## E. Proposed design

### E1. Bank accounts — rows in `cb_accounts`, nothing parallel
Each bank account is one `cb_accounts` row, kind `bank`, its own currency — the live *Ecobank Uganda – UGX* and *– USD* if they
are the right ones, Centenary **only once you confirm it exists and in which currency** (D7). A small profile table holds what
`cb_accounts` cannot: the bank, the statement profile, the date order, and **the last four digits** of the account number to check
an upload against (never the full number). Accounts are created through `addAccount()`, **not through the Opening Balances screen
until X1 is fixed**. A USD capital or director deposit needs USD counterpart accounts (`recordFunding` requires the same currency,
`lib/CashbookService.php:461-469`); D7 confirms them.

### E2. Statement readers — one interface, separately testable
`StatementParser::parse(bytes, profile) → {lines[], facts, warnings[], refusals[]}`:
- **CSV** — `BankStatement::parse()` extended: header found below a preamble; the *Type + Amount* layout as well as debit/credit;
  **an explicit date order per bank**, checked against the file; currency read from the amounts and compared with the account's.
- **Excel** — the branch `SheetReader`, with date serials converted per column, never guessed.
- **PDF** — **a positional mode added to `PdfReader`** (each text run with x/y), columns located from the header, rows rebuilt by
  position. Its own caps (bytes, pages, retained runs), deadline checks inside the pass, a memory-budget test.
- **Refusals before anything is staged:** any reader truncation (page, row, cell or text cap) — a statement is never read in part;
  a broken running-balance chain; **a gap in Sn, or pages read ≠ pages in the file**; a derived opening that does not equal the
  account's last signed-off closing (or the cut-off figure, §E8). Uncertain fields are **marked, never filled in**.
- Parsing runs fully **before** any database transaction opens, so a slow file never holds the database's writer lock.

### E3. Staging — nothing posts on import
Upload (permission *bank.import*, §E9): **choose the bank account first**, then the file. Refused at once: a currency that is not
the account's, an account-number tail that does not match, a refusal of §E2, a file already imported (sha256). The file is kept as
the audit artefact (§E9). Lines go to staging as **Imported / Unreviewed**. **No Cashbook entry is created by an import.** Lines
dated on or before the cut-off date (§E8), or inside the earlier import's date range, are **refused for posting** until linked or
cleared (§E4).

### E4. Duplicate protection — the module's own tables, enforced by the database
- **A statement:** unique by (account, sha256).
- **A line:** unique by (account, bank reference) where the reference is present and unique in the file; otherwise by (account,
  posting date, direction, amount, **running balance after the line**, normalised description). The running balance keeps two genuine
  identical payments on one day apart (their balances differ) and recognises the same line in an overlapping download.
- **A link:** many-to-many is allowed (one debit may pay several purchases; one credit may cover several receipts plus a fee), but
  **(line, ledger row) is unique among active links**, the parts must sum to the line, and a ledger row can never be claimed beyond its
  amount. Links are never deleted: an undo marks them superseded.
- **The earlier import's rows** are recorded once — by id and content hash — in the module's own table at P1, and recognised by that
  record, **not by their editable reference**; a recorded row that later changes is flagged. Lines in their date range are refused
  until linked (§E3).
- **Immutable once written** (database triggers, as `migrations/071` and `086` do): a line's imported fields, a statement's sha256 and
  file. The deploy checks every index and every trigger by name.

### E5. Matching — tiers with a stored reason, never automatic
Candidates: **approved rows only**, in the account's currency — unassigned rows and rows on **every money account** (a deposit
already recorded on *Cash – Uganda* must be found, not posted again), recorded stock-purchase payments (the existing matcher), and —
under D4 — uCRM bank-method payments. Tiers, each with a fixed reason text, stored with the line:
1. exact reference and amount; 2. exact amount within the date window, one candidate; 3. exact amount, several candidates →
   **Ambiguous**; 4. amount within a stated tolerance or parts summing to the line → **Partially matched**; none → **Unmatched**.
Every tier is a **suggestion a person confirms**; ambiguous lines are never matched or posted automatically, and there is **no
"confirm all" or "post all"**.

Confirming a match does one of two different things:
- **A row that records the bank-side movement itself** (a card payment typed by hand, a hand-typed bank charge, a bank-method
  receipt): moved into the bank account with the audited `assignAccount()`.
- **A row that records the cash side of a bank↔cash movement** (a single-row *Bank Transfer* out recording cash banked, or *in*
  recording cash drawn): **left where it is** (optionally assigned to the cash account), and the **missing bank leg alone** is written
  — `TRANSFER`, opposite direction, on the bank account, shared reference, a pair source of its own. Searched in the **opposite**
  direction to the statement line. **Only after D2 ships** (under today's arithmetic the new leg would cancel the cash row).

### E6. Review, classification and posting — the screen you described
The reviewer chooses a **type**, and the type decides the writer and the `txn_type`; the **category** list shown depends on the type:

| Type | Writer | `txn_type` | Categories offered |
|---|---|---|---|
| Expense | `addEntryRaw` on the bank account, out | `EXPENSE` | P&L categories only (the lists minus capital ones and *Opening Balance*) |
| Bank fee | same | `EXPENSE` | *Bank Charges* |
| Income / receipt | in | `PAYMENT` | P&L categories; **a customer receipt must pick or decline a uCRM candidate (D4)** |
| Customer refund | out | `REFUND` | *Customer Refund* |
| Refund of a purchase or a fee | linked to the original, **mirrors its treatment**: a reverse transfer for an inventory/suspense purchase, an *in* row in the original category for an expense | — | — |
| Transfer (our own accounts) | `recordAccountTransfer`, new source in the pair guards | `TRANSFER` | — |
| Stock purchase | per **D6** — expense, or inventory **only once an inventory → expense writer exists** | — | — |
| Funding (capital / director loan) | `recordFunding` (two legs), after an **existing-capital check** that must be linked or declined | `DIRECTOR_FUNDING` | — |
| Staff items (*Staff Advance*, *Transport Allowance*, *Commission*…) | per **D6b**: refused in v1, or through the staff-ledger hook | — | — |

Then: party, the reviewer's own description (the raw narration stays in restricted staging), reference, notes. **Posting date =
the bank's posting date**; transaction and value dates stay in staging. *Supporting documents* are **left out of v1** (no safe store
exists for them today; the expense-receipt store is served to other roles, `includes/routes.php:58-75`).
**Approved for posting** follows D5. **Posting** writes through the service inside one database transaction together with the
line's status, so a retry or a second session cannot post twice; any change after approval returns the line to unapproved.
Statuses: Imported/Unreviewed · Suggested match · Partially matched · Matched · Unmatched · Ambiguous · Duplicate/Ignored (with a
reason) · Approved for posting · Posted · Reconciled.

### E7. Transfers — never income and expense
A debit on one of our accounts and a credit on another (bank↔bank, bank↔cash) are offered as a pair. **Same currency:** amounts
equal, or within a stated fee tolerance, the difference posted **separately** as *Bank Charges* on the account whose statement shows
it. **UGX↔USD:** paired by date and by the reviewer, each amount as its own statement shows it, with a rate source; **the bank's
spread is not recorded as a P&L item** (the book has no FX gain/loss line). **A transfer is posted, both legs, when the first
statement shows it**; the other account then carries it as a deposit in transit until its own statement arrives.

### E8. Reconciliation report and period sign-off
- **Book side:** a new **date-bounded, approved-only** reader — the account's approved rows dated ≤ the period end (and ≤ the day
  before it starts). `accountBalance()` has no date and is not used for this.
- **The arithmetic, stated:** statement closing − book closing = the signed sum of the account's book rows dated ≤ the end that have
  **no link** to a statement line ≤ the end (deposits in transit, unpresented payments, the far leg of a transfer). **Every
  statement line ≤ the end must be posted, matched, or ignored as a duplicate of an already-linked line** before sign-off; fees are
  never "outstanding".
- **The first period (cut-off):** the bank account's book balance at the cut-off is not the bank's (the earlier import booked
  purchases and charges but no deposits). Two ways, **D3**: (a) one typed **`ADJUSTMENT`** row on the bank account, dated the
  cut-off, equal to the bank's balance then minus the book's, with its own approval and audit — out of the P&L (`:547`) and out of
  cash in hand — a **new writer**; or (b) take the earlier period's lines through review.
- **Continuity and completeness:** each statement's derived opening must equal the previous signed-off closing; where a statement
  states no closing balance, sign-off needs a **period-end balance from an independent source** (a bank confirmation or the online
  balance, entered and recorded).
- **Report:** account and currency; period; opening and closing (*stated* or *derived*); book closing; matched/unmatched counts and
  totals; the outstanding book rows; import errors and duplicate warnings; **the difference, explained line by line — never adjusted
  silently.**
- **Sign-off** (stored in `cb_reconciliations`, the 6 Sep design) only when every check passes. **Reopen:** admin, with a reason,
  audited. **While a period is signed off, nothing may be posted or assigned into the account dated on or before its end** (D10).

### E9. Audit, permissions, security
- **Trail:** an append-only event table (triggers refuse UPDATE and DELETE) records every act — import, match and its tier, reason
  and confidence, classification, change, approval, posting, ignore, un-match, un-post, sign-off, reopen — with the retailer **id**,
  time, before/after. FinAudit gets its `create`/`update`/`void` row too, but is not relied on alone.
- **Permissions:** dedicated keys — *bank.view*, *bank.import*, *bank.approve*, *bank.signoff* — evaluated by **one function that
  re-reads the user from the store by id** (never the 300-second session copy, never a fallback), called from the tab, the POST
  handler and the file route. Both `$_tabFiles` and `$_tabPerms` entries; the tab also guards itself. `accounts_dash` is not reused.
  Uganda only, behind a switch off by default — checked on every entry point, not only the tab.
- **Segregation (D5):** identities are retailer ids. **The approver is never the uploader**; above your threshold, never the
  classifier either; **admins are not exempt**. The threshold applies to the **statement line's** amount, not to each part. Sign-off by
  someone who approved nothing in that period, or the exception recorded.
- **CSRF:** every module action is POST-only and its own handler refuses unless `csrfCheck()` is true **and**
  `CustomerSession::crossSite()` is false — never relying on the global gate (bypassed by `cb_action`). **No JSON write endpoints in
  the first release** (the API's guard is branch-only).
- **Postings stay put:** from the first release, every module posting source is refused by `updateEntry`, `deleteEntry` and *settle*
  for **all** fields, not only money fields; corrections go through the module only. **Un-match** restores the row's previous account
  from the link's snapshot (a small new service method; `assignAccount()` cannot return a row to "unassigned"); **un-post** voids only
  rows the module itself wrote. Either returns the line to review.
- **Uploads:** a byte cap; PDF — `%PDF` header, not encrypted; XLSX — the archive member allow-list; CSV — UTF-8 (BOM allowed), no NUL
  bytes, the expected header within the first lines. Stored as `uploads/bank_statements/<cb_accounts.id>/<server name>`, folders 0700,
  files 0600, sha256 on the row; served by id from the row, **as an attachment** with `nosniff`, to *bank.view* only. **Backups:** the
  Drive backup copies `uploads` and the database — D11 decides whether statements are excluded or encrypted there, and the retention
  applies to backups too.
- **Untrusted text:** narrations, references and file names are escaped on every render; a leading `=`, `+`, `-` or `@` is neutralised in
  any CSV or Excel export.
- **Visibility — a precondition (X4):** no statement line is posted in production until the Cashbook API, its CSV export and the daily
  e-mail report exclude bank-account rows and balances, or limit them to *bank.view* (`includes/api/api_cashbook.php:339-353`,
  `lib/CashbookService.php:2040-2059`, `includes/routes.php:223-258`, `lib/DailyReportService.php:104-134`). The ledger row carries the
  reviewer's description and a generic party and reference.

### E10. What does not change — and what does
Unchanged: Domain B; South Sudan (Uganda gate + off switch); the existing writers' and readers' behaviour for rows the module does not
touch; no bank API, no live bank connection. **What the plan does change is listed in §M — each item needs your separate approval.**

---

## F. Schema changes (one additive migration, 088)

| Object | Purpose |
|---|---|
| `bank_account_profiles` | per `cb_accounts.id`: bank, statement profile, date order, account-number last four |
| `bank_statements` | one upload: account, sha256 (unique per account), stored file, parser, period, opening/closing stated or derived, page count, chain and completeness results, uploaded by/at, status |
| `bank_statement_lines` | one line: statement, account, Sn, posting/value dates, description, debit/credit, currency, references, running balance, fingerprint (unique per account), status, warnings, match tier/reason/confidence — imported fields fixed by trigger |
| `bank_line_links` | line ↔ ledger row, the amount claimed, how (matched / posted / transfer leg / receipt of a uCRM payment id), a snapshot of the row at link time, active or superseded; unique (line, row) among active links; unique uCRM payment id among active links |
| `bank_known_rows` | the earlier import's rows (id + content hash), recorded once |
| `bank_line_events` | the append-only trail |
| `cb_reconciliations` | per account and period: book and statement closings, independent balance if any, difference, signed off by/at, reopened by/at and why |

No column is added to or changed on `cb_ledger` or `cb_accounts`. **No foreign key to `cb_ledger`** (foreign keys are on and the Cashbook
still hard-deletes rows, `lib/SqliteStore.php:161`, `lib/CashbookService.php:1904`); the module checks its links and flags a linked row that
changed or vanished.

## G. Files

**New:** `migrations/088_bank_reconciliation.sql`; `lib/BankRecon*.php` (service, matcher, parsers, report, permission check); a tab under
`tabs/accounts/`; its POST handler under `includes/post/`; the file route in `includes/routes.php`; tests; the P0 tool
`tools/bank_recon_census.php` (read-only); for D4, a read-only fetch of uCRM's bank-method payments, as
`tabs/accounts/collection_reconcile.php:82-100` already does.
**Changed:** `lib/BankStatement.php` (profiles, header detection, Type+Amount, date order); `lib/BankImport.php` and `tools/bank_statement.php`
(`--commit` refused on Uganda and when the module is on; the matcher made reusable); `lib/PdfReader.php` (positional mode — branch);
`lib/CashbookService.php` (pair-guard sources; module sources refused by update/delete/settle; a date-bounded approved-only reader; the
un-match restore; the cut-off `ADJUSTMENT` writer if D3a; the closed-period guard if D10; `cashInHand`/`getEntries` if D2);
`public.php` (`$_tabFiles` and `$_tabPerms`); `includes/api/api_cashbook.php`, `includes/routes.php` (CSV export), `lib/DailyReportService.php`
(X4); `tabs/accounts/opening_balances.php` (X1); `tabs/admin/ceo_dashboard.php` (D1); `tools/set_config.php`; `manifest.json` and pins; `docs/07`.
**Not touched:** Domain B; South Sudan profiles and pages; `webhook.php` and the CRM sync (D4 works from statement lines, not from them).

## H. Risks and defects found

| # | Risk or defect | Handling |
|---|---|---|
| R1 | Bank postings move "cash in hand" and the Balance column (C2) | D2 decided and shipped first, measured by P0; hero = last Balance, tested |
| R2 | The 12 Sep adjustment and the earlier import | D3; recorded rows; cut-off; adjustment **not** voided unless P0 shows it safe |
| R3 | No unique key possible on the ledger; editable key | own tables, database-enforced, triggers; deploy checks every object |
| R4 | No transactions in `CashbookService` | each posting in one transaction with its line's status |
| R5 | Customer bank receipts absent | D4, one writer, from statement lines only |
| R6 | Bank data visible to most roles once in the ledger | X4 a precondition; reviewer's wording only |
| R7 | PDF rows not recoverable from text order; readers truncate | positional reader; truncation is a refusal; completeness checks |
| R8 | Excel/PDF readers branch-only | D9 |
| R9 | Matched or posted rows changed later | module sources protected from day one; D10; link change detection |
| R10 | Inventory/suspense never reaches the P&L | D6 |
| X1 | **Opening Balances: forms post no token (saving may fail), the handler ignores its own check, and a POST carrying `cb_action` reaches it with no token** (`tabs/accounts/opening_balances.php:26-27`, `:111-281`; `includes/post_handlers.php:109-110`) | fix: print the token, refuse on a failed check, refuse cross-site — before any account is created there |
| X2 | Bank-import transfer legs voidable singly (`lib/CashbookService.php:818`) | pair guards (in scope) |
| X3 | `approveEntry()` can reinstate a voided row; callers read the wrong result key (`:2322-2326`; `includes/api/api_cashbook.php:410-411`) | not used by the module; its own fix |
| X4 | **Every Cashbook row, with per-account balances, reaches almost every role** (API `includes/api/api_cashbook.php:339-353` + `lib/CashbookService.php:2040-2059`; CSV export `includes/routes.php:223-258`; daily e-mail `lib/DailyReportService.php:104-134`) | fix before P3 (precondition) |
| X5 | CEO dashboard shows account positions on Uganda (`tabs/admin/ceo_dashboard.php:108-116`) | part of D1 |
| X6 | FinAudit writes nothing for unknown actions; MigrationRunner marks partial files applied | the module's own trail; object checks in the deploy |
| X7 | The CLI's `--commit` posts a whole file automatically | refused on Uganda / when the module is on (P1) |

## I. Test strategy

Throwaway databases and **synthetic statements only**; each test has a weakened copy that must fail it.
- **Accounts and currency:** each bank × currency present; UGX into USD refused and the reverse; an inactive account refused.
- **Parsing:** CSV, Excel, PDF; no header, a header below a preamble, broken chain, a dropped interior row, **a dropped first row, a dropped
  last row, a dropped last page, a gap in Sn**, a file one row or page over each cap (refused, not truncated), a column-drawn PDF page, a
  scanned PDF refused, ambiguous dates refused, wrong type behind a right extension, oversized; a memory-budget test of the positional mode.
- **Duplicates:** the same statement twice adds nothing; an overlapping statement adds only new lines; **two genuine identical payments on one
  day both kept**; an earlier-import row recognised although its reference was edited; a gap between two statements refused.
- **Signs and balances:** debit/credit for both layouts; derived and stated opening/closing; continuity with the last sign-off.
- **Matching:** each tier; ambiguous never automatic; partial matches summing to the line; **one test per row kind** — bank-side row moved,
  cash-side row completed with the bank leg only (deposit and withdrawal), deposit already on *Cash – Uganda* found; un-match restores the
  previous account; un-post voids only module rows.
- **Classification:** each type's posted row has the P&L treatment the reviewer chose; capital categories refused for P&L types; a refund mirrors
  its original; staff categories per D6b (staff bag moves exactly once, or refused).
- **Transfers:** same-currency pair, fee difference, cross-currency with and without a rate source, a transfer seen on one statement first.
- **Reconciliation:** the arithmetic on a known book; cut-off alignment; a posting dated into a signed-off period refused; reopen audited.
- **Security:** every page, file and action refused to other roles and on South Sudan, with the switch off on each entry point; a forged POST
  carrying `cb_action=x` and the login page's token refused; a revoked user inside the 300-second window refused; one person cannot upload and
  approve; a sales role and a Bearer-token agent see no bank row or balance through the API, the CSV export or the daily report; a narration
  with markup and one starting with `=` neutralised; a module-posted row cannot be edited or deleted by any role; no bulk action exists.
- **Repeated approval and posting** (double click, retry, two sessions) post once.
- **Regression:** the existing Cashbook, `test_bank_statement.php`, cash-in-hand = last Balance, South Sudan byte for byte, Domain B.
The relevant suites run **twice**, exact counts and failures reported.

## J. Rollout — your five steps, made concrete

Each production row is **its own deploy, with its own log, its own before-and-after figures and its own rollback handed over separately**
(`docs/07-change-control.md:41`: one change at a time), and the quarantine's `--check` after any upload.

| Step | What | Production |
|---|---|---|
| **P0** | A **read-only measurement tool**, delivered with `git pull` into the server's checkout and run by you as the database's owner: the accounts (names, kinds, currencies, active); the *Cash – Uganda* and mobile-money rows by type, source and category; the earlier import's rows (count, date range, which reference they carry); the 12 Sep adjustment's direction, category, type and its month's P&L with and without it; **cash in hand computed four ways** — unassigned rows only, unassigned plus cash/momo accounts, each with and without the adjustment. It prints to your terminal only | No data change; adds one script to the checkout |
| P1 | Build on the development branch, behind the off switch, Uganda only: schema, parsers, staging, duplicate protection, matching, review, posting, report, the CLI refusal | No |
| P2 | Test environment, **in a throwaway folder outside the repository, deleted afterwards, output masked**: your PDF and a same-period Ecobank CSV, if available, read and compared line by line; then a **sample reconciliation report** against a stated test book — empty (every line unmatched, the difference explained), or a copy of the Cashbook rows for the period if you export them — **nothing posted** | No |
| P3a | X1 and X4 fixes | Yes, after approval |
| P3b | D2 (cash-in-hand definition) and D1 (dashboards), with P0's figures before and after | Yes, after approval |
| P3c | The module, switched off; then switched on for Ecobank UGX; the cut-off (D3); the pilot month — every line decided, opening and closing verified against an independent balance | Yes, after approval |
| P4 | D4 (customer receipts) if chosen; Centenary UGX once confirmed; the USD accounts; PDF and Excel (D9) | Yes, after approval |

**No real statement transaction is posted before your explicit go.**

## K. Decisions needed before coding (recommendations first)

| | Decision | Recommendation |
|---|---|---|
| **D1** | Reverse "cash in hand only" for bank accounts? | **Yes, but bank balances only inside the new screen** (*bank.view*). The Cashbook hero, dashboards, WhatsApp summary and the CEO dashboard stay cash only |
| **D2** | What "cash in hand" (hero **and** Balance column **and** export) counts | **Decided after P0**, from the four figures. The aim: bank and non-money accounts out, today's figure unchanged; pending/rejected rows' treatment stated |
| **D3** | The earlier import, the 12 Sep adjustment, the cut-off | **Keep the 34 rows and the adjustment as they are; reconcile Ecobank UGX from a cut-off date with one approved `ADJUSTMENT` on the bank account (D3a)** — unless P0 shows a cleaner way |
| **D4** | Customer bank receipts | **One writer only: a statement credit matched to a uCRM bank-method payment and posted with its payment id**; one credit may cover several payments plus a fee (card settlements); a start date; no backfill into signed-off periods. This changes "Cashbook = cash only" and adds bank receipts to the Cashbook P&L |
| **D5** | Who does what; thresholds | Upload and classify: *bank.import*. Approve: *bank.approve*, **never the uploader; above your amount (UGX and USD), never the classifier; admins included.** Sign-off and reopen: admin, not an approver of that period |
| **D6** | Categories for classification, and stock purchases | P&L types offer P&L categories only (server-checked). **You choose: "purchase" → *Local Purchase* (expense) or *Capital Purchase*; stock bought for resale → expense on a cash basis, or inventory once an inventory → expense writer is built. "Transport" → *Travel & Field* or *Vehicle* (see D6b)** |
| **D6b** | Staff categories paid from the bank | **Refused in v1** (their staff-cash side effects live outside the service) |
| **D7** | Which bank accounts exist | You confirm each bank, currency, last four digits, and the USD counterpart accounts for funding |
| **D8** | Formats per bank | Ecobank CSV first, **if a current export parses** (L2); Ecobank PDF second; Excel when a sample exists; Centenary after a redacted sample |
| **D9** | Branch-only readers and the API guard | **First release: CSV only, form POSTs only, on the live base.** PDF/Excel in a second release that takes the readers deliberately |
| **D10** | Lock signed-off periods | **Yes:** no posting, assignment, edit, delete or void into an account dated on or before its last signed-off end, until reopened (audited) |
| **D11** | Statement files | Kept, *bank.view* only, no automatic deletion; you set a retention period; **excluded from or encrypted in the Drive backup** |
| **D12** | Project for bank rows | `dishnet`, as today's writers do |

## L. What only you can tell me

1. Do the **Centenary** UGX and/or USD accounts exist, and are the live *Ecobank Uganda – UGX / – USD* accounts the ones to reconcile? From
   which date each?
2. **Can you download an Ecobank CSV or Excel** for the same period as the PDF? (It decides D8 and makes P2 a real comparison.)
3. When staff bank collected cash, is it entered as a *Bank Transfer* on the Cashbook today?
4. Are customer bank payments recorded in uCRM under the Ecobank methods? Are card (DPO) payments in use, and paid into which account?
5. When you last added an account or recorded a transfer on **Accounts → Opening Balances**, did it save, or say *Security token mismatch*?
6. Your amounts for D5, your choices for D6, and your retention period for D11.

## M. Exceptions to the safeguards — each needs your separate approval

The request says existing financial records and opening balances must not change. These parts of the plan change existing behaviour or
records, and are therefore put to you one by one:

1. **D2** — the definition behind "cash in hand", the Balance column and the export (with P0's before-and-after figures).
2. **D3a** — one new `ADJUSTMENT` row per bank account at the cut-off (new, not a change to an old row). *Voiding the 12 Sep adjustment is not
   proposed.*
3. **E5** — confirming a match **moves an existing row into a bank account** (`assignAccount`, audited, reversible by un-match).
4. **D4** — bank receipts enter the Cashbook and its P&L.
5. **D1 / X5** — the CEO dashboard stops showing account positions on Uganda.
6. **E9** — rows the module posts can no longer be edited or deleted through the Cashbook; **D10** — postings into signed-off periods refused.
7. **X7** — the CLI's `--commit` refused on Uganda.

## N. What you receive after implementation (deliverables 1–6)

| # | Deliverable | From step | Evidence |
|---|---|---|---|
| 1 | Exact changed files and schema changes | P1 | the diff, `migrations/088`, the docs/07 entry |
| 2 | Tests run and results | P1 | two suite runs, exact counts and failures, the weakened copies caught |
| 3 | Sample reconciliation using your Ecobank statement in a test environment | P2 | the masked line-by-line comparison and the sample report, nothing posted |
| 4 | Known parser limitations and unresolved items | P2 | a section in the docs/07 entry |
| 5 | Deployment and rollback plan | P3 | pinned deploy scripts, rehearsals, each rollback handed over on its own |
| 6 | Confirmation that no production financial records were changed | every step | the deploy's before-and-after checks; P0's figures |

## O. P0 as built — 13 October (not run on the server)

You approved *"prepare and test P0 only"*, strictly read-only, with only the minimum aggregate figures. Built on the
development branch; rehearsed on synthetic books; attacked by an independent review. **Not run on the server.**

**Files.** `scripts/bank-recon-p0.php` (the tool), `scripts/bank-recon-p0.sh` (the one command, which refuses any tool whose
sha256 is not the reviewed one), `scripts/harness/bank-recon-p0/` (the rehearsal). No plugin file changes; nothing is
installed or deployed.

**The command: superseded by §P.** The `git pull` form first written here is withdrawn: it would have updated the server's
checkout. Use only the gated command in §P.

**What it reads.** Through `docker exec`:
- the `ucrm` container's `/data` mount, the plugin's `manifest.json`, `.deployed-commit` and `ucrm.json` (for the data
  folder's name);
- the live `plugin.sqlite3`, and in it only `cb_ledger`, `cb_accounts` and the settings row (`kyc_config`);
- the three configuration files and the vault, for the book's two currency settings, rebuilt as `bookBase()` rebuilds them.

It never opens a database in `<plugin>/data`. It never reaches uCRM or the network.

**Why it cannot write.**
1. The tool is piped into the container's PHP on standard input, so nothing is copied in. It loads no plugin code: the
   Cashbook's own classes alter tables and refresh the vault.
2. SQLite opens the book **immutable and read-only**, with `query_only=ON` and `temp_store=MEMORY`, all checked. Immutable,
   SQLite takes no lock and makes no file. A plain read-only open would make the `-wal` and `-shm` and leave them behind;
   that was measured here, as in docs/07 5.18.91.
3. It reads only at a moment when the plugin has the book closed, so that no `-wal` or `-shm` is beside it. It waits up to
   two minutes for such a moment. Every query is one `SELECT`, inside one read transaction that ends in `ROLLBACK`.
4. It reads the book **twice**, on two connections. The figures count only if all four of these hold:
   - both readings are identical;
   - SQLite reports 0 rows changed on each connection;
   - the file's inode, size and times did not move;
   - no side file appeared.

   A reading that SQLite cannot finish ends as "run again" (exit 3). It is never shown as an error that could read like
   damage to the book.
5. PHP runs as the database's owner, never root, with `log_errors=0` and `opcache.enable_cli=0`. So nothing is appended to
   a php.ini error log or a cache file.

**What it prints, and what it does not.** It prints, per currency:
- cash in hand today, all projects, as the accounts dashboard and the evening WhatsApp show it;
- the same rows split by where they sit: unassigned, the 12 Sep adjustment, cash accounts, mobile-money accounts, bank
  accounts, other accounts, and accounts that do not exist;
- the pending and rejected rows inside today's figure;
- the cash and mobile-money rows by type;
- the four candidate figures A–D, with each one's difference from today;
- the same for the Cashbook hero, which shows project `dishnet` by default.

Elsewhere in the log it prints:
- account ids, with kind, currency, active and row count;
- the 12 Sep adjustment row's id, date, direction, status, type and source, and whether its category is a capital one;
- the earlier import's counts and dates.

**No name is printed: not of an account, nor of anyone.** Nor is any description, person, reference, customer or
category. Every other value is a number, a checked date or a code from a fixed list. Match the account ids on
*Accounts → Opening Balances*.

Narrower than the P0 row of §J, as your "minimum" asked:
- no account names;
- the cash and mobile-money rows by type only, not also by source and category;
- the adjustment's month in the P&L as yes/no, not as that month's P&L totals.

**The four candidate definitions (for D2).** Today's figure counts every non-voided row of the currency, whatever its
account.

| | Counts | Leaves out |
|---|---|---|
| **A** | unassigned rows, the adjustment, cash and mobile-money accounts | bank and other accounts |
| **B** | unassigned rows, cash and mobile-money accounts | bank and other accounts, the adjustment |
| **C** | unassigned rows, the adjustment | every account |
| **D** | unassigned rows | every account, the adjustment |

Rows that name an account which does not exist are left out of all four, and are counted on their own line.

**How to read the log before sending it.** Each of these must hold:
1. The command's last line says P0 finished (exit 0), and P0's own last line, just before it, is
   `P0: DONE — read only; nothing was written.`
2. `the parts add up:` reads `ok` for every currency.
3. `the connections changed 0 and 0 row(s); query_only=1`.
4. `read twice, immutable: the readings are identical; the database file did not move …; side files none, before or after`.
5. `the pages' settings row … — the same`. If it says DIFFERENT, every currency of both is measured, and that is a finding.
6. `12 Sep adjust. found: …`. If it lists candidates instead, stop and send the log (superseded by §P: a second run only on
   your word, through the gated command).

If something else happens (superseded by §P: nothing is run again without your word):
- **Exit 3** means the plugin wrote during the reading, or a check failed.
- **Exit 2** names its reason.
- **A STOP** comes from the command's own checks, before P0 runs.

**The log carries real totals.** Treat it as confidential. **The figures will not be written into the repository.** The
record will carry the decision you take and whether these checks passed.

**Evidence.**
- **The rehearsal:** `scripts/harness/bank-recon-p0/rehearse.sh`, run on synthetic books written through the plugin's own
  code.
  - Seven books: Uganda, South Sudan, three settings edge cases, and two adjustment cases.
  - Every figure equals the plugin's own `CashbookService::cashInHand()` and `bookBase()`, and an independent row-by-row
    count.
  - Every file P0 could reach is identical afterwards: content, inode, size, times, owner and mode.
  - No planted name, phone number, account number, reference or free-text value is printed.
  - The other cases:
    - the plugin holding the book;
    - a writer between and during the readings, including a 30,000-row book against a writer that closes it every few
      hundred milliseconds;
    - every refusal;
    - the source rules;
    - the command itself, through a strict fake docker.
  - Every weakened copy of the tool and the command is caught.
  - Counts: 95 checks on the reviewed files, plus 30 weakened copies of the tool and the command, each caught. That is
    **125 ok, 0 failed, on two consecutive runs**, with the files unchanged between them.
  - The reviewed files: tool sha256 `0143dae93cf34ba4…` (pinned in the command), command sha256 `9a96e7fe1cd32529…`.
- **The review:** four independent reviewers (read-only, figures, leaks, the command), each finding checked by two
  sceptics.
  - 16 findings: 13 upheld, all fixed and covered by the rehearsal; 3 refuted.
  - The most important one was reproduced: a reading interrupted by a plugin write ended as an uncaught "database disk
    image is malformed" (exit 255).
    - The book itself is not damaged by that: integrity_check said ok.
    - But the message would have read like corruption on your server.
    - It now ends as "run again". In the stress test, 4 to 7 readings in each run of 12 were caught that way, over four runs,
      and none crashed.
  - The other fixes:
    - account names dropped;
    - digits split by separators (`0772 123 456`), which the first masking missed;
    - a category, type, source or currency in free text now reads `(other)`;
    - the labels: today's all-projects figure is the dashboard's and the evening WhatsApp's; the Cashbook hero is project
      `dishnet`;
    - `temp_store=MEMORY`;
    - `log_errors=0`;
    - an exact comparison of the totals, not a rounded one;
    - clearer stops;
    - the checkout's commit and the command's own sha256 in the log;
    - the candidate ids listed when the adjustment is not found alone.

**Not done, deliberately.** No production run. No deploy. The quarantine was not touched. The importer's `--commit` was
not run. The bank reconciliation module is not built and not switched on. The statement is not in the repository: every
figure above comes from synthetic books.

## P. The gated run — approved 13 October with six gates; for the operator to run

You approved P0 with six gates:
1. verify the commit, the working-tree state and the checksums first;
2. show the command, and run nothing that differs;
3. run P0 only after those checks pass, and nothing else;
4. keep the log confidential;
5. stop at any race, inconsistent reading, unexpected path or failed check: no retry, no repair;
6. obtain the 5.18.94 after-check evidence separately.

This session cannot reach the server, so the gates are built into what you run: `scripts/bank-recon-p0-run.sh`, in
commit `3d250082dea1fa99cad1283068e8f94d204657f0`. It refuses before P0, naming the gate, whenever one does not hold.
- A second independent review (four lenses, two sceptics per finding) upheld 20 of 22 findings against its first
  version. All are fixed.
- A check of those fixes found six more gaps, all fixed in this version:
  - `ucrm.json` read too loosely;
  - a fetch that could add tags;
  - a log that Ctrl-C could cut off;
  - a rebase named as a detached HEAD;
  - an unidentified adjustment reported as plain DONE;
  - wording.
- A review of that version (four lenses, two sceptics per finding) upheld seven distinct defects. All are fixed here:
  - a git read of the checkout that failed counted as "nothing modified";
  - an unfinished cherry-pick sequence (`.git/sequencer`) was not refused;
  - a signal during one of the runner's own git reads could divert the exit line away from the log;
  - TERM to the whole job cut the log;
  - the runner kept two P0 lines in a file that this section did not list (they are now kept in memory only);
  - the second-run form of the adjustment line was missing from the reading list;
  - one rehearsal case could not tell the two DONE results apart.

  A check of those fixes (three lenses, two sceptics per finding) upheld three regressions they had introduced. All
  three are fixed:
  - the index was hashed through a shell variable, so every log gained two bash `ignored null byte` warnings;
  - an exported `SHELLOPTS` could switch job control on, so a good run would have ended in a false exit 6;
  - a failed read of HEAD's branch was taken for "detached".

  A last check of those three fixes found two rehearsal cases that could not fail (one read another case's result;
  one passed whatever the line did). Both are fixed, each with a control showing it can now fail.

  Three refuted findings were taken as improvements: the unidentified adjustment now has its own exit (7), its owner
  and "no `installed` line" branches are rehearsed, and the interruption cases are described exactly. The sceptics
  refuted the claim that a symlinked data folder could make P0 read another book: the plugin opens the same path, so it
  is the live book either way.

**Verified here before handing over.**
- `5954ede513f46e6ddcdb061fa60ce63f65cdacf5` (§O, with the review's fixes) is on `claude/study-this-jhe2eg` on GitHub.
- At that commit, the tool's sha256 is `0143dae93cf34ba4…` and the command's is `9a96e7fe1cd32529…`. The command pins the
  tool's.
- The runner pins all three. This checkout was clean when the runner was committed.
- The runner's own sha256 is `8e7eb192bb7a27b932e96ea8cca7e2605709a8c4cf7293915878f95d067013e4`; the line below checks it.

**The command — one line, as root on the server, after the P0 log of any earlier attempt has been sent.** Then send back
THE LOG FILE it names, not a copy of the terminal:

    ( umask 077 && mkdir -p /root/dnb-bank-recon && chmod 700 /root/dnb-bank-recon && cd /opt/dishnet && git -c gc.auto=0 -c maintenance.auto=false -c core.hooksPath=/dev/null -c fetch.writeCommitGraph=false -c remote.origin.followRemoteHEAD=never fetch -q --no-tags --no-recurse-submodules --refmap= origin +refs/heads/claude/study-this-jhe2eg:refs/remotes/origin/claude/study-this-jhe2eg && git cat-file blob 3d250082dea1fa99cad1283068e8f94d204657f0:scripts/bank-recon-p0-run.sh > /root/dnb-bank-recon/bank-recon-p0-run.sh && echo "8e7eb192bb7a27b932e96ea8cca7e2605709a8c4cf7293915878f95d067013e4  /root/dnb-bank-recon/bank-recon-p0-run.sh" | sha256sum -c --quiet - && env -u BASH_ENV -u ENV -u UCRM_CONTAINER -u SHELLOPTS -u BASHOPTS bash /root/dnb-bank-recon/bank-recon-p0-run.sh 2>&1 | ( trap '' INT HUP TERM; exec tee /root/dnb-bank-recon/p0-$(date -u +%Y%m%dT%H%M%SZ).log ) )

- The parentheses keep `umask 077` and `cd` inside the command, and your shell is left as it was.
- `env -u` starts the runner without `BASH_ENV`, `ENV`, `UCRM_CONTAINER`, `SHELLOPTS` or `BASHOPTS`, whatever your
  shell exports. Your shell's own options, if it exports them, therefore do not reach the runner. The runner also turns
  job control off itself and checks that it can keep P0's lines; it refuses if it cannot.
- The `tee` at the end ignores Ctrl-C, a hang-up and TERM (a plain `kill`). So an interrupted run's log still ends
  with its exit line.

**What it changes, and what it never touches.**
- **The fetch** adds Git objects to `/opt/dishnet/.git`, writes `FETCH_HEAD`, and moves one ref,
  `origin/claude/study-this-jhe2eg`, adding a line to that ref's reflog.
  - No local branch or tag is created or moved: the refspec is explicit and `--refmap=` is empty.
  - **No tag is fetched (`--no-tags`).** Without it, git adds any tag that points into the fetched history. The
    rehearsal measured that (R28).
  - `followRemoteHEAD=never` stops git 2.48 and later from creating `refs/remotes/origin/HEAD` on a fetch. Older git
    never does that.
    - The sandbox has git 2.43, so this one setting is **not rehearsed**.
    - On older git it is an unknown setting, which git ignores.
  - Automatic garbage collection, maintenance, hooks, commit-graph writing and submodules are switched off for it.
  - There is no checkout, pull, merge or reset.
- **The working tree, the index, HEAD and every local branch are never written.** Every git command in the runner runs
  with `--no-optional-locks`, so not even the index's stat cache is refreshed.
- **In `/root/dnb-bank-recon`** (`chmod 700` by the line) it writes:
  - the runner;
  - one folder `src-5954ede-…` holding the two reviewed files, taken out of the commit;
  - the log (0600).
- **If the runner's sha256 does not match,** `sha256sum` prints FAILED and nothing runs. There is then no log; tell me
  what the terminal said. Likewise if the fetch or `git cat-file` fails.

**What the runner checks, in order. Any failure stops it before P0 with `GATE REFUSED: <reason>` and
`P0 was NOT run (exit 4)`.**
- **G0, first.**
  - It must run as root.
  - `UCRM_CONTAINER`, if set, must be `ucrm`; otherwise P0 would read another container than the one checked.
  - `git`, `docker` and `sha256sum` must be present.
  - Bash must run a pipeline's last command in the runner's own shell (`lastpipe`, with job control off). Otherwise the
    checks after P0 could not read its lines, so it refuses.
  - The log folder must be root's and mode 700.
  - An option other than `--adjustment-id=N` (at most 10 digits) is refused even before G0. The log then holds only
    the message and `RUNNER EXIT 4`, with no `GATE REFUSED` line.
- **G1, the checkout.** It must be the top of a git checkout. Each of these is a refusal:
  - a merge, rebase, cherry-pick, revert or bisect in progress, including a cherry-pick or revert sequence left
    unfinished (`.git/sequencer`). This is checked first, so a rebase is named as a rebase, although it detaches HEAD;
  - a detached HEAD;
  - any git read of the checkout's state that fails, for example on an unreadable index or an unreadable HEAD. A failed
    read is never taken as "nothing modified" or as "detached";
  - a tracked file modified or staged;
  - any file git does not track inside the plugin folder (its `data/` aside), ignored ones included, counted as the
    09 Oct record and the deploy scripts count it.

  Untracked files elsewhere are counted and listed (at most five), not refused: P0 never reads the checkout's files.
- **G2, the reviewed P0.**
  - The commit must be present, and `origin/claude/study-this-jhe2eg` must still contain it.
  - Both files are taken out of the commit with `git cat-file`, never out of the working tree, and each must match its
    reviewed sha256.
  - The command must pin the same tool.
- **G3, the book.**
  - The `ucrm` container must be running.
  - Its `ucrm.json` must exist and be valid JSON that decodes the way the plugin's own loader decodes it. A file that
    is missing, unreadable, truncated, not JSON or a bare value is refused. It is never taken to mean "names no
    folder".
  - The data folder must resolve to `/data/ucrm/data/plugins/.dishnet-hybrid-sudan-data`, the live store since 09 Oct.
    Anything else is an unexpected database path.
  - `plugin.sqlite3` must be there, owned by `1000:1000`.
- **G4.** P0 runs once, in `ucrm`, with `UCRM_CONTAINER`, `BASH_ENV`, `ENV`, `SHELLOPTS` and `BASHOPTS` removed from its
  environment.
  - Its exit is reported as it is: 0 done, 1 the P0 command's stop, 2 refused, 3 stopped. **It is never retried.**
  - **Afterwards the P0 command's own `installed` line must name `ucrm`, the folder checked in G3, and owner
    `1000:1000`.**
    - The command reads `ucrm.json` again itself. If the file changed between the two reads, the result is
      `RESULT: THE P0 COMMAND READ ANOTHER FOLDER … (exit 6)`, never `P0 DONE`.
    - The same result follows if the command did not say which folder it read.
  - **If the 12 Sep adjustment was not identified,** the result is
    `RESULT: P0 RAN (its exit 0), BUT THE 12 SEP ADJUSTMENT WAS NOT IDENTIFIED (exit 7)`, never `P0 DONE`. Options B and
    D are then not computed.
- **G5.** The checkout is proved unchanged afterwards: HEAD, the branch, every local branch and tag, the stash, the
  index, and the status of every file.
  - Ignored files are included; an ignored directory counts as one entry.
  - If anything changed (nothing here changes it), the result is `RESULT: THE CHECKOUT CHANGED while P0 ran (exit 5)`,
    never `P0 DONE`. Nothing is repaired.
  - If git cannot read the checkout again afterwards, the result is
    `RESULT: THE CHECKOUT COULD NOT BE READ AGAIN after P0 (exit 5)`, never "unchanged".

**A complete log ends with `RUNNER EXIT <n>`.**
- The command pipes into `tee`, so the terminal's own status is tee's, not the runner's. Read the status from the log.
- An interrupted run ends the same way: 130 for Ctrl-C, 129 for a hang-up, 143 for TERM, the signal a plain `kill`
  sends.
  - The rehearsal sent Ctrl-C, a hang-up and TERM to the whole job after P0 began, while its `docker exec` was under
    way (R27, R27h, R27t).
  - It also sent Ctrl-C while the runner was inside one of its own git reads written into a file (R27g).
  - The exit line is written on a copy of the log's descriptor, so no redirection in force at that moment can divert
    it.
  - After an interruption the P0 reading inside the container may run on to its end, or stop. Either way it writes
    nothing, and its result does not reach the log.
- **A log that does not end with that line was cut off. It is no result.**

**Reading the log.** Each line below is quoted after its label and padding; each must be there:
- `checkout … at <commit> on <branch>`;
- `tracked files modified or staged: 0; not tracked inside dishnet-hybrid-sudan/ (data/ aside, ignored included): 0`;
- `reviewed P0 … commit 5954ede513f46e6ddcdb061fa60ce63f65cdacf5`;
- `tool sha256 0143dae93cf34ba4… = reviewed; command sha256 9a96e7fe1cd32529… = reviewed; the command pins the same tool`;
- `book … /data/ucrm/data/plugins/.dishnet-hybrid-sudan-data/plugin.sqlite3, owner 1000:1000 — as expected`;
- §O's checks 1–5, inside the P0 part;
- inside the P0 part, the adjustment line in one of two forms:
  - `12 Sep adjust.  found: the one live unassigned row of …`, on a first run;
  - `12 Sep adjust.  named by --adjustment-id: id N, …`, on a second run made on your word;
- `checkout … unchanged: HEAD, branch, local branches and tags, stash, index and every file's status are as before`;
- `folder … the P0 command read the folder checked above: /data/ucrm/data/plugins/.dishnet-hybrid-sudan-data, owner
  1000:1000, in ucrm`;
- `RESULT: P0 DONE (exit 0). Send this LOG FILE back; …`, then `RUNNER EXIT 0`.

The P0 command's own `this checkout` line reads `not readable as a git checkout`. That is expected: it runs from the
extracted folder, which the runner has verified.

**If it stops**, at any gate, with P0's exit 1, 2 or 3, with exit 5, 6 or 7, or interrupted: send the log. Nothing is
retried or repaired. A further run waits for your word.

**If the 12 Sep adjustment could not be picked alone,** that is a stop too (exit 7).
- The result line says so.
- The log lists up to ten candidate ids, each with its direction and status only.
- A second run, only on your word, is the same line with ` --adjustment-id=N` added right after
  `bash /root/dnb-bank-recon/bank-recon-p0-run.sh`, before ` 2>&1`.

**The log stays confidential.** It holds the book's totals. It is not committed, and its figures will not be written into
the repository.

### P.1 The 5.18.94 after-checks — separately, each with its own log

No evidence for any of them has reached this session. Each is reported only from its own log or screen. A check whose log
is not sent is reported as **not evidenced**, never as passed or as not run.

**Run them after the P0 log has been written, never at the same time.**
- Items 2, 3 and 4 are recorded runbook steps: docs/07, 5.18.94 runbook steps 6 and 7. For item 4, only the details
  below are added here.
- Items 1 and 5 are new here.

1. **What already exists.** Read only:

       TZ=UTC ls -l --time-style=+%FT%TZ /root/dnb-5.18.94/ /root/dnb-stray-quarantine/

   It shows which runs saved a log there. A run whose log is not there is reported as not evidenced, not as not run.
   Send the listing.
2. **`--after-only`**: the recorded command (step 6), wrapped in `( umask 077 && … )` so that its log is private:

       ( umask 077 && cd /opt/dishnet && bash scripts/deploy-5.18.94.sh --after-only 2>&1 | tee /root/dnb-5.18.94/after-$(date -u +%Y%m%dT%H%M%SZ).log )

   Expected: PASSED, R19 conclusive.

   It is **not read-only**, by its own header (`scripts/deploy-5.18.94.sh`, `--after-only`):
   - its checks use throwaway copies in temporary folders, which it removes;
   - the switch read may refresh the vault;
   - R7's run of the cash-in-hand tool may add its 071 checksum warning to the live `migration.log`;
   - on an R19 failure it appends one line to its state file. That line then fails every later `--after-only` and every
     `--rollback` (RB) of this deploy, and blocks later deploys until a `--check` begun after it has passed.

   It changes no setting and no financial row. Like P0's, **its log holds the book's cash-in-hand totals (R7)**: send it as
   a file, and its figures stay out of the repository and the record.
3. **The quarantine's own `--check`**, as recorded. It changes nothing but its own log and, on a failure, one line of its
   record:

       ( umask 077 && cd /opt/dishnet && bash scripts/quarantine-stray-store.sh --check 2>&1 | tee /root/dnb-stray-quarantine/check-$(date -u +%Y%m%dT%H%M%SZ).log )

   Expected: PASSED, with one note, K2: the container serves `f4b4c96`, not `69c87a6`. That note is expected after this
   deploy.
4. **The portal's consent version.**
   - Use your own or a test Uganda account, one whose WhatsApp you hold and which has not signed in since 13 Oct
     13:58:04 UTC.
   - Report the line under the consent box; expected: `Terms v1.2 · Privacy v1.2 · Effective 16 October 2026`.
   - Then press **Go back**, which signs you out and records no acceptance, unless it is your own account.
   - If no consent step appears, report "no consent step shown". That is **not** evidence of 1.2.
5. **The badge script's log**, if it was run.
   - The command handed over in the conversation on 13 Oct wrote it to `/root/dnb-5.18.94/badge-<time>.log`. That name
     is in no repository file.
   - If the listing shows none, say where it was written, if you know. Otherwise its run is not evidenced, and will be
     reported so.

---

*Discovery, P0 as built (§O) and its gated run (§P). No plugin file changed; no migration, no production data, no deployment. The supplied
statement stays outside the repository.*
