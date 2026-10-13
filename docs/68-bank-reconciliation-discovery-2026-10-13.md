# 68 — Cashbook bank statement import and reconciliation: discovery and plan

**Date:** 13 October 2026 · **Status: DISCOVERY ONLY.** Nothing was built, migrated, changed or deployed. No production
data was read: this is a read of the code (live `f4b4c96` = 5.18.94, and the development branch) and of the supplied
Ecobank PDF, opened in an isolated folder and read with every digit and name masked. **Coding waits for your approval of
the decisions in §K and of the exceptions in §M.**

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

---

*Discovery only. No file outside `docs/` changed; no migration, no production data, no deployment. The supplied statement stays outside
the repository.*
