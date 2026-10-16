# Bank book, statement checking and cost per customer: design proposal, version 2 (Uganda)

**Status: design only. Version 2, revised 16 October.** This revises the 14 October draft. It applies three critiques and your four answers of 14 October, and then the corrections of two checks of version 2 against the code. I read the code at checkout `158e7380`, docs/68 and docs/07, and re-read every file the critiques and the checks cite. Nothing was built, edited, run or deployed. No test or rehearsal was run. No server was contacted and no live figure was read. One sweep compared every payment-path file with the live 5.18.94 commit `f4b4c96` (`git diff --stat`) and found them byte-identical; `cron/master.php` differs only by the flag-gated `ai_media` job.

**Every name and amount in the examples is invented. No figure from P0 appears here.**

**Your decisions of 16 October** (your words: *"Ok build I will. Go with your recommendation"*):
- **D13 = A**, statement first, with a list of expected payments.
- **R0 approved to be built.** It will be built, rehearsed and reviewed like P0, then handed over. You run it on the server; it changes nothing. **Built 16 Oct; its hand-over is Part 4.**
- The other recommendations in §3.1 are taken as the direction. **Each production step still waits for its own go**, as §3.2 says, and the decisions that need evidence (D2, D3, D14, D23, D25, D26) wait for R0, P0's second run and your answers in §3.3.

**How to read the labels**
- **[M]** Measured: read in the code or in the record. A `file:line` is given.
- **[I]** Inferred from what was read. The code was not run to prove it.
- **[NEW]** Proposed here. It does not exist today.

**Paths** are under `/home/user/dishnetuganda/dishnet-hybrid-sudan/`, with two exceptions at the repository root `/home/user/dishnetuganda/`: paths that start with `docs/`, and paths that start with `../` (for example `../scripts/bank-recon-p0.php`). **F12** points to the facts in §2.1, **X8** to the fixes in §2.4, **D13** to the decisions in §3.1.

---

# Part 1 — For you, in plain words

**Words and codes used in Part 1.** You can read Part 1 without Parts 2 and 3.
- **The book** is the plugin's Cashbook. **Cash in hand** is its headline figure, meant to be the physical cash we hold.
- **uCRM** is the billing system where staff record customer payments. **The staff app** is the plugin's phone app for staff.
- **D-numbers** (D2, D3 …) are decisions for you. Each is explained in a few words where it first appears; all are listed in §3.1.
- **R0 to R3** are the repair steps for voided entries: R0 a read-only count, R1 a read-only list in the plugin, R2 your decision entry by entry for cash, R3 bank entries repaired from the bank statement (§1.9).
- **X-numbers** are safety fixes to today's code. You only need to know that some must ship before others.
- **P0, P3a, S1a …** are build steps, in order (§3.2). P0 is the read-only measuring tool you ran on 14 October.
- **[M], [I] and F- or I-numbers** point to the evidence in Part 2. You can skip them.

## 1.1 What your four answers settle

| You said on 14 October | What it settles | What it leaves open |
|---|---|---|
| **Voided entries: "List first, I decide each"** | Nothing is repaired in bulk. Nothing is "un-voided". You first get a read-only list. You decide each entry. Nothing changes until you approve. | Which entries you mean (§1.9). Who approves with you (D5). Which date a repair carries (D18). When repairs may start: after the safety fixes, after you choose how to settle the past (D3, explained in §1.9), and after that month's bank statement has been checked. |
| **"When a customer pays by bank, is it recorded in uCRM?" → "Yes, always in uCRM"** | Every bank-paid customer payment has a uCRM payment. So every customer bank credit has a uCRM payment to match, and through it a customer. A bank credit with no uCRM payment is probably not a customer payment: cash that staff banked, money moved from the Airtel wallet, or funding. | **Under which method staff record it.** The office payment form offers only Cash. The staff app offers Cash, Mobile Money and Bank; it records mobile money as Cash. If staff pick Bank there, the plugin sends it to uCRM as a bank payment, **but the book still counts it as cash** [M, F44]. So "recorded in uCRM" does **not** mean "recorded as a bank payment", and either way the money may be sitting in your book as cash today (§1.5). Also open: whether staff record it before or after the money shows in Ecobank. |
| **Costs per customer: "all of them" — what we paid Starlink, what the customer paid us, what the bank charged** | The customer's page shows what we paid Starlink **for each kit**, and what the customer paid us and the bank charges on those payments **for the customer as a whole**. Plus a list of what is not costed yet. A customer with two kits gets one "paid" figure for both. | Whether each kit was sold or lent (D25). The currency of each kit's cost, which is not stored with the kit (F33). Whether each kit has its own Starlink account (D26). How a shared cost is split: one Starlink order for several kits, one trip for several customers (D16). Whether payments can later be split per kit (D17). |
| **Bank accounts: Ecobank UGX and USD** | Two accounts are in scope: Ecobank UGX and Ecobank USD. Centenary is out unless you add it. | Whether the plugin's existing Ecobank account rows are the ones to use, and from which date (D7). Whether Ecobank gives a CSV or Excel download for each (D8). Card (DPO) settlements, if DPO is used. |

## 1.2 What you can use today, with no build

**Accounts → Reconcile** (the screen is titled *Collection Reconcile*) already reads every uCRM payment, of every method, for the dates you choose. It lists the payments that have no Cashbook entry, with a total per payment method [M, F53]. It is read-only, for admins and accountants.

Use it now to see uCRM bank income. Its per-method list also shows which method names your staff really use.

Its caveats [M, F53]:
- It counts a **voided** Cashbook entry as "in the cashbook". A payment you voided looks matched.
- It recognises only some kinds of Cashbook entry. A payment the staff app or the field form posted before uCRM accepted it, or a KYC cash sale, can look **missing although it is in the book**.
- If uCRM fails part-way, it shows a **shorter list that looks complete**. Run it again if the count looks low.
- It stops at 5,000 payments without saying so. Use short date ranges.
- **Every amount on it carries the UGX sign, even a USD payment, and every total adds UGX and USD together**: the top totals and each per-method total alike. Until this screen is fixed (step S1a), check a payment's currency in uCRM before you rely on a figure.
- It compares the uCRM date with the Cashbook date, and the Cashbook often dates a payment by the day it processed it. A payment near the edge of your range can look missing.

**Two buttons not to use to "bring back" past entries** [M, F47; I, I8]:
- **Sync Now with a From date**, on the Cashbook page;
- the **Debug + Sync** link beside it.

They can post some voided receipts again and not others. After a repair, they could post a third copy. Please also keep the old command-line bank-statement import unused on Uganda, as already decided on 4 October [M, F15, F18].

**Until the first safety fixes ship (step P3a), please do not edit or delete voided entries.** Today any signed-in user can [M, F23]. It would destroy the evidence the repair list relies on.

## 1.3 Is each thing possible?

| What you asked | Possible? | The catch |
|---|---|---|
| **A bank book that works like the cash book** | **Yes** [I] | Today, anything written on a bank account moves cash in hand in its currency, whichever account it sits on [M, F10]. So the definition of cash in hand (D2) must be fixed first. And you choose who writes a customer's bank receipt (D13, §1.6). |
| **uCRM bank income tracked in it** | **Partly today, fully later** [I] | Today: the Reconcile screen above. Later: an "expected payments" list kept by the plugin. But most bank and Airtel Money payments are probably already in the book as cash, whether uCRM recorded them as Cash or, from the staff app, as Bank (§1.5). Only the bank statement shows where the money really landed. |
| **Upload the real bank statement to check it** | **Yes.** Designed on 13 October (the bank-reconciliation design), not built [M, F16] | Ecobank CSV first, if a current download can be read (D8, the statement formats). UGX and USD. The PDF needs a new reader. |
| **Map a bank entry to the customer** | **Yes, for customer payments** [I] | Through the uCRM payment, never by guessing from the bank's text [M, F30–F31]. **The reference the customer writes matters most.** Many plans cost the same, so amount and date alone will often fit several customers [I]. An invoice or quotation number in the reference points to one customer (D24). |
| **Learn what a customer costs you** | **Partly** [I] | Kit cost exists, but its currency is not stored with the kit, and a lent kit is our asset, not the customer's cost [M, F33]. The monthly Starlink charge can be tied to a customer only if the kit's own Starlink account is recorded in the plugin [M, F38; D26]. Bank charges on a customer's own payment can be tied exactly once matched. Payments and charges are per customer, not per kit. Transport and fuel need a person to tag them. |
| **Fix the past entries you voided** | **Yes: a list first, then you decide each** | Never by "un-voiding". Some entries must never be re-entered: the three hand-typed copies found in September (§1.9), and the 12 Sep adjustment and the earlier bank-import rows, which only D3 can change. |

## 1.4 What "cash in hand" is today

**Cash in hand is meant to be physical cash. Today it is not exactly** [M, F10, F15].

On 4 October you chose to show "cash in hand only". That changed the display. It changed no data. **Cash in hand keeps today's definition until D2** (D2 is the decision on what cash in hand counts). Today's figure adds up every entry of the currency that is not voided, whatever account it sits on, pending and rejected entries included. So it contains:

1. **The Ecobank rows and the 12 Sep adjustment.** The adjustment cancels the account rows. That is why the figure looked right on 4 October [M, F15].
2. **Bank and Airtel Money payments that uCRM recorded as Cash** [I, I6]. They count as cash, although the money is in Ecobank or in the Airtel wallet.
3. **Bank payments entered in the staff app with the method Bank.** The plugin sends them to uCRM as bank payments, and the book still posts a cash receipt for each [M, F44, F6].
4. **Probable double entries** of the same payment, from several automatic paths [I, I1]. Not measured yet. A read-only count (R0, §1.9) will measure them.

What makes it physical cash:
- D2 changes the definition. **D2 may change today's figure.** Whether it does depends on which rows it leaves out, the 12 Sep adjustment among them. P0's second run shows it [I].
- Then the bank and Airtel receipts that sit on the cash side are moved or reclassified, one by one, from the statement. A person deals with any double entry the count finds.
- **The test is a physical cash count**, in two stages. When D2 ships (step P3b), the count is recorded beside the new figure, and every difference is listed and explained: bank or Airtel money recorded as cash, staff-app bank payments, double entries. The two can only agree after those entries are dealt with (steps P4, R2 and R3). **Then a new count must equal the figure.** Arithmetic alone is not enough.

## 1.5 Two cases that are probably most of your non-cash money

### Case 1 — paid by bank, recorded in uCRM as Cash

1. A customer pays by Ecobank transfer.
2. Staff record the payment in uCRM, as you said they always do [M, F44]:
   - on the office payment form, the only method offered is Cash;
   - in the staff app, they can pick Bank. The plugin then sends it to uCRM as a bank payment, but the book still posts a cash receipt;
   - on uCRM's own screen, they may pick Cash too.
3. In each case the book posts it as a cash receipt. **Cash in hand rises, although the money is in the bank.**
4. Later the drawer does not match the book. This may be part of why entries were voided [I].

How the design handles it [NEW]:
- When the Ecobank statement is checked, the credit is matched to that uCRM payment, best through the invoice or quotation number in the reference.
- A person confirms. The existing cash entry is then **moved into the Ecobank account**, with an audit record. Moving an existing entry changes an old record, so you approve this kind of move separately, once.
- It is moved only when exactly one live entry exists for that payment and no duplicate is found. Otherwise a person decides.
- It is done only after D2. Before D2, moving an entry between accounts changes nothing in cash in hand.
- If you already voided that cash entry, it cannot be moved. If its date is after the cut-off, or you choose the rebuild (D3, §1.9), the repair writes a new bank entry from the statement line instead (R3). **With a single cut-off, a voided entry dated before it stays voided.**
- Staff may then correct the method in uCRM by hand. That changes uCRM only: the book ignores later edits in uCRM [M, F7].

### Case 2 — Airtel Money

- Your live payment instructions put **Airtel Money first**, then Ecobank [M, F45].
- The plugin was built for a uCRM with no mobile-money method. Its rule sends mobile money to Cash, including the staff app's *Mobile Money* choice [M, F8, F44].
- So Airtel payments are very probably in the book as cash [I, I6]. The money sits in the Airtel wallet, not in the drawer and not in Ecobank.
- When wallet money is moved to Ecobank, that Ecobank credit is a **transfer of our own money**, never new income.
- The design has no reader for Airtel statements yet. Two choices (D23, the Airtel decision):
  - add the Airtel wallet as its own account, with its own download, once you can supply a sample; or
  - leave it out for now, and say so on screen. It then keeps inflating cash in hand until it is reclassified.

## 1.6 The big choice: who writes a customer's bank receipt (D13)

You asked for **B**: track uCRM bank income "the way the cash book works", then upload the statement to verify it. I recommend **A**, which differs. You should choose knowingly.

| | **A — statement first, plus a list of expected payments** | **B — uCRM first (what you asked for)** |
|---|---|---|
| When the book entry appears | When a person confirms a statement line against the uCRM payment | As soon as uCRM records the payment under a bank method |
| Before the statement | The payment shows on an "Expected" list kept by the plugin, outside the book. Useful only if staff use bank methods (D14, which uCRM methods staff use) | An entry on the Ecobank account, marked "not yet verified" by the plugin outside the book |
| Bank balance in the book | Only money the bank has shown | What uCRM says. A uCRM mistake stays in the balance until the statement finds it |
| Monthly profit and loss | Bank income appears only after the statement is uploaded and matched. **A timing gap** | Appears at once. Unconfirmed entries at month end are "in transit", the usual bank-reconciliation model |
| Customer page vs profit and loss | They disagree until matched | They agree sooner |
| Bank payments recorded as Cash (Case 1) | Found from the statement | **Not seen at all.** They still need the statement side |
| Changes to the live payment code | The duplicate-check fixes needed under both (X10, X11). They change one of the project's written safety rules (the duplicate-check rule). If the Expected list is built, a second written rule changes too (the payment-method rule) | The same, and both written rules always. Plus: the three automatic paths that skip non-cash payments today must post bank payments to the bank account, on uCRM's date, and follow later edits and deletions in uCRM; and a switch keeps South Sudan out |
| Must happen first | D2, before anything is posted | D2, before anything |

**Why I recommend A:**
- The bank balance never shows money the bank has not shown.
- It changes less of the live payment code.
- You said bank payments are always in uCRM, but the method is unknown. If most are under Cash, B adds little: those payments are already in the cash book, and only the statement can find them. The statement is needed under both.
- B can follow later, once staff record bank payments under a bank method. The two must never both post the same payment.

## 1.7 A day with the bank book (invented example, option A)

This day assumes D2 has shipped and the bank screen is switched on. Before that, nothing below is posted.

1. **09:10.** Customer A pays UGX 1,200,000 by Ecobank transfer for one of their invoices. Staff record it in uCRM.
   - **If they pick a bank method in uCRM,** the plugin's Expected list shows it at its next scheduled read: customer, amount, uCRM payment number. Nothing is written in the book [NEW].
   - **If they pick Cash** (likely today), or enter it in the staff app under any method, the book posts it as cash at once, as it does now. Cash in hand rises. This is Case 1.
2. **Friday.** You download the Ecobank UGX statement and upload it on the new Bank screen, choosing the account first.
   - The plugin refuses an incomplete file (a missing page, a running balance that does not add up, a gap in the line numbers) rather than reading part of it.
   - The lines go to a review area. **The upload writes nothing in the book** [M, F55].
3. **Matching.** The credit's reference carries the invoice number. The plugin suggests Customer A's payment and says why. A person confirms.
   - If it was on the Expected list, **one** entry is written on Ecobank UGX, carrying the customer and the uCRM payment number.
   - If it was posted as cash, the cash entry is moved into Ecobank UGX (Case 1).
   - Without a reference, several customers may fit. Then nothing is matched automatically. There is no "confirm all" [M, F55].
4. **A UGX 7,500 fee** on the same credit is written as *Bank Charges* and tagged *Customer A* exactly, because it sits on Customer A's payment [NEW].
5. **A credit with no uCRM payment.** Since bank payments are always in uCRM, it is probably cash you banked, Airtel money moved to the bank, or funding. Before anyone records a new uCRM payment for it, the plugin searches the cash receipts and the cash-banking entries, so the same money is not recorded twice [NEW].
6. **A debit**, say UGX 90,000 of fuel for an installation trip, is classified and tagged *Customer A*, or split between two customers served on the same trip by your rule (D16, the split rules) [NEW].
7. **An expected payment not seen on any statement after N days** (your number, D15) is flagged. Either the money never arrived, or it went to the other account [NEW].

Under **B**, step 1 would already write an entry on Ecobank UGX. **Until D2 ships, that entry would also move cash in hand.** So B too waits for D2.

## 1.8 What Customer A's page would show (invented)

*Customer A was given Kit 1.* Kit costs are shown **per kit**. Payments and the bank charges on them are shown **per customer**: if Customer A had two kits, the page would show one "paid" figure for both.

| | Where it comes from | The catch |
|---|---|---|
| **Paid to Starlink for Kit 1** | The price on the Starlink order line, plus a share of that order's shipping by your rule (D16) | The currency comes from the purchase record. If it cannot be found, the kit shows "uncosted — currency unknown", never a guess. If Kit 1 was lent, not sold, it shows as **our asset**, not Customer A's cost (D25, sold or lent) |
| **Monthly Starlink charges** | The Starlink invoices for Kit 1's Starlink account | Only if Kit 1's own Starlink account is recorded in the plugin. If no account is recorded, or several kits share one account, it shows "not attributable" (D26) |
| **Paid by Customer A** | uCRM's own payments for Customer A, minus refunds | Shown in parts: **cash**, **verified in the bank**, **expected**, **probably in the book, not confirmed**, **not found in the book**. Never added up as "paid" before it is verified. Commissions paid to the customer are shown apart from refunds. A payment or refund the plugin tied to Customer A by guessing is marked "customer not confirmed" |
| **Bank charges** | Fees on Customer A's own payments (exact once matched). Fees on our payment to Starlink for Kit 1 (by your split rule, if one payment covered several kits) | Only from statements that have been checked |
| **Transport, fuel, other** | Entries a person tagged for Customer A | Only what was tagged |
| **Not costed yet** | Technician time, cables and mounts, commission | Listed as missing, never treated as zero |

The bottom line reads **"paid less recorded costs"**, never "profit", until every kind of cost is covered. It will not equal the profit and loss report, and it says so.

## 1.9 Your voided entries

**What the record already shows.** The only voids written down in the repository are three hand-typed entries of 25 September in a technician's staff cashbook. **They were copies of two advances already in the main Cashbook; voiding them was recommended**, because counting both would double the money. The 5.18.70 records tool was then run to void them, and whether VOID was typed at its prompt is not recorded [M, F49]. The count R0 below can read whether that void took effect. They are staff cash records, not Cashbook entries. **Please do not re-enter them unless you now say that the copy finding was wrong.** Version 1 does not re-enter staff-record voids at all; if one ever must be, that is its own step. If the entries you mean are other ones, the list below finds them.

**The cut-off for the past (D3).** Before any repair, you choose how the bank's past is settled. There are two ways:
- **(a) one cut-off date.** From that date on, the bank account in the book matches the statement. Everything before it is closed with one adjustment entry, outside profit and loss. Then a voided bank receipt dated before the cut-off **stays voided**, and past bank income that was never booked **never reaches the profit and loss**.
- **(b) a rebuild.** The past months are checked statement by statement. Those months can then be repaired, at much more work.

**How the repair goes:**
1. **A count first (R0).** Read-only, prepared and rehearsed like P0, and run by you only. It prints counts and entry numbers only: no names, no descriptions, no amounts [NEW].
2. **The list in the plugin (R1).** Admins only, read-only. For each voided entry: its date, amount, what it was, how it was voided, and what evidence of the void exists. Entries that were deleted rather than voided are listed too, from the audit record. The name in a void stamp is shown "as typed", not as a proven identity. Possible duplicates are shown as suggestions, never as proof. If a checked bank statement shows the same money, the list shows that line [NEW].
   - The list comes after the first safety fixes, which stop people editing or deleting voided entries, close two hidden commands (below) and stop *Sync Now* with an old date.
   - It re-reads the book each time you open it. Until the later fixes ship, automatic jobs can still change or delete some voided entries, so the list can change between two visits. Each decision records what you saw.
   - **Please do not paste the list, or any bank-statement extract, into the chat, and never add them to the repository.** They show dates, amounts and names as typed. Describe what you see in words, or give entry numbers only.
3. **For each entry you choose** [NEW]:
   - *keep voided*; or
   - say where the money really is: **cash still held**, **cash that was banked**, **money that came into the bank**, or **Airtel Money**.

   uCRM's payment method is shown beside it **as a hint only**. On this install it does not show where the money went (§1.5). If a checked statement shows the money in the bank, "cash still held" is refused unless someone records why.
4. **Marked "keep voided (recommended)"** when a duplicate exists, when the void was a recorded deliberate act, when the entry is dated on or before the 12 Sep adjustment, or when it was voided before 4 October. You accepted the book as correct on 4 October, so earlier voids are already inside the figure you accepted [I]. **When the time of a void is not known, it counts as before 4 October.** These last cases can change only under D3, with the before and after shown.
5. **Never offered:**
   - "bring back exactly as it was" (un-void);
   - re-entering the **12 Sep adjustment** or any of the **earlier bank-import rows**. The list shows them, marked "D3 only, not repairable".
6. **When:** only after the safety fixes, after D3 is decided, and **after the bank statement for that month has been checked**. With a single cut-off, months before it are never checked statement by statement, so a cash entry dated before the cut-off stays voided, unless you record that the money was physical cash (the exception path in Part 2). A cash re-entry also needs a **physical cash count before and after**, recorded with the approval [NEW].
7. **A second person approves** (D5, the approval rule; proposed on 13 October and not yet decided). If you are alone, each act records that exception.
8. **What changes on screen:** a repair on its original date changes every Balance figure from that date to today, and that month's profit and loss. Before you approve, the screen shows each affected month before and after [M, F10; NEW]. **No period lock protects a cash repair:** the planned lock of checked months covers bank accounts only. That is why every approval carries this before and after.

**Why not simply "un-void"?** Nothing on screen offers it. But two hidden commands, *approve* and *reject*, could put a voided entry back into cash in hand. They make no check, keep no audit record, and report "failed" after they have succeeded, which invites a second try [M, F21]. A safety fix (X14) closes them.

**Why the automatic paths are not a safe way back.** Some are blocked by a voided entry. Others can post the same money again [M, F27]. Hence the two buttons in §1.2.

## 1.10 What changes for you and what does not

- **The new screens never change an old entry's amount, date or direction.** Moving an entry into a bank account (Case 1) is an audited exception you approve.
- **Existing code changes old entries today**: edits, deletions and re-stamps. The safety fixes restrict that before any repair (X8, X10, X14, X15 and X16). They do not stop it entirely: **an admin can still edit or delete a live entry that the new screens did not write**, and each such change is kept in the audit record.
- **Nothing is posted automatically**, neither from a statement nor, under A, from uCRM into a bank account.
- **South Sudan:** the new bank screens are Uganda only, behind a switch that is off by default. The safety fixes touch code both countries share, so whether they also apply to South Sudan is your decision (D22).

## 1.11 What you need to decide first

The full table is in §3.1. The first ones:
- whether bank money may be shown, on its own screen only (D1);
- what cash in hand counts, checked by a physical count (D2);
- the cut-off for the past, which also decides what repairs may do (D3, §1.9);
- A or B (D13);
- which uCRM methods staff use, and how Airtel Money is recorded (D14, D23);
- for costs: sold or lent kits, shared Starlink accounts, split rules (D25, D26, D16);
- which voids you mean, and who approves (D19, the repair scope; D5).

---

# Part 2 — Technical plan

## 2.1 What the code does today

### uCRM income → Cashbook

**Only three paths skip non-cash payments: the webhook, the gap filler and the evening pull (F1–F4).** The PWA API, field collection and the catch-up post a cash-stream receipt without checking the method (F6), and so does the pull's fallback (F43). The KYC cash sale is cash-only by construction (F6). The retry queue and auto-heal create uCRM payments but write no Cashbook row (F42).

- **F1 [M]: the webhook's rule.** `payment.add` posts only when `methodId === PaymentUuids::CASH`, or when the method id is empty and the name contains "cash" or is blank. Any other method is logged *"non-cash … not posted"* (`webhook.php:1188-1194`, `:1294-1296`).
- **F2 [M]: the webhook's row.** Source `crm_webhook`, category `Receipt`, `crm_payment_id` and `crm_client_id` set, **no `account_id`**, and **date = the processing day** `date('Y-m-d')`, not the uCRM date (`webhook.php:1258-1281`, `:1261`).
- **F3 [M]: the gap filler.** Every 300 s (`cron/master.php:184`), today's payments only, at most 50 (`cron_crm_payment_gap.php:53-54`). The same cash rule (`:80-88`). Dated by `createdDate` (`:138`).
- **F4 [M]: the evening pull** (`syncFromCrmApi`). It asks uCRM for `methodId=CASH` only (`lib/CashbookService.php:2421-2427`). Its bank branch never runs (`:2460-2482`); it would have used the capital category *Bank Transfer* (`:540-544`, `:2551-2558`). Its cut-off is the latest approved row's date (`:2360`). The Cashbook's *Sync Now* and the debug link also run it (F47).
- **F5 [M]: no database protection against double posting.**
  - Every new `CashbookService` drops every UNIQUE index on `cb_ledger` (`lib/CashbookService.php:1077-1090`).
  - Duplicates are prevented by SELECT-then-INSERT, outside any transaction.
  - **Several different checks, and some writers have none.** The webhook and gap filler share one (`webhook.php:1199-1207`, `cron_crm_payment_gap.php:91-97`). The pull checks `validation_ref` only, among four sources (`lib/CashbookService.php:2394`). Field collection has its own (`includes/post/post_field.php:1110-1113`). The old import has `refExists` (`lib/BankImport.php:310-315`, called at `:106-107`). The PWA API and the catch-up check uCRM for an existing payment before creating one (`createPaymentSafe`: `includes/api/api_retailer.php:156`; `cron/payment_catchup_sync.php:160`), **but their Cashbook inserts check nothing**. The retry queue and auto-heal write no Cashbook row at all (F42). The admin push is unreachable (F6).
  - `addEntryRaw` checks nothing itself; a fixed SR is not a key (`lib/CashbookService.php:1790-1845`).
- **F6 [M]: writers that post a uCRM-linked receipt with no method check.**
  - **PWA API (the staff app):** creates the uCRM payment with `PaymentUuids::resolve($method)` (`includes/api/api_retailer.php:135`), where the app offers Cash, Mobile Money and Bank (F44), then posts a cash-stream receipt unconditionally (`:182-208`). If the push succeeded, the row has sr `COL-{collection}`, ref `PAY-{payment}` and the payment id (`:188`, `:198`, `:202`). If it failed, the ref is `COL-{collection}` and `crm_payment_id` 0 (`addEntryRaw` casts the NULL, `lib/CashbookService.php:1830`).
  - **Field collection:** the same shape, with ref `COL-{collection}` and `crm_payment_id` NULL on failure (`includes/post/post_field.php:1106-1108`, insert `:1123-1145`).
  - **Admin push: unreachable as written.** Its handler is `tabs/admin/settings.php:211-275`, which `public.php` requires only when `tab=settings` (`public.php:2796`, `:3058`). The only form that posts `push_pending_to_crm` has no `action` attribute (`tabs/sales/all_collections.php:41-43`), so it posts back to `tab=all_collections`, where that handler is never loaded. Even when reached, `:214` requires `__DIR__.'/../lib/CashbookService.php'`, which resolves to `tabs/lib/`, a folder that does not exist: a fatal error before any uCRM post. It also ignores `csrfCheck()`'s result (`:213`).
  - **Catch-up:** picks collections on `crm_synced`, attempts, client and amount only, **never on status** (`cron/payment_catchup_sync.php:66-106`). It posts its own row, sr `COL-{collection}` with ref `PAY-{payment}`, with no Cashbook duplicate check (`:193-196`, `:210`). Its comment "idempotent via SR" (`:192`) is untrue (F5).
  - **KYC cash sale: cash-only by construction.** The Cashbook row is written only inside `if ($isCash && …)` (`lib/KycService.php:1120`, call at `:1165`), and the uCRM post requires `$isCash` too (`:957`). `$isCash` is `sales_type === 'Cash'` (`:184`, `:643`), so `resolve(sales_type)` at `:962` always yields Cash. Its row has **no `crm_payment_id`** and the ref `CRM#client` (`:1877-1895`).
- **F42 [M]: paths that create the uCRM payment but write no Cashbook row of their own.**
  - **Retry queue, with two consumers of `crm_payment_retry.json`.** `cron_sync.php:232-295`, every 60 s, uses a plain `post('payments')` (`:266`). `cron/crm_payment_retry.php`, every 300 s (`cron/master.php:181`), uses `createPaymentSafe` (`:84`). Both back-fill the collection's `crm_payment_id` in `payment_collections.json` only when the retry item carries a `collection_id` (`cron_sync.php:275-279`; `cron/crm_payment_retry.php:97-101`). The PWA's retry record has none (`includes/api/api_retailer.php:165-171`), so its collection never learns the payment id and is never marked synced. **Neither consumer writes the payment id onto the Cashbook row already posted.**
  - **Auto-heal**, every 60 s (`cron_sync.php:337-392`; `cron/master.php:167`), with a plain `post` (`:376`). No status check. For a collection with no client it **guesses the client by name**, with a score of at least 75 (`:334`, `:350`). It stamps `crm_auto_matched` on **every** collection it heals (`:380-385`), including Case A, where the staff-chosen client was already present (`:346-356`). The guessed case is told apart only by the `[auto-matched]` text in the uCRM payment's note (`:353`, `:368`). It writes the payment id into `payment_collections.json` only, never into the Cashbook row.
  - Auto-heal and `cron_sync.php`'s retry do not check uCRM first, so they, and the other consumer, can each fire for the same collection.
- **F43 [M]: the pull's local fallback.** When the API returns no cash payment since the cut-off (uCRM down, an empty page, or I5), it posts every collection dated on or after the cut-off as `COL-{id}`, source `crm_sync`, with no status or method check (`lib/CashbookService.php:2443`, `:2571-2600`). `COL-` never matches the `PAY-` refs that the PWA, field and catch-up writers use.
- **F7 [M]: uCRM deletions and edits.**
  - `payment.delete` proceeds only on a definite 404 from uCRM (`webhook.php:3535-3545`).
  - It finds the original by `crm_payment_id … direction='in' LIMIT 1`, with **no ORDER BY and no status filter** (`:3559-3562`).
  - It posts an OUT row, category `Refund` / `txn_type REFUND`, which `plByPeriod` treats as contra-revenue (`lib/CashbookService.php:764-772`); **no `account_id`**, `crm_client_id` 0, and no check for an existing `REV-CRM-PAY-{id}` (`webhook.php:3555-3592`).
  - It UPDATEs the original row's description and `validation_status`, with no FinAudit (`:3594-3600`).
  - `payment.edit` changes nothing in the Cashbook (`:3699-3746`).
- **F8 [M]: method ids are another instance's constants.** The bank methods are commented "Inactive / hidden from UI", and "no mobile money" exists (`lib/PaymentUuids.php:11-24`, `:36-45`). Custom method ids are generated per instance (`tools/dpo_payment_method.php:10-19`). SAFETY.md RULE 7 says "Mobile Money → Cash UUID" (`SAFETY.md:77-83`).
- **F44 [M]: which form offers which method.**
  - The office collection form offers only Cash (`tabs/sales/collect_payment.php:635-637`; `lib/PaymentUuids.php:121-124`).
  - **The staff app's collection form offers Cash, Mobile Money and Bank** (`retailer/index.html:1197-1199`). `resolve('Bank Transfer')` returns `BANK_TRANSFER` (`lib/PaymentUuids.php:85-91`), and the PWA API creates the uCRM payment under that id (`includes/api/api_retailer.php:135`) — if the constant exists on Uganda's uCRM at all (F8, I5) — and then posts a **cash-stream** Receipt anyway (`:182-208`). Auto-heal and the catch-up resolve the collection's stored method the same way (`cron_sync.php:365`; `cron/payment_catchup_sync.php:135`). So "a bank payment counted as cash in hand" is a code path read directly, not only an inference.
  - `resolve()` maps mobile money and unknown values to Cash (`:44-45`, `:92-100`).
  - The Cashbook refund handler posts refunds under Cash (`includes/post/post_cashbook.php:571`).
- **F45 [M]: what Uganda customers are told.** Since 25 September the payment text leads with the Airtel Money merchant, then the Ecobank transfer (`docs/07-change-control.md:351-369`; the text in `docs/31-airtel-money-merchant.md:125`). It asks customers to use *"your own name or your quotation number"* as the transfer reference. The website says the invoice number "is used as the payment reference" (`../dishnet-web-uganda/site/tutorials/view-pay-invoice.html:173`). A quotation carries `crm_client_id` (`lib/QuotationService.php:114`).
- **F46 [M]: refunds.** The Cashbook refund handler takes a client id from the first 1–6 digit number in the text (`includes/post/post_cashbook.php:549-561`), posts a uCRM refund (`:567-576`), and stores the refund's id in `crm_payment_id` on the OUT row (`:576-580`), a different id space. No refund webhook exists (`webhook.php` case list `:833-3806`).
- **F9 [M]: a read-only reader of every payment method already exists**: Collection Reconcile (F53).

### Balances

- **F10 [M]: cash in hand ignores accounts.** `cashInHand()` sums every row of the currency whose status is not `voided` or `voided_reconcile`. It has no account filter, so pending and rejected rows count (`lib/CashbookService.php:671-686`). The running balance and the CSV export use the same arithmetic, in date order (`:1950-2031`). It is shown on the Cashbook hero (`tabs/accounts/cashbook.php:952`), the landing dashboard (`tabs/accounts/accounts_dashboard.php:91-94`), the evening WhatsApp (`cron/cashbook_summary.php:69`) and by `tools/cash_in_hand.php:36-40`.
- **F11 [M]:** hand-typed entries cannot carry an account, a customer or a source: the `addEntry()` INSERT lacks them (`lib/CashbookService.php:1767-1785`).
- **F12 [M]:** `accountBalance()` counts approved rows only and takes no date (`:254-261`). `accountLedger()` exists (`:692-712`) but nothing calls it.
- **F13 [M]:** `assignAccount()` moves a row to an account with a FinAudit row and a currency check, and **refuses voided rows** (`:186-236`, refusal at `:205-209`).
- **F14 [M]:** `cb_accounts` holds id, name, currency, kind, active and timestamps (`:131-142`); the kinds are at `:120-129`; `MONEY_KINDS` (bank, cash, momo) at `:552`.

### Decisions on record

- **F15 [M]: the 4 October decision** was "cash in hand only" on Uganda: **display only, no data change** (`docs/07-change-control.md:3518-3527`). The 12 Sep adjustment and the earlier import's rows stay as they are, and `tools/bank_statement.php` *"should not be run again on Uganda"* (`:3580-3583`). The figure looked right because the adjustment cancels the account rows (`:3538-3541`).
- **F16: docs/68 is discovery only; only P0 is built** [M, `docs/68-bank-reconciliation-discovery-2026-10-13.md:3-9`].
  - **P0's gated run of 14 October ended with exit 7** [M, recorded from the terminal you pasted; the log file was not received, `docs/68:816-825`].
  - Exit 7 is the runner's (`../scripts/bank-recon-p0-run.sh:207-211`). The code gives it whenever the automatic rule does not find **exactly one** live, unassigned row dated 2026-09-12 whose description or category names an adjustment: zero candidates **or several** (`../scripts/bank-recon-p0.php:249-254`, the test `count($r) === 1` at `:253`).
  - **That there were zero comes from the record**, not from the code: the terminal you pasted, as recorded in `docs/68:823-824`.
  - Zero fits several causes equally [I]: the row was voided, assigned to an account, had its date or wording edited (`lib/CashbookService.php:1862`), or was hard-deleted (`:1904`).
  - The `--adjustment-id=N` form checks only the id, `account_id = 0` and a live status; otherwise it fails with exit 3 (`../scripts/bank-recon-p0.php:46`, `:245-247`, `$live` at `:211`). **A row whose date or wording was edited passes it.**
- **F17 [M]: docs/68 D4 is statement-first.** There is *"one writer only"* (`docs/68:441`), and `webhook.php` and the CRM sync are *"Not touched"* (`docs/68:368`).
- **F18 [M]: the old CLI still posts whole files on any tenant.** `tools/bank_statement.php --commit` has no tenant check (`:44`, `:54`, `:130`) and posts approved rows (`lib/BankImport.php:116-157`).

### Voids, approvals and edits

- **F19 [M]: `voidEntry()`** keeps the row and changes only status, the description stamp and `updated_at`. A single void writes a FinAudit `void` row with before and after; a pair void (`account_transfer`, `funding`, `fx_exchange`) writes none. The actor is recorded by name only (`lib/CashbookService.php:808-849`).
- **F20 [M]:** the Void button needs admin or accountant, plus `csrfCheck()` (`includes/post/post_cashbook.php:672-690`).
- **F21 [M]: approve and reject can each bring a voided row back.**
  - `approveEntry()` and `rejectEntry()` UPDATE the status with no status check and no audit (`lib/CashbookService.php:2322-2330`). `cashInHand()` counts `rejected` rows (F10), so **rejecting a voided row puts it back in cash in hand too**.
  - Both are reachable through the API by the `field_accountant` role (`includes/api/api_cashbook.php:29`, `:405-426`).
  - The callers read `success` while the methods return `ok`. So they report a failure after the UPDATE has run (`:411`, `:423`; `includes/post/post_cashbook.php:652`, `:667`). That invites a retry.
  - **No screen offers either on a voided row.** No form posts `cashbook_approve_entry` (`includes/post/post_cashbook.php:643`), and the retailer app approves from its pending list only (`retailer/index.html:2560-2578`). It takes a crafted API call.
  - `approveEntry` leaves `account_id` alone. That does not keep a reinstated row out of cash in hand: `cashInHand()` and the running-balance streams have no account filter (`lib/CashbookService.php:671-686`, `:1974-1988`; F10), so a row reinstated by approve or reject moves cash in hand whatever its account.
- **F22 [M]:** there is no un-void method. A grep for `unvoid`, `reinstate` and `restoreEntry` in `lib`, `includes`, `tabs`, `tools` and `webhook.php` found none.
- **F23 [M]: edit and hard delete are open to almost anyone.** `updateEntry` and `deleteEntry` have no status check, so voided rows can be changed or deleted (`lib/CashbookService.php:1847-1909`). Their handlers check only login and the form token (`includes/post/post_cashbook.php:1237-1301`). The token is an HMAC of the date alone (`public.php:90-114`) and is printed on the login page (`:2075`).
- **F24 [M]:** `settle_disb` checks login only, with no token and no role. `cb_reseed` checks login only, and bulk-deletes rows by source or SR pattern with no audit. A POST carrying `cb_action` skips the global token gate (`includes/post/post_cashbook.php:1217-1232`, `:1350-1375`; `includes/post_handlers.php:100-115`).
- **F25 [M]:** Staff Cashbooks, the records tool and `voided_reconcile` void with no FinAudit row and cascade into JSON stores (`tabs/accounts/staff_cashbooks.php:282-295`; `tools/staff_records_currency.php:131-169`; `includes/api/api_cron_debug.php:646-697`). `voided_reconcile` rows are hidden from the Cashbook list (`lib/CashbookService.php:2043`).
- **F26 [M]: FinAudit's limits.** `FinAudit::ACTIONS` is create, update, void and delete. Any other action, or any write error, is swallowed and returns 0 (`lib/FinAudit.php:27`, `:57-90`).
- **F27 [M]: which automatic paths a voided row blocks — restated.**
  - **Blocked:** the webhook and the gap filler. Their check matches any source and any status (`webhook.php:1199-1207`; `cron_crm_payment_gap.php:91-97`).
  - **Partly blocked:** the evening pull. Only rows of its four sources block it (`crm_sync`, `crm_api_sync`, `collect_payment`, `crm_webhook`), and only back to its cut-off (`lib/CashbookService.php:2394`, `:2360`). A voided row from `crm_gap_fill` (`cron_crm_payment_gap.php:151`), `catchup_sync` (`cron/payment_catchup_sync.php:216`) or `kyc_cash_sale` (`lib/KycService.php:1883`), or one whose `validation_ref` was edited, does not block it. *Sync Now* lets a user set the cut-off by hand (F47).
  - **Not blocked:** the pull's fallback (F43) and the catch-up, which ignore a collection's status (F6). Auto-heal and both retry consumers ignore it too: they create the uCRM payment for a voided collection, which the webhook may then post (F42, I7). The admin push would as well, but is unreachable (F6).
  - The old import's `refExists` matches voided rows (`lib/BankImport.php:310-315`).
- **F28 [M]: a precedent for correcting past rows.** `tools/uganda_capital_cash_rebook.php:1-41` checks the exact state, voids the wrong rows, posts new ones, runs dry by default and aborts on a second run.
- **F47 [M]: code that rewrites or deletes rows automatically, or for any user, without audit.**
  - Every pull hard-deletes `crm_api_sync` rows whose SR or ref matches any `crm_webhook` row, live or voided, and also `CRM-0` rows (`lib/CashbookService.php:2370-2385`). No FinAudit. If the webhook twin was voided, the live sync row is deleted and that money leaves the book without trace [I].
  - Every pull rewrites `validation_ref` on webhook rows (`:2387-2390`).
  - The `crm_sync` POST needs only a login: no `csrfCheck()`, no role, and a user-chosen `sync_from` (`includes/post/post_cashbook.php:1303-1311`). As a `cb_action` it skips the global gate. The session cookie is `SameSite=None` (`public.php:57-63`).
  - The Cashbook shows *Sync Now* with a free From date to every user who can open it and is not a field agent (`tabs/accounts/cashbook.php:1169-1186`). Beside it is a GET *Debug + Sync* link (`:1190`) that runs the sync with `&from=` (`includes/api/api_cron_debug.php:1179-1184`, `:1303-1307`); that route checks for admin, super_admin or accountant.
  - `cashbook_handover_reconcile` selects `status != 'voided_reconcile'`, so it re-stamps already-voided rows to `voided_reconcile` and hides them (`includes/api/api_cron_debug.php:657-663`, `:686-690`).
  - Staff Cashbooks rewrites an amount `WHERE status!='voided'`, which also hits `voided_reconcile` rows (`tabs/accounts/staff_cashbooks.php:332`).
- **F48 [M]: the evidence of a void is weaker than it looks.**
  - `updateEntry` can rewrite a voided row's description (`lib/CashbookService.php:1862`) through role-free handlers (`includes/post/post_cashbook.php:1253-1280`). So a stamp can be erased or forged by any logged-in user.
  - Staff Cashbooks and the records tool stamp `[VOIDED: reason]` on `cb_ledger` with no actor and no time (`tabs/accounts/staff_cashbooks.php:121`, `:295`; `tools/staff_records_currency.php:145`). The records tool's `$reason` (`:134`) does not contain its `$by` value (`:126`), which it writes only into `payment_collections.json` as `voided_by` and `audit_log` (`:137`, `:141`). The JSON stores those pages void do carry `voided_by` and `voided_at` (`tabs/accounts/staff_cashbooks.php:97`, `:104-110`, `:282-288`; `tools/staff_records_currency.php:136-141`, `:162-165`), and the staff ledger its own `voided_by` and `voided_at` (`lib/StaffLedgerService.php:262-276`).
  - `voidEntry`'s own stamp carries the actor's name and the minute (`lib/CashbookService.php:819-820`), for single and pair voids alike.
  - `voidEntry` passes the actor by name only, so `fin_audit.actor_id` is NULL (`lib/CashbookService.php:844-847`; `lib/FinAudit.php:76`). A pair void writes no FinAudit row (`:821-832`).
  - `activity_log` keeps the last 500 entries, with no actor id (`public.php:716-724`).
  - A hard-deleted row survives only as a FinAudit `delete` row (`lib/CashbookService.php:1904-1908`), or not at all after `cb_reseed` or the pull's DELETE.
- **F49 [M]: the recorded staff-record voids.**
  - docs/07 found three hand-typed 25 September cash-in records in a technician's staff cashbook to be copies, typed again by hand, of two advances already in the main Cashbook: *"counting both would double the money"*. Voiding them was the tool's default repair, and void or keep was left to the operator (`docs/07-change-control.md:3685-3690`, `:3896`). The finding was a copy found by mistake; only the void, if it was typed, is a deliberate act.
  - The 5.18.70 records tool was run with `--void`. The paste ended at its prompt, and whether VOID was typed is not known (`:3933-3939`, `:4056-4057`). docs/07 names the three cash-in records (`:3931-3933`); their ids are not repeated here.
  - The tool's cash-in branch voids `cash_ins.json` and the staff ledger only, not `cb_ledger` (`tools/staff_records_currency.php:157-169`; *"no ledger row to void"*, `docs/07-change-control.md:3939`). If VOID was typed, those three records carry `voided_by = 'staff_records_currency 5.18.70'` in `cash_ins.json` (`tools/staff_records_currency.php:164`), and their staff-ledger rows, keyed `CIN-<id>`, carry the same `voided_by` (`tools/staff_records_currency.php:187`; `lib/StaffLedgerWriter.php:479-483`). **So a read of those two stores answers "was VOID typed?" without asking you.**
  - No Cashbook ⋮ Void by the operator is recorded anywhere in the repository. docs/68 never mentions operator voids.
- **F50 [M]: pair writers.** `recordAccountTransfer` takes a source parameter (`lib/CashbookService.php:338-341`); `recordCashExchange` (`:405`) and `recordFunding` (`:451`) do not. The pair guards key on `account_transfer`, `funding` and `fx_exchange` (`:818`, `:1854`, `:1894`).

### Customers and costs

- **F29 [M]: customer links on ledger rows.** `cb_ledger.crm_client_id` exists (`lib/CashbookService.php:1045`). Only receipt writers set it (`webhook.php:1276`; `lib/CashbookService.php:2562`, `:2598`, `:2678`); the reversal writes 0 (`webhook.php:3585`). No reader groups ledger rows by customer.
- **F30 [M]: five loose matchers exist. None may be reused for bank lines.**
  - fuzzy name matching in field collection (`includes/post/post_field.php:642-679`);
  - auto-heal's `_cronAutoMatch` (`cron_sync.php:334-376`);
  - the refund digit regex, which also posts a uCRM refund (`includes/post/post_cashbook.php:541-580`);
  - the nearest-date "best fit" of the old import (`lib/BankImport.php:213-221`);
  - client + amount for the collector (`webhook.php:1242-1253`).
- **F31 [M]: the project's identity rules.** A customer is identified only by an exact match, and an ambiguous match is not an identification (`lib/CustomerIdentity.php:20-44`). Loose matching can *"identify the WRONG customer"* (`manifest.json:126`). docs/68's match tiers are suggestions a person confirms (`docs/68:237-245`).
- **F32 [M]: the statement parser keeps one description and one reference per line**: the first matching header in file order (`lib/BankStatement.php:38-45`, `:58-66`, `:88-97`). The Ecobank PDF has Remarks and three reference columns (`docs/68:185-187`), so the customer's reference may be dropped.
- **F33 [M]: kit cost — what is stored, and what is not.**
  - `stock_units` carries `purchase_cost`, `crm_client_id`, `job_id` and `starlink_account` (`migrations/036_stock_tables.sql:23-47`). **It has no currency column.**
  - The currency sits on the purchase header: `stock_purchases.currency` defaults to USD (`036:97-102`), and Starlink orders carry their own (`lib/StarlinkOrderImport.php:192`). The purchase-lines table `stock_purchase_items` has **no currency column** (`migrations/066_purchase_lines.sql:20-32`); `066:62` is the currency of **supplier payments**, `stock_purchase_payments` (table at `:57`).
  - **A unit reaches its purchase exactly through its inbound stock movement.** `createUnit()` logs an inbound `stock_movements` row with `reference_type` and `reference_id` (`lib/StockService.php:510-518`). `PurchaseService` sets `reference_type 'purchase'` and `reference_id` = the `stock_purchases` id (`lib/PurchaseService.php:280-281`). The Starlink order import sets the existing bill's id (`lib/StarlinkOrderImport.php:359-360`, `$existing` from `:234` and `:293`), which can be empty. The free-text `purchase_ref` (`lib/StockService.php:493`; `lib/StarlinkOrderImport.php:358`) is only the weaker fallback.
  - `ReportingService` sums `purchase_cost` under the book currency without checking, and calls installed units *"ours, not on the shelf"*, that is, an asset (`lib/ReportingService.php:256-268`).
  - `equipment_assignments`: one live assignment per unit and per service. A unit can be released and reassigned any number of times; `replaced_by_unit_id` exists; nothing can be deleted; released rows are fixed (`migrations/068_equipment_assignments.sql:19-34`, `:59`, `:66-67`).
  - Installation refuses client 0 (`lib/StockService.php:892-958`).
- **F34 [M]:** a Starlink kit's unit cost is its order line price; shipping stays on the bill (`lib/StarlinkOrderImport.php:353-360`).
- **F35 [M]: other costs carry no customer or no cost.** Consumables are moved per job with no cost captured (`lib/StockService.php:1376-1440`; `migrations/036_stock_tables.sql:69-87`). Staff expenses have no customer or job column (`migrations/005_cash_advances.sql:62-104`). The jobs cache stamps client 0 (`cron/jobs_cache.php:61`).
- **F36 [M]:** `install_auth.price_snapshot` is the price quoted to the customer, not our cost (`migrations/086_install_authorisation.sql:16-24`, `:47`).
- **F37 [M]: `ReportingService`'s rules.** A figure carries a basis, a completeness flag and a caveat; it is one currency, never summed across currencies; uCRM owns sales and payments; `grossMargin()` is unavailable (`lib/ReportingService.php:24-44`, `:281-301`).
- **F38 [M]: Starlink invoices.** Lines keep no service-line id (`lib/StarlinkInvoiceImport.php:164-188`, `:280-327`). **But each invoice is booked per Starlink account**: its `supplier_ref` is the *"Customer Account: ACC-…"* (`:116`, `:318`). `equipment_assignments` **may** carry `starlink_account` and `starlink_service_line` for a live kit and its client. Both are `TEXT NOT NULL DEFAULT ''`, commented *"an identifier we do not have is empty"* (`migrations/068_equipment_assignments.sql:44-46`). No live-unique index covers `starlink_account`: the live indexes are unit, service, kit, terminal, router and service line (`:66-85`). An empty account must never be joined, and two live kits may hold the same account.
- **F39 [M]:** the earlier import booked kit and card purchases to inventory or suspense, and nothing moves them into the P&L (`lib/BankImport.php:12-21`, `:134-157`; `docs/68:383`).
- **F51 [M]: the same money sits under several sources.**
  - A staff expense and its ledger row, linked by `staff_expenses.cashbook_entry_id` (`migrations/005_cash_advances.sql:94-96`).
  - A module-posted debit is both a statement line and a ledger row.
  - A kit card purchase that the matcher links to a purchase (`lib/BankImport.php:134-152`, `:335-375`) is the same money as that unit's `purchase_cost`.
  - A Starlink invoice is booked as a `stock_purchases` bill (`lib/StarlinkInvoiceImport.php:312-318`), and the bank debit that pays it is the same money. Their link, where one exists, is `stock_purchase_payments.cb_ledger_id` (`migrations/066_purchase_lines.sql:65`), written by the old import's matcher (`lib/BankImport.php:385`) or when a supplier payment is recorded (`lib/PurchaseService.php:366-375`).
  - `payment_collections.commission` (`includes/post/post_field.php:1012-1031`) beside *Commission* ledger rows.
  - A *Customer Commission* OUT row and the uCRM refund the Cashbook handler posts for it: the handler posts a uCRM refund for *Customer Refund* and *Customer Commission* alike (`includes/post/post_cashbook.php:541`, `:567-576`). *Customer Commission* is an expense category (`lib/CashbookService.php:28-35`); `plByPeriod` treats only *Refund* and *Customer Refund* as refunds (`:764-765`).
  - REFUND rows are contra-revenue (`lib/CashbookService.php:761-772`); *Staff Advance* is a capital category (`:543`).
- **F52 [M]: `CustomerAccountService` already assembles a customer.** `equipment()` returns `purchase_cost` to staff, and `forCustomer()` strips it (`lib/CustomerAccountService.php:19-26`, `:390-430`). `payments()` reads only payments attached to invoices, from the per-invoice cache (`:273-300`). A KYC payment posted as credit with no invoice is therefore missing (`lib/KycService.php:967`).
- **F53 [M]: Collection Reconcile.** Menu *Accounts → Reconcile*, page title *Collection Reconcile* (`tabs/accounts/collection_reconcile.php:217`); admins and accountants (`:13-16`; `public.php:2728`, `:2855`); shown on both tenants (`includes/navigation.php:314`). A read-only GET that fetches every uCRM method for a date range, matches by `PAY-`/`CRM-` refs and totals per method (`:76-140`). Caveats:
  - no status filter on Cashbook rows, so voided rows count as "in cashbook" (`:44-51`);
  - it matches only a `PAY-{id}` ref or a `CRM-{id}` SR (`:53-61`, `:125-127`), so a payment whose Cashbook row is a `COL-` row (push failure, F6) or a KYC `CRM#client` row shows as missing although it is in the book;
  - it stops at the first empty or failed page, so a partial list looks complete (`:90`);
  - it stops at 5,000 without a flag (`:94`);
  - it sums amounts across currencies, in the top total (`:117`) and in each per-method total (`:122`), and prints every amount with the configured symbol (`dn_cur()`, `lib/currency.php:19-24`; e.g. `:290`, `:315`), whatever the payment's own currency;
  - it compares with uCRM's `createdDate`, while the webhook dates by processing day (`webhook.php:1261`).
- **F55 [M]: docs/68's statement path.** An upload writes nothing in the book; lines go to a review area (`docs/68:216-221`). Matching tiers are suggestions with a stored reason, never automatic, with no "confirm all" (`docs/68:237-245`).

### Infrastructure

- **F40 [M]: migrations** end at `087_wa_channels.sql`, so 088 is the next free number. The MigrationRunner:
  - marks a file applied even when a statement failed (`docs/68:158-159`);
  - treats `UNIQUE constraint` and `NOT NULL constraint` errors as safe, records nothing, and logs the file OK, not even PARTIAL (`lib/MigrationRunner.php:226-238`, `:243-251`);
  - closes a trigger body at the first `END`, so a `CASE … END` inside a trigger breaks it (`:114-121`).
- **F41 [M]:** the Uganda gate is `StaffJobsGate::applies()` (`lib/StaffJobsGate.php:19-35`).
- **F54 [M]: the SAFETY.md rules this work touches.** RULE 7: `resolve()` is the only method lookup, and mobile money maps to Cash (`SAFETY.md:77-83`). RULE 11b: a scheduled script never calls `exit()` (`:118-136`). RULE 12: the duplicate check is `validation_ref` only (`:138-142`).

### Inferred from the above

- **I1 [I]: double posts, by family.** The code paths were read; nothing was measured on data.
  - **(a) Push failure.** The PWA or field collection posts `COL-x` with no payment id (F6). A retry consumer or auto-heal later creates the uCRM payment `y`. It writes `y` into `payment_collections.json` at most — for the PWA's retry items, which carry no collection id, not even there — and never into the `COL-x` row (F42). If its method is Cash, the webhook then posts `PAY-y` beside `COL-x`, because its check knows only `PAY-` refs and `crm_payment_id` (`webhook.php:1199-1207`).
  - **(b) Catch-up.** It posts its own row, sr `COL-x` with ref `PAY-y`, beside the earlier `COL-x` row (F6). Its insert is unconditional once it reaches the collection. Whether it reaches it first depends on which of auto-heal, the two retry consumers and the catch-up succeeds first. The admin push would do the same, but is unreachable (F6).
  - **(c) The pull's fallback** re-posts collections as `COL-{id}`, a ref that never matches `PAY-` rows (F43).
  - **(d)** Gap-filled and catch-up receipts are outside the pull's set, so the pull can post them again (F27).
  - **(e)** A KYC row has no payment id, so the webhook can post a second receipt beside it (F6).
  - **(f)** The two retry consumers and auto-heal can each fire for one collection and create **two uCRM payments**. uCRM's own income for that customer is then doubled too (F42). One consumer checks uCRM first (`createPaymentSafe`); whether that check catches a payment auto-heal created first is not established.
- **I2 [I]:** deleting in uCRM a payment whose Cashbook receipt was voided still posts a reversal, because the lookup has no status filter (F7). Unmatched reversals may sit in the book.
- **I3 [I]:** a future bank receipt carrying a payment id would be found by the same deletion lookup and reversed onto the cash stream (F7).
- **I4 [I]:** under today's arithmetic, any bank posting moves cash in hand by its full amount, on every screen in F10.
- **I5 [I]:** if Uganda's Cash method id differs from the constant, the cash paths post a cash payment only when its method id is empty. Not established.
- **I6 [I]: most non-cash income is probably in the cash stream.** The office form records Cash; the staff app records mobile money as Cash and posts a cash-stream receipt even for Bank (F44, F6); mobile money maps to Cash (F8); customers are told to pay by Airtel Money first and Ecobank second (F45); and you say bank payments are always recorded in uCRM. So bank and Airtel money count as cash in hand, whether uCRM recorded them as Cash or, from the staff app, as Bank.
- **I7 [I]: a voided collection can come back.** A collection voided in Staff Cashbooks before it reached uCRM can still be pushed by the catch-up, auto-heal or either retry consumer, none of which checks the collection's status (F6, F42). The result is a real uCRM payment, so the customer's invoice shows paid, and a fresh cash receipt, from the catch-up directly or from the webhook.
- **I8 [I]: *Sync Now* with an old From date** re-posts voided receipts from outside its four sources, and not others. After R2 it would post a third copy of each repaired receipt, unless X11 widens its check (F27, F47).
- **I9 [I]: some of the entries you voided may have been correct removals of I1 duplicates.** Re-entering them would count the money twice.

## 2.2 D13 — who writes a customer bank receipt

§1.6 gives the summary. The technical costs of each:

**A — statement first, plus an Expected list.**
- The ledger row appears when a person confirms a statement credit against a uCRM payment (docs/68 D4, F17).
- Bank income is absent from `plByPeriod`, which counts approved rows only (`lib/CashbookService.php:739-741`), until a statement is uploaded and matched.
- The per-customer view (read from uCRM) and the P&L disagree until then.
- The Expected list covers only payments under a mapped bank, card or cheque method. If D14 shows that staff record bank payments under Cash, the list is nearly empty and is not worth building in that form [I].
- It still needs X10 and X11 on existing code (§2.4), because the existing writers never read the module's tables (point C below). X11 needs the RULE 12 amendment **A12** under A too. If the Expected list is built, its method map needs the RULE 7 amendment **A7**.

**B — uCRM first (the literal request).**
- A ledger row on the mapped bank account when uCRM records a bank-method payment.
- "Unverified" must be a marker in a module table, never a ledger status: `cashInHand()` counts every status except voided (F10), and `plByPeriod` counts approved rows only.
- It needs: one shared, Uganda-gated method classifier in place of the three inline rules (F1, F3, F4); writers that set the account, `txn_type PAYMENT`, category `Receipt` and the uCRM date; a visible hold for a currency mismatch (today `addEntryRaw` throws and the webhook swallows it into a log line, `webhook.php:1287-1290`); the pull's Cash-only filter dropped for mapped methods; a deletion that reverses onto the same account; edits handled; a tenant gate on the webhook; both the RULE 7 and the RULE 12 amendments (A7, A12), always.
- Unmatched rows at month end are deposits in transit, as docs/68 E8 already explains (`docs/68:288-291`).
- **It sees nothing of Case 1.** Payments recorded as Cash still need the statement side.
- D2 must ship before anything (I4).

**C — what both need.**
- **They must never both post the same payment.** If B is chosen, the statement side only links; it never posts a customer receipt.
- **A database-enforced rule across every writer is impossible today.** `cb_ledger` cannot hold a UNIQUE key (F5), and the existing writers do not read the module's tables. So "one active posting per uCRM payment id" is **checked, not enforced**, unless D27 goes further.
- X11 makes every uCRM-receipt writer check `crm_payment_id` (IN rows, outside the reversal and refund sources) across all sources and statuses, plus the module's link table, **and the payment's collection** (its `COL-` row). That changes the webhook, the gap filler, the pull and its fallback, the catch-up, the PWA API, field collection, auto-heal and both retry consumers. The admin push changes only if it is ever repaired (F6). **It is not "one small change".**
- A method edited in uCRM from bank to Cash after the module posted the payment: `payment.edit` changes nothing (F7), and today the pull would post it again (F27). After X11 it cannot. The Expected job re-reads that payment by its id, marks the row *Changed in uCRM* and flags the posting for a person (§2.3.3).

**Recommendation: A**, for the reasons in §1.6, with the costs above stated. B may follow once D14 shows bank methods used reliably.

## 2.3 Components

### 2.3.1 Bank accounts, statement upload, staging, matching, review, report

docs/68 §E1–E10 as written [M, `docs/68:196-336`]. Additions [NEW]:
- **Accounts:** Ecobank UGX and Ecobank USD only (your answer 4).
- **The parser keeps every reference column and Remarks** (F32), in restricted staging.
- **A match tier for an exact invoice or quotation number** found in any reference or Remarks column → a candidate client. A suggestion a person confirms; never automatic. A customer's own name is never an identification (F31).
- **Refund debits** are matched to uCRM refund records, which carry a `clientId`, the way credits are matched to payments. The digit regex is never used (F46). **But every refund the Cashbook handler created took its `clientId` from that regex** (`includes/post/post_cashbook.php:549-561`, used at `:570`). Until a person confirms it, such a refund's client is *customer not confirmed*, in matching and in the per-customer view. It is recognised by an OUT ledger row whose `crm_payment_id` is the refund id (`:577-580`), or by *"CB ref:"* in the refund's note (`:574`). X13 fixes this for future refunds only.
- **Cross-currency receipts** (a UGX invoice paid into the USD account, or the reverse; DPO books in the invoice currency, `lib/DpoPaymentService.php:396-400`): the receipt is booked in the bank's currency at the bank's amount. The uCRM amount and a rate source are kept in the module's table for the per-customer view. Anything unclear is held for a person, never refused silently. The book has no FX gain or loss line (`docs/68:118-123`).

### 2.3.2 Payment-method map [NEW]

- **`bank_method_map`** maps each live uCRM method id to a kind (bank, card, cheque, cash, momo, ignore).
- **For bank, card and cheque it maps each pair (method id, payment `currencyCode`) to one `cb_accounts` id of that currency.** A uCRM method has no currency of its own, so one bank method can then send UGX payments to Ecobank UGX and USD payments to Ecobank USD (your answer 4). A pair with no mapped account goes to the "not mapped" bucket.
- **Under A the account comes from the statement**: the upload names its account, and a receipt is posted only on that account. The map gives only the payment's kind and which statement it is expected on.
- **It is filled from the live uCRM method list**, read-only, on an admin screen (F8).
- **It never goes through `PaymentUuids::resolve()`**, which falls back to Cash (F44). It is a second lookup path, so it needs the **RULE 7 amendment A7** (§2.4).
- **An unmapped method or pair** puts its payments in a visible "method not mapped" bucket. Never Cash.
- **momo is not statement-matched** until a wallet statement source exists (D23).
- It is used only by the bank module. Under A the existing cash rule (F1–F4) is unchanged.
- Side benefit: reading the live list shows whether I5 holds.

### 2.3.3 Expected receipts [NEW] — only if D14 shows bank methods in use

- **A persisted extension of Collection Reconcile** (F53), not a new reader. The table `bank_expected_receipts` holds one row per uCRM payment of a mapped bank, card or cheque method, with a **UNIQUE uCRM payment id** and a snapshot of client, amount, currency, `createdDate` and method.
- **Reading rules**, fixing what Collection Reconcile does (F53):
  - a failed or empty page marks the read **incomplete**, and an incomplete read never feeds *Stale* or *Withdrawn*;
  - a cap is shown as a flag;
  - totals stay per currency.
- **Schedule:** a job registered in `cron/master.php`, Uganda-gated (it belongs to the new bank module) and flag-gated, ending with `return;`, never `exit()` (RULE 11b). **Its interval is set at build and is not decided here.** Plus a *refresh now* button: POST only, with `csrfCheck()` and `crossSite()` (docs/68 E9). Never a GET.
- **Two reads per run.** A list read by date finds new payments of mapped methods. Then **each open or posted row is re-read by its payment id** (`payments/{id}`), because a list filtered by method cannot see a payment whose method has moved to Cash.
- **States:**
  - *Expected* → *Partially matched*, with the remaining amount → *Matched* → *Verified*, when the posting is written;
  - *Withdrawn* only on a **definite 404** to the read by id, the webhook's 5.18.37 rule (`webhook.php:3535-3545`); any other answer is *could not check*;
  - *Stale*, after D15's number of days;
  - *Changed in uCRM*: the amount, currency or method moved, both values kept as an event. **A change from bank to Cash is *Changed in uCRM*, never *Withdrawn*:** it flags the row and any posting for a person.
- **Cheques:** a bounce (a credit, then a debit) flags the uCRM payment for deletion (D21).
- **Card (DPO):** one settlement credit may cover several payments plus a fee (`docs/68:228-230`). The list groups them for a person to confirm.
- **At confirmation the payment is re-read from uCRM**, as the webhook does for `payment.add` (`whVerified` + `whFetchFirst`, `webhook.php:1165`). A stale snapshot is never posted.
- **The posted row** (under A) follows docs/68 E6: `addEntryRaw` on the mapped account, `txn_type PAYMENT`, category `Receipt` (not the capital *Bank Transfer*, F4), date = the bank's posting date, `crm_payment_id` and `crm_client_id` set. It is written in one transaction with the line's status and the link.
- **Refusals before posting:**
  - another active link holds the same uCRM payment id;
  - a **live** row has `crm_payment_id = ? AND direction = 'in'`, outside the reversal and refund sources (F7, F46). "Live" means status not `voided` or `voided_reconcile`, matching `cashInHand()`. It goes to a person;
  - a **voided** row with that id needs a recorded decision. It is never ignored;
  - a **null-id twin**: the payment's collection in `payment_collections.json` (by `crm_payment_id`) and its `COL-` row (sr or ref, any status); or any row with the same `crm_client_id` and amount within a date window, in any source or status; or, for a row with no client id (hand-typed rows cannot carry one, F11), the same amount, direction and currency within the window. It is held for a person. That covers KYC rows, push-failure rows and hand re-entries.

### 2.3.4 Case 1 — paid by bank, recorded as Cash [NEW]

- It is found only from the statement side (`docs/68:238-248`): a credit is matched against approved rows on every money account and unassigned rows.
- **The E5 move** (`assignAccount`, audited, docs/68 §M exception 3) is offered **only when exactly one live IN row exists for the payment and no twin is found** (the checks of §2.3.3). Otherwise a person decides. A staff-app receipt recorded under *Bank* is the same case: its row carries the payment id when the push succeeded (F6), and it sits on the cash stream like the rest.
- **Only after D2.** Before D2, `cashInHand()` ignores the account (F10), so the move corrects nothing.
- **If the cash row was voided**, `assignAccount` refuses it (F13). The repair writes a new bank receipt from the statement line, linked "replaces voided #N" (R3).
- Staff may correct the method in uCRM by hand afterwards. `payment.edit` changes nothing in the book (F7). After X11 the pull cannot re-post it.
- **A credit with no uCRM payment:** before anyone records one in uCRM, the module searches the cash receipts and the cash-banking (*Bank Transfer*) rows. Cash that staff banked is only the **missing bank leg**, a `TRANSFER`, written after D2 (`docs/68:249-252`). Recording it as a new uCRM payment would double uCRM income, and the webhook would post a second cash receipt [I].

### 2.3.5 Case 2 — Airtel Money [NEW]

- Most non-cash income is probably Airtel Money recorded as Cash (I6).
- A wallet sweep to Ecobank is a `TRANSFER` (wallet → bank), never income, and only after D2.
- **D23** chooses:
  - (a) an Airtel account of kind `momo`, with its own statement or export reader, once a sample exists; or
  - (b) excluded for now, stated on the Bank screen, inflating cash in hand until reclassified.
- Until (a) exists, momo receipts are never matched to an Ecobank credit as if they were customer payments.

### 2.3.6 Costs per customer [NEW]

**One canonical source per money movement.**
- A cost tag attaches to a **posted `cb_ledger` row** only. A statement line is resolved to the row it was posted as; a staff expense to its `cashbook_entry_id` row (F51). If nothing is posted yet, the tag waits.
- A trigger that follows these links refuses a second tag for the same money.
- **Refused as sources:**
  - transfer legs and pair sources;
  - capital categories and `txn_type`s, *Staff Advance* included (`lib/CashbookService.php:540-547`);
  - inventory and suspense rows;
  - rows linked to a stock purchase (kit cost and the subscription are derived, below). **"Linked" means any of:** the old import's matcher link; `stock_purchase_payments.cb_ledger_id` pointing at the row (F51); a module link; or a debit a person has classified as a Starlink payment;
  - REFUND rows (they are netted on the income side);
  - **OUT rows whose `crm_payment_id` holds a uCRM refund id**, *Customer Refund* and *Customer Commission* alike (F46, F51). The per-customer view nets those refunds, so tagging the row as a cost would count the same money twice;
  - voided rows;
  - *Commission* rows where the collection's own commission is used (one of the two, never both).

**The table `cost_attributions`**, append-only, following migrations 068 and 079:
- **Columns:** the canonical `cb_ledger` row id; a **snapshot and hash of that row** at tagging time; `crm_client_id NOT NULL`; optional `crm_service_id` and uCRM `job_id`; `amount`; `currency` (must equal the row's); `basis` (direct or split); `evidence`; `assigned_by_id` (a retailer id re-read on the server); `assigned_at`; `active`; `superseded_by`; `reason`.
- **Triggers** refuse DELETE and allow only two UPDATEs: `active` from 1 to 0, and setting `superseded_by` on a live row.
- **Rules:** a partial UNIQUE on (row, client) among active rows; shares may not add up to more than the row's amount.
- **No foreign key to `cb_ledger`**, because its rows can be hard-deleted. The view flags a source row that changed or vanished, as docs/68 F does for its links (`docs/68:352-354`).
- **Tagging rows the module did not post waits for X8.** Anyone can edit or delete them today (F23).

**The customer always comes from** a uCRM client a person chose, a uCRM job's own client id, or `install_auth.crm_client_id`. Never from text (F30–F31).

**The three figures you asked for:**
1. **Paid to Starlink.**
   - **Kit:** `stock_units.purchase_cost`, **exact in amount, currency unconfirmed**. The currency comes first from the unit's inbound `stock_movements` row: `reference_type = 'purchase'` and a non-empty `reference_id` → that `stock_purchases.currency`. Only when that link is empty, from an exact match of `purchase_ref` to one purchase or order. When both are missing or ambiguous, the unit is *uncosted — currency unknown*, never defaulted to the book currency (F33).
   - **Sold or lent (D25):** sold → that customer's cost of sale; lent or rented → our asset, shown apart, not their cost.
   - **Reassigned or replaced kits:** a unit's cost is attributed **once**, to the sale or to the current live assignment, per D25. Never to every holder.
   - **Shipping:** it stays on the order's bill (F34) and is split across that order's kits by your rule (D16).
   - **Monthly subscription (D26):** if each kit has its own Starlink account, the invoice's account (`supplier_ref`) joins the assignment that was live on that account at the invoice date (F38). **Only where exactly one such assignment holds that non-empty account** → that customer. An empty account never joins, and empty strings are never compared. Anything else is *not attributable*. R0 counts the live assignments whose `starlink_account` is empty.
2. **Paid by the customer:** §2.3.7.
3. **Bank charges:**
   - a fee on a credit linked to one customer's payment → that customer, exactly;
   - a fee on the debit that paid a Starlink order or invoice → that order's kits, or that invoice's account, by your rule (D16). The debit itself is linked to the purchase (as defined above) and is refused as a cost source; only its separate *Bank Charges* line is tagged.

**Not attributable from today's data** [M, F35, F39]: consumables (no cost on the movement), labour, commission per customer (the collection's client may come from a fuzzy match), purchases parked in inventory or suspense, and subscriptions on shared Starlink accounts.

### 2.3.7 Per-customer view [NEW, read-only]

- **Built on `CustomerAccountService`** (F52). Its `equipment()` already gives staff the kit and its cost; `forCustomer()` keeps stripping the cost.
- **Income from uCRM's payments read by client**, not from the invoice cache, which misses KYC credits (F52). **uCRM refunds are netted, with commissions shown apart from refunds** (the handler posts both as uCRM refunds, F51). Refunds the Cashbook handler created are *customer not confirmed* until a person confirms them (§2.3.1, F46).
- **Income and bank charges are per customer, not per kit.** Per kit would need payment → invoice → service → live assignment (`equipment_assignments.crm_service_id`, one live kit per service, `migrations/068_equipment_assignments.sql:42`, `:71-72`). Nothing in the repository reads an invoice's service today, so that is a later option under D17 [I]. Until then a customer with two kits gets one income figure; credits with no invoice could never be split per kit anyway.
- Each payment carries *landed in the book as*: cash row, verified bank, expected, voided, **probably in the book as …, not confirmed**, or not found. It is resolved in this order: a row with that `crm_payment_id`; else the collection whose `crm_payment_id` is the payment (`payment_collections.json`) → its `COL-{id}` row; else, for a KYC credit, a `CRM#client` row with the same client and amount within the window (KYC rows have no payment id, `lib/KycService.php:1877-1895`). The last two are labelled *probably, not confirmed*. **Cash, verified and expected are shown apart**, never summed into one "paid".
- **"Customer not confirmed"** marks: payments whose uCRM note carries auto-heal's `[auto-matched]` text (F42); field collections with `crm_auto_matched` (field collection sets it only when it matched by name, `includes/post/post_field.php:689-695`, `:1031`; F30); and the handler-created refunds above. `crm_auto_matched` alone is **not** used for auto-heal, which sets it on every collection it heals. The client is never called exact where the plugin guessed it.
- **Why not from `cb_ledger` rows:** reversals carry client 0 (F29), KYC rows lack a payment id (F6), duplicates are probable (I1), and voided rows still carry their ids (F27).
- **Costs:** kit (D25), subscription (D26), bank charges, active tags.
- **Every figure follows `ReportingService::figure()`**: one currency, a basis, `complete`, and a caveat (F37). The label is *paid less recorded costs*. **The page states that it will not equal the P&L.**
- **Its own permission**, *cost.view*, admin only at first (D20).

### 2.3.8 Repairing the voided past [NEW]

**R0 — a read-only census**, built, rehearsed and **run by you only**, with P0's gates and masking (`docs/68:507-548`). It prints counts and ids only: no names, descriptions or amounts. Its output never enters the repository or the chat unmasked. It reads `cb_ledger`, `fin_audit`, `payment_collections.json`, `cash_ins.json`, `cash_expenses.json`, `staff_ledger` and `equipment_assignments`, read-only. It reports:
- voided rows by source, by path (single Cashbook void with FinAudit; pair void; Staff Cashbooks or tool; `voided_reconcile`) and by month;
- each voided row's **void time**, from the first source that has one: `voidEntry`'s stamp, which carries the minute (F48); `fin_audit.created_at`; or the `voided_at` / `audit_log` of the JSON record that carries the same `COL-`, `PAY-` or `EXP-` ref (F48). It is compared with the 12 Sep adjustment's creation and with 4 October. **A void with no time from any source is reported as "time unknown" and treated as before 4 October**;
- **the evidence kind** per row: a FinAudit void `before_json`, the stamp's form, or none; and FinAudit `update` rows dated after each void (F48);
- FinAudit `delete` rows, including deletions of voided rows: entries that may have been hard-deleted rather than voided;
- **twins, as candidates only, never proof:**
  - the same `crm_payment_id`, IN, outside the reversal and refund sources;
  - the same `validation_ref`;
  - `COL-`/`PAY-` pairs, through the collection's `crm_payment_id`, or through the retry item that created the payment (F42);
  - client + amount + direction within a date window, across all sources (twins of one payment carry different dates, F2, F3);
  - **for rows with no client id** (hand-typed rows cannot carry one, F11): amount + direction + currency within the window, plus a description-text candidate computed inside R0. The text is compared, never printed: the output stays masked;
  - a many-to-one sum for the same client or person within the window;
  - KYC rows by `crm_client_id` against `PAY-` rows;
- each I1 family, counted;
- Receipt rows whose collection's method is not Cash. **The uCRM-method half of this count is not in R0** (§2.8): R0 stays local and never calls uCRM;
- reversal rows whose original is voided (I2);
- **rows dated 2026-09-12:** status, account, and every FinAudit update, delete or void record. That covers every cause of exit 7's zero, not only "voided" (F16);
- **candidates for the 12 Sep adjustment, on any date:** every row, voided or not, on an account or not, whose direction and amount equal the recorded adjustment's (`docs/07-change-control.md:3540`). For each: its id, date, status and account kind only. The Cashbook screen shows the SR No., not this id, so this list is how the id for P0's second run is found;
- **deliberate voids:**
  - on `cb_ledger`, by the records tool's own reason text: the stamp contains *"by the Manual Entry form"* and ends *"(5.18.70)]"* (`tools/staff_records_currency.php:134`, `:145`). The tool's `$by` value never reaches `cb_ledger` (F48);
  - cross-checked against `payment_collections.json`: `voided_by = 'staff_records_currency 5.18.70'` on the collection with the same `COL-`/`PAY-` ref (`tools/staff_records_currency.php:137`);
  - **for the three recorded cash-ins (F49): their status and `voided_by` in `cash_ins.json`, and their `CIN-<id>` rows in `staff_ledger`.** This answers whether VOID was typed at the 5.18.70 prompt;
- live `equipment_assignments` rows whose `starlink_account` is empty (D26, F38).

R0 is a snapshot. Until X15b ships, every pull can still delete and rewrite rows (F47), so R1 re-reads the book rather than trusting R0's ids.

**R1 — the list in the plugin.** Admin only, read-only. It reads `cb_ledger` and `fin_audit` directly, because `getEntries()` hides `voided_reconcile` (F25). It lists three kinds:
- **voided rows still in `cb_ledger`**;
- **hard-deleted rows**, from FinAudit `delete` rows, as their own kind: the deleted row exists only as that `before_json` (F48);
- **the 12 Sep adjustment, every `bank_import` row and both legs of each import transfer, read-only, labelled "D3 only, not repairable".** They are shown, so that nobody looks for them elsewhere, and no decision can be recorded on them.

Each row shows:
- its void stamp, labelled *"name as typed, not an identity"*, and its void time or *time unknown*;
- its FinAudit `before_json`, if any, and any post-void edits;
- its twins, as suggestions;
- the other leg of a pair or of an import transfer (docs/68 X2);
- **any statement line linked or suggested for the row's `crm_payment_id`, or for its client + amount within the window**, once statements exist (P3c);
- the deliberate-void tag, uCRM's method as a hint, and R0's flags.

**R1 waits for P3a: X8, X9, X14 and X15a.** It re-reads the book on every visit. Until X15b and X16 ship, automatic jobs can still change or delete some voided rows (F47), so a decision records the row's hash as shown (R2).

**R1 screens, R0 output and any statement extract never enter the chat or the repository unmasked.** R1 shows dates, amounts and void stamps with names as typed; a statement extract shows customers' names and references. The operator describes what the screen shows in words, or gives entry ids only (§3.3).

**R2 — a decision per row**, stored in `cb_corrections` (append-only, with a partial UNIQUE on `voided_ledger_id` among active rows). Each decision records the row's content hash when it was shown, the decision, who decided, who approved (D5) and the reason.
- **Decision kinds:** keep voided · cash still held · cash banked · bank receipt · Airtel Money · "different payment, not a twin" (with a reason).
- **uCRM's method is evidence, never the rule** (F44). *Cash banked* goes to the transfer flow after D2. *Bank receipt* goes to the statement path (R3). *Airtel Money* waits for D23.
- **The statement is evidence too.** A cash-kind decision is refused when a reviewed statement line is linked or suggested for the same payment, or for its client + amount within the window, unless a person records why the money is still physical cash. The bank path is offered instead.
- **Default *keep voided*** when a twin exists, when the void is a recorded deliberate act, when the row is dated on or before the 12 Sep adjustment, or when it was voided before 4 October, **or its void time is unknown**. The last cases can change only under D3, with the before and after shown.
- **Excluded entirely:** the 12 Sep adjustment, every `bank_import` row, and both legs of each import transfer. R1 shows them read-only. Any change to them belongs to D3, decided after P0's second run, under its own §M approval.
- **The source of truth is the FinAudit void `before_json`**, where it exists. A post-void edit, or a missing FinAudit row for a single void, refuses the repair or sends it to a person.
  - **A pair void writes no FinAudit row** (`lib/CashbookService.php:821-832`, F19). Its evidence is the stamp, which carries the actor and the minute, plus both legs. Every pair repair goes to a person.
  - **A hard-deleted row** exists only as its FinAudit `delete` row. Any decision on it goes to a person.
- **Timing:** after X8–X16, after D3, and **after that period's statement has been reviewed** (P3c or later). Each cash-kind batch carries a **physical cash count before and after**, recorded with its approval.
- **No period lock protects a cash repair.** D10 locks postings *into an account* dated on or before its signed-off end (`docs/68:302`, `:448`). A cash-kind repair is unassigned, on the cash stream, so the lock never applies to it. Every approval therefore records the before and after of every affected month. Extending the lock to the cash stream is its own decision (D28).
- **Before the D3a cut-off.** Under D3a the months before the cut-off are never reviewed statement by statement, so the timing rule can never be met for a cash-kind row dated before it. **Such a row stays voided.** The only exception is a recorded attestation that the money was physical cash. It has no lock, carries the before and after of every affected month and a physical count, needs the second approver (D5), and is *not recommended*.

| Kind of voided row | Repair offered |
|---|---|
| uCRM receipt (`crm_webhook`, `crm_api_sync`, `crm_gap_fill`, …) | Re-read the payment from uCRM. The decider chooses the kind. *Cash still held* → a new receipt row (source `cb_repair`, the same crm ids, the date per D18). *Cash banked* or *bank receipt* → the statement path only. *Deleted in uCRM* → keep voided, and check for an unmatched reversal (I2) |
| Hand-typed entry | A new row with the fields you give, through the module's writer, because `addEntry()` cannot carry an account or a customer (F11) |
| Pair void (`account_transfer`, `funding`, `fx_exchange`) | Both legs together, always decided by a person, **keeping the original pair source** with a new shared reference and the link to the voided pair in `cb_corrections`. `recordFunding` and `recordCashExchange` take no source parameter (F50). The pair guards alone do not protect the result: they block only amount, date, direction and category (`lib/CashbookService.php:1853-1859`), while `validation_ref` and the description stay editable and `voidEntry` pairs legs by `validation_ref` + source (`:822-827`). So X8 and X16 refuse edits to any row an active `cb_corrections` link references, and `validation_ref` edits on any pair-source row |
| Hard-deleted entry (FinAudit `delete` row only) | To a person only. A new row from the `before_json`, through the module's writer, if the decision says so |
| Earlier bank-import rows; the 12 Sep adjustment | **Shown read-only in R1; excluded: D3 only** |
| Staff Cashbooks, records tool, `voided_reconcile` | **Listed read-only; not re-entered in version 1** (D19). Their voids also changed `payment_collections.json`, `cash_ins.json` and the staff ledger (F25). The recorded ones were copies of advances already in the main Cashbook; voiding them was recommended (F49) |

- **Refusals, checked in one transaction:**
  - the row's hash differs from the one shown;
  - an active correction exists;
  - a live twin exists, unless *different payment* is recorded with a reason;
  - the row is in the excluded set;
  - it is dated on or before the adjustment, was voided before 4 October, or its void time is unknown, without a D3 decision;
  - a cash-kind decision where a reviewed statement line is linked or suggested for the same money, without a recorded reason;
  - for a bank-kind repair (R3): its date falls in a signed-off period of that account (D10);
  - there is no second approver and no recorded exception (D5).
- **What the invariant really says:** *the module never writes the original row.* A test asserts it for the repair writer. It does **not** say the row never changes: `payment.delete`, the pull, `updateEntry`, the handover reconcile and Staff Cashbooks can rewrite voided rows today (F7, F23, F47). The hash check means something only after X8, X10, X15b and X16.
- **`cb_repair` rows, and every row an active `cb_corrections` link references, count as module rows** for X8, X10, X11's checks, X16 and docs/68 E9's protection. Repair rows are identified by the link, not by their source, because a repaired pair keeps its pair source.
- **Which figures change:** a repair on its original date changes every Balance from that date to today (F10, date order) and that month's P&L. They are shown per month before approval.
- **The audit trail** lives in the module's own events table, because FinAudit has no repair action and silently records nothing for an unknown one (F26). FinAudit still gets its rows.
- **Not used:** `approveEntry()` and `rejectEntry()` (F21); `assignAccount()` on voided rows (F13). *"Never rewrite a voided row"* is **this design's own principle**; the precedent followed is `tools/uganda_capital_cash_rebook.php` (F28).

**R3 — bank-kind repairs**, through statement lines only. Possible **only for rows dated after the D3 cut-off, or under D3b for the months concerned.**
- Under D3a, statement lines dated on or before the cut-off are refused for posting (`docs/68:219-221`), and one `ADJUSTMENT` absorbs the difference, out of the P&L (`docs/68:291-294`; `lib/CashbookService.php:547`). Re-posting would count twice. So voided bank receipts dated before the cut-off **stay voided**, and are explained in the cut-off reconciliation.
- **The D3a consequence for "making the past entries properly":** the bank balance is right from the cut-off on. Past customer bank income that was never booked never reaches the P&L. Income that was booked as cash stays in the P&L, but on the cash side.

### 2.3.9 Permissions and audit

- docs/68 E9's keys apply: *bank.view*, *bank.import*, *bank.approve* and *bank.signoff*, re-read by id, behind the Uganda gate and an off-by-default switch, POST only with `csrfCheck()` and `crossSite()` (`docs/68:304-321`).
- New keys: *bank.map* (the method map), *cost.assign*, *cost.view* (the per-customer view) and *cb.repair* (deciding repairs; approval is a separate person, D5).
- Every row the module posts, `cb_repair` rows and every row an active `cb_corrections` link references included, is refused by `updateEntry`, `deleteEntry` and *settle*, and by every other direct writer (X16's inventory).

## 2.4 Precondition fixes and approvals

"Before" names the first step that may not ship without the fix.

| # | Fix | Evidence | Before |
|---|---|---|---|
| **X1** (docs/68) | Opening Balances: print the token, refuse on a failed check, refuse cross-site | `docs/68:384` | P3c |
| **X2** (docs/68) | Bank-import transfer legs can be voided singly: add them to the pair guards | `docs/68:385` | P3c |
| **X4** (docs/68) | Bank rows and balances reach almost every role through the API, the CSV export and the daily e-mail: limit them to *bank.view* | `docs/68:387` | P3a′, P3c |
| **X5** (docs/68) | The CEO dashboard shows account positions on Uganda | `docs/68:388`; part of D1 | P3b |
| **X7** (docs/68) | The old CLI's `--commit` refused on Uganda and whenever the module is on | `docs/68:390` | P3c |
| **X8** | `updateEntry`, `deleteEntry` and bulk delete refuse voided rows, module rows, `cb_repair` rows and **any row an active `cb_corrections` link references**; refuse `validation_ref` edits on every pair-source row; and require the admin role. **Residue:** an admin can still edit or delete a live row the module did not write; each change is recorded in FinAudit | F23; `lib/CashbookService.php:1853-1859`, `:822-827` | R1 |
| **X9** | `cb_reseed` gets a token, the admin role and an audit row, or is removed. `settle_disb` gets a token and a role | F24 | R1 |
| **X10** | `payment.delete`: live IN rows only, ordered and counted. More than one live row, or any module or `cb_repair` row → a flag for a person, nothing posted. Refuse when `REV-CRM-PAY-{id}` exists. Reverse onto the original's account. Its UPDATE of the original skips voided and module rows. **Zero live rows with any voided row → a flag for a person, nothing posted** (the I2 case). Gated per D22 | F7; I2, I3 | R2, P4 |
| **X11** | **Automatic re-posting and cross-writer duplicates.** (a) Every uCRM-receipt writer — webhook, gap filler, pull and its fallback, catch-up, PWA API, field collection — checks `crm_payment_id` (IN, outside the reversal and refund sources) across all sources and statuses, plus the module's link table and the `cb_repair` source. (b) **The collection link, which prevents I1(a) and (b).** Before posting a receipt for payment *y*, a writer resolves *y* to its collection: `payment_collections.json` with `crm_payment_id = y`, or the retry item that created *y* (its `crm_payment_id` in `cron_sync.php`'s consumer, `crm_id` in `cron/crm_payment_retry.php`). When a row with sr or `validation_ref` `COL-{that collection}` exists in any status, it posts nothing: the webhook flags it for a person, and the catch-up only marks the collection synced. A `COL-` row with no payment id and the same client and amount within the window is also a flag for a person, nothing posted. That covers a webhook that arrives before the back-fill, and the PWA's existing retry items, which carry no collection id, so only this check can link them. *Not chosen:* stamping `crm_payment_id` onto the existing `COL-` row, which would rewrite old rows. (c) The pull's fallback skips voided collections and any collection whose payment id or `COL-` ref already has a row. (d) The catch-up, auto-heal and both retry consumers skip voided collections. (e) The PWA's retry record carries the collection id; both retry consumers back-fill it; `cron_sync.php`'s consumer uses `createPaymentSafe`, like the other. (f) The admin push is unreachable (F6); only if it is ever repaired does it take the same checks and honour `csrfCheck()`. One test per I1 family (§2.6) | F5, F6, F27, F42, F43; I1, I7, I8 | R2, P3a′, P4 |
| **X12** | The KYC cash sale writes its `crm_payment_id` | F6 | P4 |
| **X13** | The refund digit regex is replaced by a client a person chooses; the refund is recorded under its real method | F46 | P4 |
| **X14** | `approveEntry()` and `rejectEntry()` refuse every row that is not `pending_approval`, write FinAudit with the actor's id, and return the key their callers read. Test: no role, through either route, can approve or reject a voided row, and cash in hand does not move | F21 | R1 |
| **X15a** | `crm_sync` becomes POST with `csrfCheck()`, `crossSite()` and the admin role. The debug `?run=1` is refused. Neither needs X11 | F47 | R1 |
| **X15b** | The pull's DELETE and UPDATE are removed, or audited and limited to live duplicates | F47 | R2, P4 |
| **X16** | The handover reconcile and Staff Cashbooks' raw UPDATEs filter `status IN ('approved','pending_approval')` and write FinAudit. **Every direct UPDATE and DELETE on `cb_ledger` is inventoried** (docs/68 counts 27 more beyond edit and delete, `docs/68:127-128`), and each refuses voided and module or repair rows | F47 | R2, P4 |

X3 of docs/68 (`approveEntry` reinstates a voided row) is replaced by X14.

**Approvals needed beside the decisions:**
- **docs/68 §M exceptions 1–7**, as written (`docs/68:462-474`).
- **M8:** X10 changes `webhook.php`.
- **M9:** back-dated repairs (D18) change past months and today's figures.
- **M10:** X11 changes the webhook, the gap filler, the pull and its fallback, the catch-up, the PWA API, field collection, auto-heal and both retry consumers: `webhook.php`, `cron_crm_payment_gap.php`, `lib/CashbookService.php`, `cron/payment_catchup_sync.php`, `includes/api/api_retailer.php`, `includes/post/post_field.php`, `cron_sync.php` and `cron/crm_payment_retry.php`. The admin push (`tabs/admin/settings.php`) changes only if it is ever repaired.
- **M11:** X8, X9, X14, X15a, X15b and X16 restrict what staff can do today.
- **A7, an amendment to SAFETY.md RULE 7:** for the bank module only, `bank_method_map`, read from the live uCRM list, decides a method's kind and account. `resolve()` stays the only path for building a `methodId` payload. The map never falls back to Cash.
- **A12, an amendment to SAFETY.md RULE 12:** the receipt check becomes `crm_payment_id` (IN) across all sources, plus `validation_ref`, plus the collection link (`COL-` rows, X11 b), plus the module's links. Not `validation_ref` alone. Needed under A and B alike.
- **RULE 11b** is followed, not amended: the Expected job ends with `return;`.
- **D22 — South Sudan.** X8 to X16 change code both tenants share. The files, fix by fix:
  - X8: `lib/CashbookService.php` (`:1847-1909`), `includes/post/post_cashbook.php` (`:1237-1301`);
  - X9: `includes/post/post_cashbook.php` (`:1217-1232`, `:1350-1375`);
  - X10: `webhook.php`;
  - X11: `webhook.php`, `cron_crm_payment_gap.php`, `lib/CashbookService.php` (the pull and its fallback), `cron/payment_catchup_sync.php`, `includes/api/api_retailer.php`, `includes/post/post_field.php`, `cron_sync.php`, `cron/crm_payment_retry.php`;
  - X12: `lib/KycService.php`;
  - X13: `includes/post/post_cashbook.php` (`:541-580`);
  - X14: `lib/CashbookService.php` (`:2322-2330`), `includes/api/api_cashbook.php`, `includes/post/post_cashbook.php`;
  - X15a: `includes/post/post_cashbook.php` (`:1303-1311`), `includes/api/api_cron_debug.php`, `tabs/accounts/cashbook.php`;
  - X15b: `lib/CashbookService.php` (`:2370-2390`);
  - X16: `includes/api/api_cron_debug.php`, `tabs/accounts/staff_cashbooks.php`, and every file its inventory finds.

  Either they apply to both tenants, and South Sudan's byte-for-byte test is changed deliberately and recorded; or they stay Uganda-only, and South Sudan's exposure to the same holes is recorded.

## 2.5 Schema — one migration per step [NEW]

- **Each step that needs tables brings its own migration**, numbered when it ships; 088 is the next free number. In ship order:
  - P3a′: `bank_method_map`, `bank_expected_receipts` and their events;
  - P3c: docs/68 §F's seven tables (`docs/68:342-350`);
  - R2: `cb_corrections` and its events;
  - P5: `cost_attributions`.
- **No column is added to `cb_ledger` or `cb_accounts`. No foreign key to `cb_ledger`.**
- **No migration seeds anything.** Every seed, for example docs/68's `bank_known_rows`, is a separate, audited, counted tool. The runner drops a seed row that hits a UNIQUE rule and still logs the file OK (F40).
- **Trigger bodies avoid `CASE … END`**, or the splitter is fixed and tested first (F40).
- **Each deploy verifies** every table, index and trigger by name, the row counts, and each trigger's behaviour, by a rolled-back probe on a copy.

## 2.6 Tests

Synthetic data only. Each test has a weakened copy that must fail it, and suites run twice (`docs/68:392-415`). P2, and anything that reads a real statement, runs **in a throwaway folder outside the repository, deleted afterwards, with masked output**. The statement *"will never be a test fixture"* (`docs/68:178`, `:426`). **The same rule binds R0's output, R1 screens and any statement extract: none of them enters the repository or the chat unmasked** (§2.3.8).

New cases:
- the Expected list never moves cash in hand; an incomplete read never marks a payment Stale or Withdrawn; only a 404 to the read by id withdraws;
- an unmapped method, or a (method, currency) pair with no account, is held, never treated as Cash; a USD payment under a bank method maps to Ecobank USD, a UGX one to Ecobank UGX;
- a changed payment is re-read before posting; a bank → Cash edit makes the row *Changed in uCRM* and flags the posting, and never *Withdrawn*;
- one active posting per payment id across a cash row, a bank match and a repair, through every writer X11 touches, one test per writer;
- **one active posting per collection, one test per I1 family:** (a) push failure, then a retry consumer or auto-heal, then the webhook → no `PAY-y` row beside `COL-x`; (b) the catch-up → no second row beside `COL-x`; (c) the pull's fallback → no `COL-` re-post; (d) a gap-filled or catch-up receipt is not re-posted by the pull; (e) a KYC sale's row carries its payment id (X12); (f) two retry consumers and auto-heal on one collection → one uCRM payment. Also: the webhook arriving before the back-fill is flagged, not posted;
- **uCRM says Cash and the statement shows a bank credit for the same payment**: the E5 move is offered only with exactly one live IN row and no twin;
- **a voided receipt, uCRM says Cash, a reviewed statement credit matches** → the cash re-entry is refused and the bank path is offered;
- a KYC row, a push-failure `COL-` row and a hand-typed row with no client id are found as twins;
- `payment.delete` leaves module and `cb_repair` rows alone, flags two live rows, flags zero live rows with a voided one, and never reverses twice;
- no role, through either route, can approve or reject a voided row, and cash in hand does not move;
- a cash repair is refused when the row changed, was already repaired, has a live twin, is in the excluded set, is dated on or before the adjustment, was voided before 4 October or at an unknown time, or has a matching reviewed statement line without a reason; a bank-kind repair is refused when it falls in a locked period of its account;
- every cash repair's approval carries a before and after for each affected month and a physical count;
- the repair writer never writes the original row;
- **a repaired pair cannot be edited one leg at a time**, `validation_ref` and description included. Today's `updateEntry` fails this test (F50, `lib/CashbookService.php:1853-1859`); it passes only after X8 and X16 refuse rows an active `cb_corrections` link references;
- a double tag of one money movement (line and row; expense and row; purchase debit and kit) is refused, and so are a currency mismatch and a capital, transfer or advance source;
- **a Customer Refund or Customer Commission OUT row whose `crm_payment_id` holds a uCRM refund id is refused as a cost tag**, and so is a debit linked to a Starlink bill (matcher link, `stock_purchase_payments.cb_ledger_id`, module link, or a person's classification). Each with a weakened copy;
- a kit's currency comes from its inbound movement's purchase; with that link empty, from an exact `purchase_ref` match; with neither, *uncosted*. One test for each; a reassigned unit is costed once;
- a Starlink invoice whose account is empty, or held by two live assignments, is *not attributable*; empty accounts are never joined;
- the per-customer view never sums currencies, nets refunds with commissions shown apart, shows cash, verified and expected apart, resolves *probably in the book as* through the collection and KYC rules, and marks auto-heal's `[auto-matched]` payments, field collection's auto-matched collections and handler-created refunds as *customer not confirmed*, while auto-heal's Case A stays confirmed;
- South Sudan is unchanged byte for byte, or changed exactly as D22 decides.

## 2.7 Not knowable from the repository

1. The Uganda uCRM's payment methods and their ids, including whether its Cash id equals the constant (I5), and whether the `BANK_TRANSFER` constant the staff app sends exists there at all (F44).
2. **Under which method** staff record bank and Airtel payments — in uCRM, and in the staff app, which offers Bank — and whether before or after the money lands. Your answer 2 leaves this open.
3. Which entries you voided, through which screen and when; whether any of that money was typed in again. Whether VOID was typed at the 5.18.70 prompt is not knowable from the repository, but R0 can read it from the live stores (F49).
4. Whether I1, I2 and I7 have happened on the live book. R0 answers this.
5. What Ecobank's reference and Remarks columns carry for customer payments.
6. How many installed kits carry a purchase cost, and which currency their purchase shows.
7. Whether each kit has its own Starlink account.
8. Why P0's automatic rule found no candidate row on 14 October (exit 7).
9. Whether uCRM fires `payment.add` for payments the plugin itself creates, and whether it redelivers events.

## 2.8 Critique points narrowed, moved or not taken

Every other correction in the three critiques, and in the two checks of version 2, is applied as written. These were narrowed, moved or replaced, each with its evidence. Points are numbered *critique.point*, both counted from 0.

1. **"The 5.18.70 records-tool VOID also voids `cb_ledger` by ref"** (point 1.4, citing `tools/staff_records_currency.php:126`, `:143-145`). That cascade sits in the tool's **collection** branch (`:131-148`). The three recorded rows are **cash-ins**. The cash-in branch (`:157-169`) voids `cash_ins.json` and the staff ledger only, and docs/07 records *"no ledger row to void since `Collection` cash-ins have none"* (`docs/07-change-control.md:3939`). So the recorded voids are not in `cb_ledger`. The correction — tag deliberate voids and default them to *keep voided* — is applied, and R0 now reads `cash_ins.json` and `staff_ledger` for them (F49, R0, R2).
2. **"Tag deliberate voids by reason patterns such as `staff_records_currency 5.18.70`"** (point 1.4). Applied with a different pattern. That string is the tool's `$by`, which never reaches `cb_ledger`: the ledger stamp carries `$reason` only (`tools/staff_records_currency.php:126`, `:134`, `:145`). R0 matches the reason text on `cb_ledger` and `voided_by` in the JSON stores (R0, F48).
3. **"Debug + Sync runs the sync from a GET"** (points 1.3 and 2.0). True, but that route checks the role: admin, super_admin or accountant (`includes/api/api_cron_debug.php:1179-1184`). The fix still applies, because a GET is reachable cross-site with a `SameSite=None` session cookie (F47): X15a refuses `?run=1`.
4. **"Restrict `crm_sync` and the debug run in P3a"** (point 2.0). Applied by **splitting X15**. X15a (POST, `csrfCheck()`, `crossSite()`, the admin role; `?run=1` refused) ships in P3a, before R1; it needs nothing from X11. X15b (removing or auditing the pull's DELETE and UPDATE, `lib/CashbookService.php:2370-2390`) stays in P3a+, because it changes the pull's duplicate handling together with X11. R1 therefore re-reads the book on every visit and says that automatic jobs can still change voided rows until X15b and X16 ship (§1.9, §2.3.8).
5. **"R0 counts Receipt rows whose uCRM method is not Cash"** (point 0.3). **Narrowed.** R0 counts the collection-method half only, so it stays local and never calls uCRM. The uCRM-method half is read on screen. **Its limits today:** Collection Reconcile matches only `PAY-{id}` refs and `CRM-{id}` SRs (`tabs/accounts/collection_reconcile.php:53-61`, `:125-127`), so it misses `COL-` and KYC `CRM#client` rows; and it has no status filter (`:44-51`), so voided rows count as matched. **S1a fixes this**: it shows matched counts per uCRM method, with voided rows kept apart and `COL-`/KYC rows resolved through the rules of §2.3.7.
6. **"Exclude the 12 Sep adjustment from R1 and R2 entirely"** (points 0.1 and 1.6). R2 excludes it. **R1 shows it read-only**, with the bank-import rows and the legs of each import transfer, labelled *"D3 only, not repairable"*, and accepts no decision on them. Hiding them would send a reader looking for them elsewhere.
7. **"R2 needs a period lock, or a statement that there is none"** (point 1.15). D10 locks postings *into an account* (`docs/68:302`, `:448`), so it never protects cash-stream repairs. The design states that cash repairs have no period protection, requires a before and after for every affected month in every approval, and records extending the lock to the cash stream as its own decision (D28). Under D3a, cash rows dated before the cut-off stay voided, except by the recorded attestation path (§2.3.8).
8. **"The cost page shows three figures for each kit"** (your answer 3). Kit costs are per kit. **Income and bank charges are per customer**, because nothing in the repository reads an invoice's service, the step a per-kit split would need [I]. It is stated plainly in §1.1 and §1.8, and per-kit income is a later option under D17.
9. **The admin push.** The critiques treated it as a live writer. It is unreachable as written (F6), so I1(b) no longer depends on it and X11(f) changes it only if it is ever repaired.
10. **"The plugin's forms offer only Cash"** (point 2.1's evidence, inherited by version 2). Corrected: the office form offers only Cash, but the staff app offers Bank, and a Bank payment from it still posts a cash-stream receipt (F44). The conclusion that bank money sits in cash in hand is now partly read from the code, not only inferred.
11. **"Mark every `crm_auto_matched` payment customer not confirmed"** (version 2's own rule). Narrowed: auto-heal sets the flag on every collection it heals, so for auto-heal only the `[auto-matched]` note counts (F42, §2.3.7).
12. **"Prevent cross-writer duplicates by `crm_payment_id`"** (points 0.0, 1.0 and 1.2). Applied, and widened: an id check alone cannot link a `COL-x` row to the `PAY-y` posting of the same collection, so X11(b) adds the collection link. Stamping the payment id onto old `COL-` rows was considered and not chosen, because it rewrites old rows.

---

# Part 3 — Decisions, build order, questions

## 3.1 Decisions

| # | Decision | Status | Recommendation |
|---|---|---|---|
| D1 | Reverse "cash in hand only" for bank money; bank balances on the Bank screen only (`docs/68:438`) | Open | Yes, on the Bank screen only. Needed before any Expected view is shown (S1a, P3a′) |
| D2 | What cash in hand counts: hero, Balance column and export (`docs/68:439`) | Open; blocked on P0's second run | Bank and non-money accounts out. It **may** change today's figure, depending on whether the 12 Sep adjustment is left out too; P0's second run shows it [I]. **Accepted by a physical cash count**, not only by P0's arithmetic: at P3b the count and an itemised, explained difference are recorded; after P4, R2 and R3 a new count must equal the figure |
| D3 | The past: one cut-off `ADJUSTMENT` (a) or a rebuild by review (b) (`docs/68:440`) | Open; after P0 | Your choice. **It also decides repairs:** under D3a, bank receipts before the cut-off stay voided, and past bank income never booked never reaches the P&L; cash rows dated before the cut-off stay voided too, except by a recorded attestation. Re-entering them needs D3b for those months |
| D4 | Customer bank receipts: one writer (`docs/68:441`) | Refined by D13 | — |
| D5 | Maker-checker and thresholds (`docs/68:442`) | **Proposed, not decided** | Also covers repairs and cost tags. If one person does everything, each act records the exception |
| D6, D6b | Categories; staff categories refused in v1 | Open | Unchanged |
| D7 | Which bank accounts exist | **Answered: Ecobank UGX and USD** | Confirm the existing account rows and their start dates on screen |
| D8, D9 | Statement formats; CSV first | Open | Ecobank CSV first, for UGX and for USD |
| D10 | Lock signed-off periods | Open | Yes, for bank accounts (R3). **It does not cover cash-stream repairs** (D28) |
| D11, D12 | Statement retention; project `dishnet` for bank rows | Open | Unchanged |
| **D13** | A statement-first plus an Expected list, or B uCRM-first (your literal request) | **Decided 16 Oct: A** | **A**, chosen knowingly (§1.6, §2.2) |
| **D14** | Which uCRM methods mean which account, per currency; which method staff pick for a bank payment, in uCRM and in the staff app (which offers Bank, F44); card; cheque | Open | You name them from the screen; the plugin reads the ids. Unmapped methods are held. **The Expected list is built only if bank methods are in use** |
| **D15** | Days before an expected payment is flagged; who chases it | Open | Your number |
| **D16** | Costs per customer | **Scope answered: all of them.** Split rules open | Shipping and Starlink-payment fees split across an order's kits in proportion to line price; a shared trip split by the shares a person states |
| **D17** | Source of per-customer income; per customer or per kit | Open | uCRM payments read by client, refunds netted, commissions apart; cash, verified and expected shown apart. **Per customer in v1**; per kit later only if uCRM invoices can be tied to a service, which nothing reads today |
| **D18** | Repair form and date | Open; **depends on D3** | A new entry with a link, never an un-void. The original date only with a before/after per affected month and a physical cash count; no period lock covers a cash repair (D28). Refused before the cut-off under D3a, except by the attestation path |
| **D19** | Repair scope in version 1 | **Process answered: list first, you decide each** | Cashbook voids of `cb_ledger` rows, single and pair, and hard-deleted rows, each pair or deleted row by a person. Shown read-only and excluded: the 12 Sep adjustment and the bank-import rows (D3). Staff-record voids listed only; the recorded ones were copies of advances already in the main Cashbook |
| **D20** | Who sees the per-customer view | Open | Admin only at first |
| **D21** | A uCRM payment deleted or edited after its bank posting; a bounced cheque | Open | Flag it for review (X10) |
| **D22** | The safety fixes (X8–X16, every file listed in §2.4) on South Sudan too, or Uganda only | Open | Both tenants, with South Sudan's test changed deliberately — if whoever runs South Sudan agrees |
| **D23** | Airtel Money: its own account and reader, or excluded for now | Open | Excluded in v1, and said so on screen; an Airtel account once you can supply a sample export |
| **D24** | What customers write as the transfer reference | Open | The invoice number, or the quotation number, not their own name. It changes the payment text and the website, so it is its own instruction |
| **D25** | Each kit sold or lent; the rule for a reassigned or replaced kit | Open | Recorded per kit; a reassigned kit is costed once, to the sale or to the current live owner |
| **D26** | The monthly Starlink charge per customer | Open | Exact only where each kit has its own Starlink account; otherwise "not attributable" |
| **D27** | Going beyond X11: writing the module's claim (UNIQUE uCRM payment id) in the same transaction as each existing writer's insert | Open | Later, as its own step. Until then the rule is checked, not enforced |
| **D28** | Extend the period lock (D10) to cash-stream rows | Open | Not in v1. Cash repairs carry a before/after for every affected month and a physical count instead |

## 3.2 Build order

Each production step is its own deploy, with its own log and its own before/after figures. **Its rollback is handed over separately, never in one copyable block with the deploy** (`docs/68:419-420`; `docs/44-j1-j8-implementation-specification-2026-09-27.md` §16.9). **No step posts a real transaction without your explicit go.**

Sizes are rough and relative, for comparison only [I]: **S** small, **M** medium, **L** large.

| Step | What | Production change | Waits for | Size · what is reused |
|---|---|---|---|---|
| **Now** | Use *Accounts → Reconcile* with its caveats. Leave *Sync Now* with a From date, *Debug + Sync* and voided entries alone | None | — | — · exists |
| P0, 2nd run | P0 with `--adjustment-id=N` | None | The 12 Sep row's id, found through R0's candidate list (the screen shows the SR No., not the id), and its state (question 2). If it is voided or assigned to an account, P0 ends with exit 3 and needs a change first. An edited date or wording does not stop it (F16). If it was deleted, there is no id to give, and P0 needs a change first | S · exists |
| **R0** | The read-only census (§2.3.8), **plus the candidate rows for the 12 Sep adjustment** (id, date, status and account kind only) | None: a script in the checkout | **Built 16 Oct; the hand-over is Part 4**; its run waits for you | M · P0's runner, gates and masking |
| **S1a** | A read-only Expected view on Collection Reconcile: voided rows shown apart, matched counts per uCRM method, `COL-` and KYC rows resolved (§2.3.7), incomplete reads flagged, every amount and total per currency, "landed in the book as". Collection Reconcile is shown on both tenants (F53), so the change is gated per D22 | Yes; read-only screen, **no migration** | **D1**, because it shows bank-method money; D14 for the method names | S · Collection Reconcile |
| **S1b** | A read-only kit-cost view per customer: currency or *uncosted*, sold/lent label. Uganda-gated | Yes; read-only screen, **no migration** | D20; D25 for the labels | S · `CustomerAccountService` |
| P1 | Build dark on the development branch, Uganda only | No | §K and §M approvals | L · docs/68 design |
| P2 | Throwaway environment outside the repository, masked; your statements read and compared; nothing posted | No | P1 | M |
| **P3a** | X8, X9, X14, X15a and X1 | Yes | Approval M11; D22 | M |
| **R1** | The repair list, read-only. It re-reads the book on every visit | Yes, no migration | P3a | M |
| **P3a+** | X10, X11, X12, X13, X15b, X16 | Yes | Approvals M8, M10, A12; D22 | L |
| **P3a′** | The method map and the persisted Expected list: reads uCRM, posts nothing. **Only if D14 shows bank methods in use** | Yes, one migration | P3a+, D1, D14, X4, A7 | M · Collection Reconcile's read |
| P3b | D2 and D1, with P0's figures before and after, **a physical cash count and an itemised, explained difference** (the count equals the figure only after P4, R2 and R3) | Yes | P0's second run, X5 | S–M |
| P3c | Bank module on for Ecobank UGX; the D3 cut-off; a pilot month; the D10 lock | Yes, one migration | P3b, X1, X2, X4, X7 | L |
| **R2** | Cash-kind repairs, one by one, each approved, with a physical count per batch and a before/after for every affected month (no period lock covers them, D28) | Yes, one migration | R0, R1, P3a+, P3c and that period's statement review, D3, D5, D18, D19 | M |
| P4 | Customer receipts from matches (D13); the Case 1 moves; Ecobank USD; card if used | Yes | P3c, D13, D21, X10–X16 | L |
| **R3** | Bank-kind repairs through statement lines, after the cut-off or under D3b | Yes, row by row | P4, D3 | M |
| **P5** | Cost tags and the full per-customer view: income, kit, subscription, bank charges, tags | Yes, one migration | D16, D17, D20, D25, D26, X8 | L · `CustomerAccountService`, `ReportingService::figure()` |
| P6 | The Airtel Money wallet account and its reader | Yes | D23 (a) and a sample export | M–L |

## 3.3 Questions for you

Please answer from the screens, **with no amounts, names, phone numbers or account numbers in chat.** **Never paste the repair list (R1), R0's output or any bank-statement extract into the chat, and never add them to the repository:** they show dates, amounts and names as typed. Describe what you see in words, or give entry numbers only.

1. **Your voids.** Are the entries you mean the three hand-typed staff-cashbook entries of 25 September that the records tool was run to void (§1.9)? If you remember whether you typed VOID at its prompt, say so; R0 can also read it. If you mean other entries too: roughly which dates, which screen (Cashbook ⋮ Void or Staff Cashbooks), whether the money was cash still held, cash that was banked, money paid into the bank or Airtel Money, and whether you typed any of it in again afterwards.
2. **The 12 Sep adjustment.** The Cashbook screen shows each entry's SR No., **not the internal id** that P0's second run needs, and the two are different numbers. So please do not try to read the id off the screen. Instead:
   - open the entry in the Cashbook and say, in words, whether it is voided, whether it sits on an account, and whether its date or wording looks changed; or say that you cannot find it;
   - **R0 (question 13) will list the candidate rows for it** — by direction and amount, on any date, voided or not, on an account or not — printing only each row's id, date, status and account kind. You then name the id, and P0's second run uses it.
3. **Payment methods.** In uCRM → Settings → Payment methods, which method names are active? When staff record a bank payment, which one do they pick: in uCRM itself, and in the staff app, which offers Cash, Mobile Money and Bank? On *Accounts → Reconcile*, for last month, which method names appear in the per-method list? Names only. Is card (DPO) in use?
4. **Airtel Money.** Under which uCRM method do staff record an Airtel Money payment? Is the wallet money moved to Ecobank, taken out as cash, or left in the wallet?
5. **Timing and references.** Do staff record a bank payment in uCRM before or after it shows in Ecobank? What do customers usually write as the reference: their name, a quotation number or an invoice number? May we ask for the invoice number from now on (D24)?
6. **Kits.** Is each customer's kit on its own Starlink account, or are several kits on one account? Is that account recorded against the kit in the plugin? Are kits sold, lent, or both? Is one figure per customer enough for what the customer paid us, or do you need it per kit?
7. **Ecobank.** Are the plugin's existing Ecobank UGX and USD accounts the ones to use, and from which date? Can you download a CSV or Excel statement for each?
8. **D13.** A (statement first, recommended) or B (uCRM first, as you asked), knowing the costs in §1.6?
9. **Approval and counting.** Is there a second person who can approve repairs and bank postings (D5)? Can a physical cash count be made and recorded before and after each repair batch?
10. **Repair date (D18).** Should a repaired entry carry its original date (past months' figures change, shown to you first) or today's date?
11. **Since voiding.** Have you deleted payments in uCRM as well as voiding entries in the Cashbook (I2)? Have you pressed *Sync Now* with a From date, or *Debug + Sync*?
12. **South Sudan (D22).** Should the safety fixes apply to South Sudan too, or to Uganda only?
13. **R0.** May the read-only census be prepared, rehearsed and run by you only, like P0? **Answered 16 Oct: yes. Built; the hand-over is Part 4.**

---

# Part 4 — R0: the hand-over (16 October)

**Status: built, reviewed and rehearsed; committed and pinned; not run anywhere.** You run it on the server. It reads
the book and changes nothing: there is no rollback because there is nothing to roll back, and no other command belongs
with this one.

**What R0 tells us.** It counts the voided entries (by source, path and month, with when each was voided and what
evidence of it is kept), entries deleted outright, probable double entries (candidates only, never proof), receipts by
collection method, reversals, the rows of 12 Sep, **the candidate rows for the 12 Sep adjustment** (so P0's second run
can be given its id), the deliberate voids, whether VOID was typed for the three 25 Sep cash-ins, and live kits with no
Starlink account. It prints counts, entry ids, dates and codes only — **never a name, description, person, reference,
customer or amount**. The 12 Sep adjustment's candidates are found by a hash of the figure docs/07 records, never by the
figure.

**How it was built.** The same write-proof mechanics, gates and masking as P0 (`docs/68` §O, §P), copied: the tool is
piped into the container's PHP, runs as the database's owner, opens the book read-only and immutable with `query_only`,
waits until the plugin has the book closed, reads it twice and compares the two readings. The gated runner is P0's, with
R0's files, pins and texts.

**The pins.**
- Commit `fa327f4a02fcb07a529380bc123580857970d6c5` holds the tool (`scripts/bank-recon-r0.php`, sha256
  `9cb7e4fecb592f1a…`) and the command (`scripts/bank-recon-r0.sh`, sha256 `c34d4675a131c2bb…`). The command pins the
  tool's sha256.
- Commit `72cf1dcec816f3202e465a9498c1bbc3284ce055` holds the runner (`scripts/bank-recon-r0-run.sh`), which pins
  commit `fa327f4a` and both sha256s.
- The runner's own sha256 is `36da24023d0af5b30e4a5546689139979f148ddd9697b99a725d5bf44d9cb604`; the line below
  checks it.

**Rehearsed** (`scripts/harness/bank-recon-r0/`, synthetic books only; every name and figure in them is invented):
- `rehearse.sh` — the tool and the command on synthetic books, including a 30,000-row book, a plugin writing during
  the reading, and books built the way the webhook writes reversals. 16 Oct, two runs (14:41 and 15:51 UTC): **282
  passed, 0 failed each**; of them, 70 weakened copies of the tool and the command, every one caught.
- `rehearse-run.sh` — the line, run as written with only its two server paths and its two pins replaced, against a
  sandbox checkout and a strict fake docker. 16 Oct, two runs (14:54 and 16:06 UTC): **132 passed, 0 failed each**; of
  them, 42 weakened copies of the runner, every one caught.
- Those four runs used the same tool, command and runner, with the pins not yet set.
- **Against the pins above**, after both commits were pushed and GitHub served all three files with the sha256s above:
  - `rehearse.sh`, 16 Oct 17:17–17:29 UTC: **282 passed, 0 failed**, the 70 weakened copies caught;
  - `rehearse-run.sh` with `PINS=real`, which reads the line out of this Part and checks that its commit holds exactly
    this runner and its sha256 is the runner's own: 16 Oct 17:29–18:23 and 18:24–19:18 UTC, **134 passed, 0 failed
    each**, the 42 weakened copies caught. The two runs match line for line, except that one weakened copy was caught by 8
    assertions in the first and 9 in the second (timing);
  - the R0 files and the harness hashed the same before and after all three runs.
- Not rehearsed: `followRemoteHEAD=never` (the sandbox has git 2.43).

## The command — one line, as root on the server

Run it after the log of any earlier attempt has been sent. Then send back THE LOG FILE it names, not a copy of the
terminal:

    ( umask 077 && mkdir -p /root/dnb-bank-recon && chmod 700 /root/dnb-bank-recon && cd /opt/dishnet && git -c gc.auto=0 -c maintenance.auto=false -c core.hooksPath=/dev/null -c fetch.writeCommitGraph=false -c remote.origin.followRemoteHEAD=never fetch -q --no-tags --no-recurse-submodules --refmap= origin +refs/heads/claude/study-this-jhe2eg:refs/remotes/origin/claude/study-this-jhe2eg && git cat-file blob 72cf1dcec816f3202e465a9498c1bbc3284ce055:scripts/bank-recon-r0-run.sh > /root/dnb-bank-recon/bank-recon-r0-run.sh && echo "36da24023d0af5b30e4a5546689139979f148ddd9697b99a725d5bf44d9cb604  /root/dnb-bank-recon/bank-recon-r0-run.sh" | sha256sum -c --quiet - && env -u BASH_ENV -u ENV -u UCRM_CONTAINER -u SHELLOPTS -u BASHOPTS bash /root/dnb-bank-recon/bank-recon-r0-run.sh 2>&1 | ( trap '' INT HUP TERM; exec tee /root/dnb-bank-recon/r0-$(date -u +%Y%m%dT%H%M%SZ).log ) )

- The parentheses keep `umask 077` and `cd` inside the command; your shell is left as it was.
- `env -u` starts the runner without `BASH_ENV`, `ENV`, `UCRM_CONTAINER`, `SHELLOPTS` or `BASHOPTS`, whatever your shell
  exports. The runner also turns job control off itself and checks that it can keep R0's lines; it refuses if it cannot.
- The `tee` at the end ignores Ctrl-C, a hang-up and TERM, so an interrupted run's log still ends with its exit line.
- **R0 may print nothing for up to two minutes** after its `installed` and `this checkout` lines: it waits until the
  plugin has the book closed (no `-wal` or `-shm` beside it), then reads it twice. Leave it running; Ctrl-C ends the run
  with no result (exit 130 below).
- **No rollback exists or is needed: nothing is written to the book, the plugin or the checkout.**

## What it changes, and what it never touches

- **The fetch** adds Git objects to `/opt/dishnet/.git`, writes `FETCH_HEAD`, and moves one ref,
  `origin/claude/study-this-jhe2eg` (one reflog line). No local branch or tag is created or moved; no tag is fetched
  (`--no-tags`); automatic gc, maintenance, hooks, commit-graph writing and submodules are off for it. There is no
  checkout, pull, merge or reset.
- **The working tree, the index, HEAD and every local branch are never written**; every git command in the runner runs
  with `--no-optional-locks`.
- **In `/root/dnb-bank-recon`** (`chmod 700`) it writes the runner, one folder `src-r0-fa327f4-…` holding the two
  reviewed files taken out of commit `fa327f4a`, and the log `r0-<UTC time>.log` (mode 0600). P0's files there are left
  as they are.
- **The book** is opened read-only and immutable, with `query_only`, by the plugin's own owner (1000:1000) inside the
  `ucrm` container. It is read twice and the two readings are compared.
- If the runner's sha256 does not match, `sha256sum` prints FAILED and nothing runs: there is no log; say what the
  terminal said. Likewise if the fetch or `git cat-file` fails. A `git cat-file` that fails still leaves an empty
  `bank-recon-r0-run.sh` behind, and a shell with `noclobber` set then refuses to overwrite it
  (`cannot overwrite existing file`): delete it before the next attempt.

## Reading the log — each of these lines must be there

1. `checkout        /opt/dishnet at <commit> on <branch>`
2. `tracked files modified or staged: 0; not tracked inside dishnet-hybrid-sudan/ (data/ aside, ignored included): 0; …`
3. `reviewed R0     commit fa327f4a02fcb07a529380bc123580857970d6c5; origin/claude/study-this-jhe2eg at …`
4. `… tool sha256 9cb7e4fecb592f1a… = reviewed; command sha256 c34d4675a131c2bb… = reviewed; the command pins the same tool`
5. `book            /data/ucrm/data/plugins/.dishnet-hybrid-sudan-data/plugin.sqlite3, owner 1000:1000 — as expected`
6. Inside the R0 part:
   - `installed       <commit> in ucrm; data /data/ucrm/data/plugins/.dishnet-hybrid-sudan-data; database owner 1000:1000`
   - `this checkout   not readable as a git checkout; …` — expected: the command runs from the extracted folder, which
     is not a checkout;
   - `database        … — read-only, immutable, query_only=1; JSON functions yes` (or `no`, which the sections then say);
   - the `tables`, `stores` and `columns` lines, then every section (voided rows by source, path and month; void time and
     evidence; fin_audit records; twin candidates; the I1 families; receipt rows by method; reversals; rows of 12 Sep;
     adjustment candidates; the five largest unassigned rows per currency; deliberate voids; the three 25 Sep cash-ins;
     equipment). **A section may read `absent` — that is a result, not a fault.**
   - `checks          the parts add up: ok (…); the connections changed 0 and 0 row(s); query_only=1`
   - `read twice, immutable[ (after waiting N s for the plugin to close the book)]: the readings are identical; the database file did not move (inode, size, times); side files none, before or after`
     — the part in square brackets is there only when R0 waited for the plugin, N its seconds;
   - `R0: DONE — read only; nothing was written.`
7. `checkout        unchanged: HEAD, branch, local branches and tags, stash, index and every file's status are as before`
8. `folder          the R0 command read the folder checked above: /data/ucrm/data/plugins/.dishnet-hybrid-sudan-data, owner 1000:1000, in ucrm`
9. `RESULT: R0 DONE (exit 0). Send this LOG FILE back; keep it confidential: it holds the book's ids and counts.`
10. The last line: `RUNNER EXIT 0`.

**A log that does not end with `RUNNER EXIT <n>` was cut off and is no result.**

| Exit | What it means | What to do |
|---|---|---|
| 0 | R0 DONE | send the log file |
| 1 | the R0 command stopped before R0 ran (the reason is above) | send the log file |
| 2 | R0 refused (the reason is above) | send the log file |
| 3 | R0 stopped: it saw the plugin write during the reading, a check failed, or an internal error | send the log file; not retried |
| 4 | a gate refused before R0 ran (`GATE REFUSED: <reason>`, `R0 was NOT run`) — also any option given to the runner | send the log file |
| 5 | the checkout changed while R0 ran, or could not be read again | send the log file; do not use the counts |
| 6 | the R0 command read another folder than the one checked, or did not say which | send the log file; do not use the counts |
| 129 / 130 / 143 | the run was interrupted (a hang-up, Ctrl-C, TERM): there is no RESULT line; R0's reading inside the container may run on to its end, or stop, and writes nothing either way | send the log file; not retried |
| 255 or other | R0 did not finish (e.g. a PHP fatal error) | send the log file; not retried |

Nothing is ever retried or repaired; a further run waits for your word. Of the exit-3 lines,
`R0: FAILED — SQLite could not finish reading the book … run again` is the plugin-wrote-it case (a later run may
succeed), while `R0: FAILED — an internal error (<class>, line <n>) … do not run it again` is R0's own fault: the fix is
in the tool, not in the timing. Neither line carries any text from the book.

## What the log holds, and what it never holds

It holds counts, ids, dates, status codes and kinds of the real book. It never holds a name, description, person,
reference text, category text, customer or amount. **The log stays confidential: send it as a file, never paste it;
none of its content enters the repository** — neither its counts nor its ids, nor any statement from which they could
be rebuilt (§2.3.8).

## After the log

- **The 12 Sep adjustment.** The `adj. candidates` section lists each candidate's id, date, status and account kind. If
  exactly one candidate is the 12 Sep entry, its id goes to P0's second run (§3.2), which gets its own hand-over and its
  own go. If there are several or none, I will ask you about them in words (date and status only).
- **The 25 Sep cash-ins.** The `25 Sep cash-ins` section answers whether VOID was typed at the 5.18.70 prompt; you need
  not remember it.
- **Nothing is decided by R0.** Its counts feed R1, D2, D3 and the questions in §3.3; each later step waits for its own
  go.
