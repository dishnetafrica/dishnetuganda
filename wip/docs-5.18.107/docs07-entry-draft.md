# Draft — 5.18.107's entry for root `docs/07-change-control.md`

Not yet in `docs/07`. Every ⟦PENDING⟧ is a figure not yet measured: suite passes E1 and E2 on `771a3c1`, the
rehearsal's development trials and record run, or the entry's closing date. When they are in, this text goes into `docs/07` after the 22–24 Nov
entry, `wip/design-5.18.107/76-…` moves to root `docs/76-…`, and the draft is deleted, in one commit. Each figure below
was read from the repository on 27 Nov; the sources are named beside them.

---

## 21–⟦PENDING⟧ Nov — 5.18.107: on Uganda, the connection quotation built in code (Part Q); a customer holding a live quotation gets no price from the assistant (Part K); the numbers the assistant never answers (Part X, migration 092); one reply to a burst (W.1); the topic rule built in (W.2); the office line on the customer-care hand-over (W.3); the AI Knowledge Base screen reachable (W.5); a two-prices warning (W.6); "Nothing is added on top." leaves the tax lines (O7); four defect fixes (F-1, F-7, F-8, F-11) — every new switch off; South Sudan unchanged. `release/5.18.107` = `771a3c1`, one commit on 5.18.106 (`bf29cbd`), re-cut once on 27 Nov for test fixes only (the first cut, `5b6245b`, kept); NOT pushed; `scripts/deploy-5.18.107.sh` pinned to it; the release suite twice on it ⟦PENDING: E1, E2⟧; the rehearsal ⟦PENDING⟧; NOT deployed

**Request.** The owner's requests of 18–25 Nov, and two defects found on 23 Nov, each mapped read-only against
`bf29cbd` (root `docs/76` §B and §W):

- the assistant typed connection prices itself, and got totals wrong;
- a customer who already held a quotation was given other prices by the assistant and its enquiry follow-ups;
- some numbers must never get an AI reply, follow-up or offer, while invoices and receipts still go (staff decide which);
- 58% of typed messages had a newer one within 15 minutes (§N.1), and each got its own reply;
- the assistant drifted off DishNet topics, and "customer care" got the hand-over but no way to reach the office;
- found on 23 Nov: the AI Knowledge Base screen could not be opened, and a plan's two uCRM prices disagreed unseen
  (`docs/76` §W.5, §W.6). The release's `SAFETY.md` labels the first F-9; F-9 is the Help Guide's own defect (§J),
  recorded and not changed.

The design is root `docs/76`, reviewed eleven times; Parts H, S, N and L moved to 5.18.108 (its draft,
`wip/design-5.18.107/77-…`). The owner's decisions are in §O: O7, O10, O11 and O13 on 25 Nov, the six defaults
confirmed (*"Keep all six"*), and O14–O17.

### Release notes (Uganda only; root `docs/76` §A, the release's `SAFETY.md`)

Every change sits behind `StaffJobsGate::applies()`, so South Sudan runs exactly as before.

- **Part Q, the connection quotation** (`connection_quote_enabled`, off; needs `ai_hardware_expert`).
  - When the model writes `<<QUOTATION>>`, `lib/ConnectionQuote.php` builds both options, Standard then Mini: kit,
    installation, mount, plan, and the total with the first month. The figures come only from uCRM's own rows.
  - Any item missing, unpriced or duplicated, or a plan not priced for one month, is a refusal: the colleague line and
    a hand-over, never a guess.
  - It works on WhatsApp (every selling number) and the website chat. Settings: `connection_quote_transport`,
    `connection_quote_tax_line`, `connection_quote_closing`.
- **Part K, a customer holding a live quotation** (`quoted_no_prices_enabled`, off), on WhatsApp only.
  - `lib/QuoteInPlay.php` finds the live quotation, and any doubt holds the price.
  - The prompt gets the QUOTATION ALREADY SENT block. A reply that still carries a figure is replaced with
    `quoted_reply_text` (`lib/QuotedFloor.php`), and the quotation's owner is alerted (`lib/QuotedAlert.php`, at most
    once in `quoted_alert_cooldown_hours`, 6).
  - That phone gets no AI-written enquiry follow-up.
  - The website chat cannot know who the visitor is, so it has no Part K.
- **Part X, the numbers the assistant never answers** (no switch). Migration 092 creates `wa_no_ai` empty, so nothing
  changes until staff list a number.
  - A row is lifted, never deleted (triggers). The migration verifies itself, 5 of 5.
  - `lib/NoAiList.php` is the one reader and writer. Admins change the list on Engage → WhatsApp → AI setup, or with the
    Inbox toggle (a token bound to the session, `lib/NoAiGuard.php`).
  - For a listed number, these stop: AI replies (before the model is called), the voice acknowledgement, the media
    worker's hand-overs, enquiry follow-ups, quote chase steps, offers and the win-back. Invoices, receipts, payment
    reminders and anything staff send still go.
  - When a listed number writes, the line owner is alerted, else the lead's salesperson, else the central number
    (`lib/NoAiAlert.php`, `no_ai_alert_cooldown_hours`, 3).
  - **While the list cannot be read, no Uganda customer gets an AI reply** (`docs/76` §X.5).
- **W.1, one reply to a burst** (`ai_burst_merge_enabled`, off; `ai_burst_merge_window_minutes`, 15;
  `lib/BurstMerge.php`).
  - The newest typed message gathers the older unanswered ones, each within the window of the next, and answers them
    together. Voice notes are never merged.
  - `tools/sales_followup.php --check burst-drain` reads the marks a rollback would leave, and writes nothing.
- **W.2:** rule 8, *STAY ON DISHNET TOPICS*, among the absolute rules on WhatsApp and the website chat
  (`lib/TopicRule.php`). No setting removes it.
- **W.3:** the customer-care hand-over also gives the office's approved short answer from the knowledge base.
- **W.5:** the AI Knowledge Base screen gets its own tab, an admin-only menu link, and a redirect after a save.
- **W.6:** the uCRM catalogue screen lists a plan whose service-plan and product prices disagree, with both prices; the
  KYC form's admin hint says a plan has two prices and points there.
- **O7**, live at the deploy, with no switch: "Nothing is added on top." leaves the tax lines.
- **Four defect fixes**, live at the deploy:
  - **F-1:** an enquiry follow-up is stored as `DishNet Plugin`, so it never makes the assistant drop a customer's
    message.
  - **F-7:** a reply an opt-out blocked is final, never retried through the model.
  - **F-8:** the assistant's photo and flyer go as replies, so a STOP no longer drops them.
  - **F-11:** the account answers carry the latest invoice's number.
- **Not in this release:** W.4 (`gpt-transcribe` as the code's default) waits on voice step 7's check (`docs/76` §W.4). W.7 is in
  the deploy script and W.8 in the rehearsal's harness.
- **Files:** 115 differ from `bf29cbd`, 75 changed and 40 added (`git diff --name-status bf29cbd 771a3c1`).
  - Runtime: 36 changed and 10 added, plus `SAFETY.md` and `manifest.json` (5.18.106 → 5.18.107).
  - Tests: 37 changed and 30 added. Among them are the 5.18.107 boundary, `tests/fixtures/r107_revert.php`, judged by
    section XB.
- **Every new switch is off.** The owner turns them on one at a time after the deploy: K before Q (with the v10
  settings text), then W.1 (`docs/76` §K).

### Tests, the script, the rehearsal

- **The release's sections.**
  - Sixteen new sections of `tests/test_quote_followup.php` (FX, XB, X, XW, K, KP, KR, Q, QP, QR, QW, WB, WD, TR, HA,
    AK) and section W of `tests/test_catalogue_direct.php`.
  - 279 weakened copies, each caught (the release's `SAFETY.md`, *Tests*).
  - The older boundaries are re-pointed on purpose, never weakened.
- **Suite pass D1, on the first cut `5b6245b`** (27 Nov): 317 files, 23,873 passed, 16 FAIL lines (`docs/76` §T.15).
  - 7 are the container-only checks.
  - 1 is known limit 16's flake (D03).
  - 6 were aged by the calendar: F19 ×2, M02 ×2, QM-M6 and CK12.
  - 2 were new, `test_timezone` and VK1.

  None was a runtime defect. The fixes are tests only.
- **The re-cut, `771a3c1`.** It differs from `5b6245b` in nine test files only. Batch R1 ran its 15 affected files:
  7,633 passed, 0 failed (§T.15).
- **Suite passes E1 and E2 on `771a3c1`:** ⟦PENDING⟧.
- **`scripts/deploy-5.18.107.sh`** (`abf0a25`): derived by 349 asserted edits on 5.18.106's script. 16,306 lines, sha256
  `8b67f903…`; two runs gave the same bytes (§T.16).
  - Its design is `docs/76` §T, reviewed four times. Five reviews of the derived script followed, each fixed in the
    derivation, never in the script (§T.14).
  - Building the rehearsal found two of the script's 092 probes wrong on every migration-built book (§T.16, S-1 and
    S-2). S-2 would have stopped the first deploy NO-GO at stage A on any book, and S-1 on a book carrying views that
    no longer compile; whether production's book does is not established. Both were fixed in the derivation.
- **The rehearsal** (`scripts/harness/deploy-5.18.107/`, chained on 5.18.106's): 2,097 lines, sha256 `d513cc5f…`
  (`f7dbf15`).
  - Built in parts and reviewed in three rounds, every finding fixed; three items are accepted as known limits
    (`wip/rehearsal-5.18.107/design-v3-build-notes.md`).
  - W.8, the stand-in docker's `-u` PATH, is applied again after each snapshot restore.
  - Development trials: ⟦PENDING⟧. The record run: ⟦PENDING⟧.
- **Not pushed.** `release/5.18.107` is pushed, and the script is handed over, only on the owner's word.
