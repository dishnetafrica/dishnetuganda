# 74 — Uganda: 5.18.105, no phone number in any log line (design, 9 Nov 2026)

Status: **built and re-cut — `release/5.18.105` = `7835906` (the first cut, `f764699`, is kept), local, not pushed; the suite's two passes and the rehearsal still to finish; NOT deployed (§S, 14 Nov 2026).** The design below stands as written on 9 Nov. It follows the owner's rulings of 9 Nov (root `docs/07`:16641-16653):

- 5.18.105 masks **every** log line that can carry a phone number on Uganda (option B), not only the four lines of 5.18.104's known limit 13.
- The chase (step 6) does not wait for 5.18.105. Offers-live (step 12) does.
- The report's column run is in scope.

The base is the live release 5.18.104 (`release/5.18.104` = `994d660`). It was deployed on 9 Nov (copy at 03:50:25 UTC; PASSED 231 ok, 0 failed; root `docs/07`:16658-16703).

## A. What 5.18.105 does, in plain words

- **Every Uganda log line that can carry a phone number stops carrying it.** There are 35 edits in 19 files, covering 37 log statements (§H.1). They are the four lines of known limit 13 and 33 more. Examples: every invoice.add in the webhook log, the quotation WhatsApps in `quote_wa_log.json`, the lead and watchdog jobs, and the staff reminders.
- **What takes the number's place:**
  - Usually nothing, because the line already names its record: a job, quote, invoice or payment number, or a name.
  - Where the line named nothing else, a record id: conversation #, client #, lead # or the invoice number.
  - In the webhook log's three phone fields, `••••` and the last two digits. The Sales numbers admin already shows this form (§H.1.22).
- **The report's BY NUMBER table pads by characters, not bytes.** A masked line such as `••••57` and a four-digit count such as `1013` print as two fields again (§H.1.23).
- **Nothing else changes.** Messages, sends, screens and stored records all stay as they are; only the text of those log lines changes. There is no new setting and no migration.
- **The switches stay as they are through the deploy and through any rollback.**
  - The deploy never runs the switch tool.
  - A rollback goes back to 5.18.104 (`994d660`) with every step still running.
  - A rollback brings back the numbers in all 37 statements.
- **Steps:**
  - Steps 1 (report) and 2 (ack) have been on since 9 Nov, at 04:25 and 04:26 UTC (root `docs/07`:16709-16713).
  - Steps 3–5 are planned for 10 Nov.
  - Step 6 (chase) comes on about 13 Nov, after tracking has run 72 hours. It does not wait for 5.18.105.
  - Step 12 (offers-live) waits until 5.18.105 serves and its `--after-only` has passed.
- **On South Sudan:**
  - The lines it shares with Uganda lose their numbers too. Nothing else changes there.
  - Lines only South Sudan runs, and lines nothing runs, are left and recorded (§H.2.1).
  - The exception is `cron/staff_ssp_report.php:123`, which the owner named.
- **Not in 5.18.105:**
  - e-mail addresses in logs;
  - names and amounts, because the ruling covers numbers only;
  - lines only South Sudan runs or that nothing runs (§H.2.1);
  - `notification_audit_log` and `quotes_log.json`, which are stores that code reads back, not logs (§H.2.5);
  - the `071_dpo_payments.sql` checksum mismatch (root `docs/07`:16671-16674).

## B. How to read it

- **Paths.** A bare `path:line` is under `dishnet-hybrid-sudan/` on `release/5.18.104` at `994d660`, read with `git show`. `scripts/…`, `wip/…` and `docs/NN` are the development branch, at the repository root: none of the scripts cited here exists at `994d660`. `docs/NN` is a root document.
- **Ids:**
  - **LN-01…LN-35** are the masked lines (§H.1);
  - **K-01…K-18** are the sites left (§H.1.20, §H.2);
  - **G-1…G-11** are the review's gaps (§B.1).
- **Inputs.** Three inputs were read, nothing else changed:
  - the masking spec, with 48 sites;
  - the deploy and tests spec;
  - an adversarial review of both, with 11 gaps.

  §B.1 settles every gap. Where this text and either spec differ, this text is right.
- **One rule for every line (Q1).** A log line carries no phone number in any form. Names, amounts and record ids are not phone numbers, so they stay.
- **Nothing has run.** Every "proved" names a test still to be written, with a weakened copy that must fail it (§H.1.0).
- **Every automated edit asserts three things.** This covers the build, the tests and the deploy derivation:
  - its anchor is found exactly once, at the cited line;
  - its count: it replaced exactly once;
  - its result: the new text is present once and the old text nowhere.

  Otherwise it exits non-zero.
- **The other sections.** The deploy script, its rehearsal and the plan have their own sections in this document. §B.1 and §B.2 say what those sections must match.

### B.1 The review's eleven gaps, and how each is settled

| Gap | What the review found | Settled as | Where |
|---|---|---|---|
| G-1 | Option B missed two Uganda lines that write a number into `activity_log.json` through `logActivity` (`public.php:730-739`). | **Adopted.** LN-34 (`includes/post/post_kyc.php:269`: the `mobile` and `note` values become `changed`) and LN-35 (`includes/post/post_cashbook.php:612`: the number is dropped). The review cited `:264-267`; at `994d660` the call is `:267-270`. A scan that joins multi-line statements found no third (§H.2.6). Both files join R105_DECLARED, and their holds are re-pointed (§H.1.24). | §H.1.18, §H.1.19 |
| G-2 | `test_quote_company` I4 holds `cron_quote_wa.php` and `post_kyc.php` byte for byte to `ca05ada5`, on the plain judged tree (`tests/test_quote_company.php:162-164`, `:1855-1858`, `:1908-1912`). | **Adopted.** For the files outside `QC_I4_102` (`:113-114`), I4 compares `r105_less($T)`. Its weakened copy, an unmarked edit at `cron_quote_wa.php:114` (the three-minute wait's `< 180`, found once), 98 lines above the file's first 5.18.105 marker and so outside every marker's window (§H.1.0), must still fail I4, and N03 and CD I2 beside it. **Found besides:** `tests/test_cashbook_physical_list.php:1937-1948` holds `post_cashbook.php` byte-identical to `PL_BASE` (`ec0accd4`, `:130`), and reverts only `navigation.php` first. That is a third hold; it is re-pointed the same way. | §H.1.24 |
| G-3 | Section E reused M01's lists, so it fails on this design's own lines and on untouched lines. | **Adopted, phone only.** E1 judges 5.18.105's added lines with a phone-only list. E2 applies the same list to every log statement in the repository, against an allow-list: the K sites, `cron_evo_sync.php`'s three `evoLog` calls and two hits that are not numbers, each keyed by file and the statement's fixed words, never by line number (§H.1.0). M01 still judges the 105-less tree, unchanged. The spec's `PluginLog::mask` test is corrected: mask leaves a 6-digit run by design, because its pattern needs seven characters (`lib/PluginLog.php:52`). Its test is therefore on 9+ digit numbers. | §H.1.0 |
| G-4 | LK-CA2 says `quote_followup_state` never falls. The daily list deletes its `list_last:%` rows every day (`lib/QuoteDailyList.php:542`, inside the sweep's transaction). | **Adopted.** "Never fell" counts only rows `NOT LIKE 'list_last:%'`. The append-only tables keep the rule. A rehearsal case crosses the sweep during the deploy over L2 (steps 1–6 on), the state the deploy is expected to meet now that step 6 does not wait (§B.2 item 1). | the deploy section |
| G-5 | `--after-only` cannot compare LK readings. `MAP_SALT` is drawn per run (`scripts/deploy-5.18.104.sh:10805`), and only one aggregate digest is kept. | **Adopted, the first fix.** An LK key goes in a mode-0600 file beside the state file, following the `QL_KEY_FILE` precedent (`:10806`). The per-key HMAC map goes in the 0600 sheet. `--after-only` re-reads with that key and names the keys that changed, never a value. | the deploy section |
| G-6 | The plan, the LM checks and the rehearsal are sized for option A. | **Adopted.** LM-G1, LM-CA1, LM-CA2 and LM-RB1 take their list from the build's R105_DECLARED hunks (LN-01…LN-35, the helper and the report), never from a fixed four. Each LN says whether the suite alone proves it: the suite alone proves every LN except LN-01…LN-04. The re-estimate takes these inputs: 35 lines; 21 drive-harness groups; 2 new fakes, both under `tests/log_mask/`; 35 line copies plus 5 other weakened copies (E's four, the report's one) and 2 copies through the layer (G-2, §H.1.24); 3 re-points of older holds beyond the tests spec's own list. | §H.1.0, §H.1.24, the plan |
| G-7 | 105-10's sweep with no exclusion would match names the ruling keeps (`scripts/harness/deploy-5.18.104/rehearse.sh:2307-2315`). | **Adopted.** Over the logs 5.18.105 writes, 105-10's sweep looks for numbers only: every fixture number in each written form, plus the 9+ digit rule. The name sweep stays only on paths whose lines carry no name by design. | the rehearsal section |
| G-8 | TL14's planted-read controls would not see an `r105_less` tree cached before the plant. | **Adopted.** Only TL14's main scan (`tests/sales_followup_tool/TL_tool.php:616-627`) reads `r105_less`. The plant scans (`:628-643`) keep `$ctx->tree()`; 5.18.105 does not change the files they plant into. | the tests section |
| G-9 | The plan sends the operator to repeat the quarantine's `--check`. | **Adopted.** It is done: begun 04:39:36 UTC on 9 Nov, PASSED, 9 ok, 0 failed, 2 notes (root `docs/07`:16714-16717). Only the rule is kept: A14 needs a `--check` after any later copy or rollback. | the plan |
| G-10 | `notification_audit_log` and `quotes_log.json` are named as logs and carry the number, but neither is classified. | **Adopted.** §H.2.5 records them, with three more stores, as records that code reads back. E never scans their writers. | §H.2.5 |
| G-11 | LK-G1's "held" refusal would newly cover `settingKeys()`. | **Adopted.** Held is NO-GO only for `allKeys()`, as 5.18.104 counts it (`scripts/deploy-5.18.104.sh:6123-6126`). A setting key in `config.json` is a note, compared in LK-CA1. | the deploy section |

### B.2 Where this text changes the two specs, beyond the review

1. **Step 6 no longer waits (root `docs/07`:16649-16653).** Wherever the deploy and tests spec treats step 6 like step 12, it now names step 12 alone. That means:
   - LK-G1's note in capitals;
   - rb_pre105's notice;
   - the rehearsal's state L4, which is now step 12 on instead of step 6;
   - the plan's sentence "steps 6 and 12 stay off until 5.18.105's `--after-only` has passed".

   From about 13 Nov, step 6 being on during the deploy is expected. It is a plain note.
2. **The two missed lines, LN-34 and LN-35**, are added (G-1).
3. **The empty markers in the webhook log's data become `tail()`'s `none`:** `(EMPTY)` at `webhook.php:1102` and `(none)` at `:1152`. The three structured fields then have one form, and the phone variable appears only as `tail()`'s argument (E1). No code reads either marker:
   - `(EMPTY)` occurs only at `webhook.php:1102`;
   - `tabs/engage/failed_queue.php:316-317` only displays the value.
4. **`tail()` is called with a `(string)` cast** in `webhook.php`, which declares `strict_types` (`webhook.php:2`).
5. **The two new fakes live under `tests/log_mask/`:** the Evolution chat endpoints and a WhatsML feed. The shared `tests/fixtures/fake_evo_server.php` is left alone.

## Q. Questions for the owner

Each question has a default that ships if it is not answered. None holds the build back.

- **Q1 — Only numbers.** Default: a masked line keeps names, amounts and record ids.
  - For example, `cron/staff_ssp_report.php:123` keeps the staff member's name and the SSP and USD balances, and the invoice lines keep the customer's name and the amount.
  - The alternative is 5.18.104's rule for its own new lines: counts and fixed codes only (`SAFETY.md`:580-581; M01). About twenty of the 35 lines would then also lose a name.
- **Q2 — What takes the number's place.** Default: as listed in §A.
  - Nothing, where the line already names its record.
  - A record id, where it named nothing else: LN-01, LN-09, LN-10, LN-25, LN-27, LN-30…LN-32.
  - `••••` and two digits in the webhook log's three phone fields: LN-17…LN-19.
  - The alternative is always nothing.
- **Q3 — Searching by a full number.** Default: accepted. New entries can no longer be found by a full number in:
  - the Failed Queue's CRM Events search (`tabs/engage/failed_queue.php:272`);
  - the Activity Log's filter (`tabs/admin/activity_log.php:28-33`).

  The client id, the invoice number and the name stay searchable.
- **Q4 — Text that holds a number only if someone else put one there** (§H.2.2). This covers exception messages, uCRM's and URA's answers, traces, and the dry-run capture (K-02). Default: left.
  - These are masked only where the line is edited anyway: LN-03, and LN-24 (`evoLog`).
  - The alternative is to pass every exception message in the 19 files through `PluginLog::mask()`. That is 38 lines in `webhook.php` alone. The mask also turns dates and long references into `<number>`.
- **Q5 — The KYC edit line (LN-34).** Default:
  - `mobile` and `note` log `changed`, without their values.
  - The other nine fields keep their before and after values.
  - The full before and after stays in the application's own audit record (`includes/post/post_kyc.php:244-252`), shown on the application (`tabs/sales/applications.php:450-453`).

  The alternative is that every field logs only its name.
- **Q6 — Lines already written.** Default: left. 5.18.105 changes code only and rewrites no data.
  - The capped files drop old entries as new ones arrive: `webhook_log.json` keeps 300 entries, `quote_wa_log.json` 200, `evo_sync.log` 500 and `activity_log.json` 500.
  - `invoice_notify_cron.log`, `data/plugin.log` and PHP's log keep growing until rotated. `ilog` trims nothing (`cron_invoice_notify.php:33-43`).
  - The alternative is a one-off clean-up tool, run by hand and reviewed. It is not designed.
- **Q7 — E-mail addresses.** Default: out of scope. No line is edited for an e-mail address. `PluginLog::mask()` always replaces e-mail-like tokens, so two masks change them in passing:
  - `evoLog`'s JID becomes `<e-mail>` (LN-24);
  - an exception text in LN-03.
- **Q8 — A rollback while step 12 is on.** Default: the rollback is never blocked, because it is an emergency act.
  - Before ROLLBACK is typed, a notice in capitals says that `994d660` writes numbers again in 37 log statements.
  - The notice prints the operator's own `bash scripts/sales-followup.sh off offers-live` alone, on its own line, never in one block with the rollback command (root `docs/44` §16.9).
  - V1's automatic rollback always proceeds.
  - The alternative is to refuse while step 12 is on, unless `--keep-steps-unmasked` is given.

## H.1 The lines, file by file

### H.1.0 How every line is changed and proved

**The edit.**
- Each LN is one hunk on `994d660`. Its builder asserts four things:
  - the old text occurs exactly once in the file, at the cited line;
  - it is replaced once;
  - the result holds the new text once and the old text nowhere;
  - `php -l` passes, and the PHP 7.4 rules hold (`QFU_I_RULES`; the server runs 8.1, root `docs/07`:16627).

  A failed assertion stops the build.
- **The marker.** Each hunk carries a comment `// 5.18.105 (root docs/74 §H.1.n): …` on the line above it. R105_MARK therefore holds by construction: the marker is within the hunk or the 30 lines above it. The r105 layer can then put every hunk back.
- **No new setting.** No hunk reads a `$config` key.
- **The phone variable.** No hunk names a phone variable except as the whole argument of `PluginLog::tail()` or `PluginLog::mask()`.

**Fixtures.**
- Every number is synthetic, `+256700000xyz`, with `xyz` distinct per test (for example `+256700000731`).
- Each number is written in four forms, because uCRM contact fields are free text: E.164 `+256700000731`, digits `256700000731`, national `0700000731`, spaced `0700 000 731`.
- Names are synthetic (`Test …`). Staff appear by role only.

**The drive.**
- Each test drives the real path: a served PHP, a sandboxed cron run, or a direct call with fakes.
- It captures the sink: the file, stdout, or PHP's log through `-d error_log=FILE` or `ini_set('error_log', FILE)`.

**Assertions for every LN.**
- **A1, positive control:** the line is written, found by its fixed words.
- **A2:** no form of the fixture number appears — none of the four forms, and not its last nine digits as one run.
- **A3:** with the line's own timestamp prefix removed, nothing matches `(?<![\d.])\d{9,}(?!\d)`. The decimal guard is needed because `cron_leads.php:258` prints `{$hoursIdle}` as a float, for example `49.166666666667`.
- Each LN's own extra assertions are given under it.

**The weakened copy, LN-nn-w.**
- The copy puts `994d660`'s text back by anchor: the new text is found once and replaced once, and the old text is then present once. The copy must fail A2.
- Each test also runs once on `994d660`'s own file as a control, where A2 must fail.

**A copy that plants an unmarked edit** (one the r105 layer must leave in place, G-2 and §H.1.24, or one that any check marking hunks the same way must find).
- It names its line, at least 31 lines from every 5.18.105 marker in the copied file. A hunk counts as marked when a marker sits in its own added lines or in the 30 lines above it, and a hunk that only closes a block counts as marked too. That is `qf_n_hunks`' rule (`tests/quote_followup/declared.php:277-281`), which the r105 layer applies with 5.18.105's marker. An unmarked edit inside a marker's window would be taken for 5.18.105's own and put back without a word, so the copy would pass.
- Before it is judged, the copy asserts that its line lies outside every marker's window and that its hunk does not only close a block. A failed assertion fails the test; it never skips.

**E, the static scan** (`tests/log_mask/`, section E). It judges phone numbers only, by the owner's ruling.
- **The phone list:**
  - the variables `$phone`, `$to`, `$msisdn`, `$number`, `$digits`, `$recipient`, `$remoteJid` and `$jid`;
  - any other variable, or array element read (for example `$leader['phone']`), whose name contains `phone`, `msisdn`, `jid`, `digits` or `recipient`.
- **E1:** in 5.18.105's added lines, read from Git against `994d660`, no log statement names a listed variable, except as the whole argument of `PluginLog::tail(` or `PluginLog::mask(`. A `(string)` cast before the variable is part of that argument (LN-17…LN-19).
- **E2:** the same list, with E1's exception, over every log statement in the repository, with statements joined across lines.
  - The log functions are: `error_log`, `whLog`, `qwa_log`, `ilog`, `olog`, `evoLog`, `wsLog`, `waLog`, `mlog`, `rclog`, `_cclog`, `cb_log`, `clog_*`, `log_msg_*`, `log_ja`, `pusherLog`, `logActivity` and `PluginLog::write`, plus `echo` and `print` in the CLI jobs (`cron/`, `cron_*.php`, `workers/`).
  - Every hit must match an allow-list entry, which carries its reason.
  - **An entry is keyed by its file and the statement's fixed words:** the statement's string literals, in order, compared whole, with every variable and call left out. Never by line number, because 5.18.105 itself moves lines: in `webhook.php`, the loader after `:129` and the markers of LN-16…LN-19 sit above K-01 (`:2665` at `994d660`). Each entry must match exactly one log statement in its file; an entry that matches none, or more than one, fails, so no entry goes stale. A line number beside an entry is `994d660`'s, for the reader only.
  - The entries:
    - the K sites that name such a variable (§H.1.20);
    - `cron_evo_sync.php`'s three `evoLog` calls, `"Syncing {$phone} ({$jid})..."`, `"  → {$phone}: {$chatMsgs} messages imported"` and `"  → ERROR for {$phone}: " . $e->getMessage()` (`:155`, `:204`, `:213`). They still name `$phone` and `$jid` in their arguments, outside `tail()` and `mask()`; they are masked centrally by LN-24;
    - two hits that are not phone numbers:
      - `includes/api/api_lte.php:750`: `$recipients` holds e-mail addresses, on LTE;
      - `lib/KycService.php:2660`: `$number` is a quotation's number.
  - Any other hit fails, so a new log line that carries a phone fails.
- **What E does not judge:**
  - names, amounts and ids (Q1);
  - store writers, which are not log statements (§H.2.5).
- **M01** (`tests/quote_followup/M_logging.php:97-140`) keeps judging 5.18.104's own lines on the 105-less tree, unchanged.
- **E's weakened copies:**
  - a new `error_log` naming `$phone` in an in-scope file, which E1 and E2 must catch;
  - one K site's allow-list entry removed, which E2 must catch;
  - an entry whose statement no longer matches it (its fixed words changed in a copy of the file), which E2 must catch;
  - `PluginLog::tail()` stubbed to return its argument, which A and E must catch.
- **`PluginLog::mask()`'s own test:** a 9- to 13-digit number in each of the four forms comes out as `<number>`.

**What proves what.**
- The suite proves every LN.
- The rehearsal's 105-5 also drives LN-01…LN-04 through the deployed code. Every other LN is proven by the suite alone. This is the rehearsal's list of driven lines; the rehearsal section follows it.
- The rehearsal's 105-10 sweep judges every log 5.18.105 writes during the run, numbers only, with no exclusion. It proves no single line.
  - It chooses records by their own stamp (`received_at`, `time`, or the line's prefix) at or after the copy, never by byte offset. `whLog`, `qwa_log` and `logActivity` put the newest entry first and rewrite the whole file (`webhook.php:148-157`, `cron_quote_wa.php:924-926`, `public.php:733-738`), and `evoLog` rewrites its file with its last 400 lines once it passes 500 (`cron_evo_sync.php:266-267`). The bytes after a size recorded earlier are therefore old entries, or nothing.
  - In `overdue_cron_status.json` it judges the `output` field only, which is the log. `started_at` and `updated_at` are epoch seconds, ten digits (`cron_overdue_email.php:95`, `:102`).

**What the deploy checks.** The checks read their list from the build's R105_DECLARED hunks.
- **LM-G1, a refusal, on the pin from Git:** for each LN, the new text, taken as one block without its marker, appears once and the old text nowhere. Lines are never counted one by one: LN-03's loader line also stands, unchanged, at `lib/QuotationService.php:851`, and markers may repeat within a file. The control is `994d660`, where the old text appears once and the new text nowhere.
- **LM-CA1:** the same, on the installed files.
- **LM-CA2:** counts the old forms in the sinks the script can read, stamped 60 s or more after COPY_AT. It chooses records, and reads `overdue_cron_status.json`, as 105-10 does (above). It prints counts only, never a line.
- **LM-RB1:** after a rollback, a note in capitals giving the count of statements unmasked again. It reads the installed files block by block, as LM-G1 does.

**Files.** R105_DECLARED's runtime files are:
- the 19 log files: `evo_webhook.php`, `cron/staff_ssp_report.php`, `lib/QuotationService.php`, `lib/WaAutoReplyService.php`, `cron/bidal_summary.php`, `cron/cashbook_reconcile.php`, `cron/cash_carry_reminder.php`, `cron/wa_watchdog.php`, `cron_invoice_notify.php`, `cron_quote_wa.php`, `webhook.php`, `cron_evo_sync.php`, `cron_wa_sync.php`, `cron_overdue_email.php`, `cron_leads.php`, `cron_lead_alerts.php`, `includes/notify_actions.php`, `includes/post/post_kyc.php`, `includes/post/post_cashbook.php`;
- `lib/PluginLog.php`;
- `tools/sales_followup_report.php`;
- `manifest.json`.

Its test files are the ones 5.18.105 adds, and the ones it re-points (§H.1.24) or re-pins (the tests section). The added ones:
- `tests/test_log_mask.php`;
- its sections under `tests/log_mask/`, among them the two new fakes (§B.2 item 5) and the report's section F (§H.1.23);
- `tests/fixtures/r105_revert.php` (§H.1.24), the only new file under `tests/fixtures/`. `tests/fixtures/fake_evo_server.php` is not changed.

**Counts.** The release's own counts are these, and every other section uses them, never the masking spec's site count:
- 35 edits, LN-01…LN-35, in the 19 log files, covering 37 log statements (LN-24's one edit covers three);
- 4 other runtime edits: `webhook.php`'s loader after `:129`, `tail()` in `lib/PluginLog.php`, and the report's two in `tools/sales_followup_report.php`. That makes 21 runtime PHP files, plus `manifest.json`'s version line;
- 35 weakened line copies, one per LN, in 21 drive groups (G-6).

### H.1.1 `evo_webhook.php` — LN-01

**LN-01 — `:381-382` — mask.**
- **Old:** `error_log('[evo_webhook] opt-out recorded for ' . $phone . ' (matched "' . $stop['matched'] . '")');`
- **New:** `error_log('[evo_webhook] opt-out recorded for conversation ' . ($convId ? '#' . (int)$convId : '(not stored)') . ' (matched "' . $stop['matched'] . '")');`
  - `$convId` is set at `:288-290` and is null when the store failed.
  - `$stop['matched']` is a word from ContactOptOut's fixed list (`lib/ContactOptOut.php:233-249`).
  - The form follows `lib/VoiceTranscription.php:245` (`media #%d: opt-out recorded from the transcript (matched "%s")`).
- **Readers:**
  - PHP's log only. No code reads it: `opt-out recorded for` occurs nowhere else.
  - The opt-out every send checks is the ContactOptOut row written at `:371-379`.
  - The 5.18.104 rehearsal's OPTOUT104 (`scripts/harness/deploy-5.18.104/rehearse.sh:2307-2311`) excludes the old form and expects one line of it. That stays true inside 5.18.104's chain. 5.18.105's runs expect 0 lines of the old form and 1 of the new.
  - W2's two strings (`:298`, `:300`; required by `lib/SalesFollowupChecks.php:68-72`) are untouched, so `on chase` still reads `W2: installed`.
- **Uganda:** yes, on every new STOP on any Evolution channel.
- **Proof:** the suite and the rehearsal's 105-5.
  - Harness: F11 (`tests/quote_followup/F_stops.php:257-300`, the served `evo_webhook.php` and a fake Evolution), with PHP started with `-d error_log=FILE`.
  - The drive: a typed STOP on a stored conversation, then a second STOP from the same number.
  - Extra assertions:
    - exactly one line `opt-out recorded for conversation #<id> (matched "stop")`;
    - the second STOP writes no line (`created` is false);
    - a STOP whose store fails writes `(not stored)`.
  - Control: the ContactOptOut row exists.

### H.1.2 `cron/staff_ssp_report.php` — LN-02

**LN-02 — `:123` — mask.**
- **Old:** `error_log("[staff_ssp_report] Sent to {$staffName} ({$phone})  SSP={$sspBal}  USD={$usdExposure}");`
- **New:** `error_log("[staff_ssp_report] Sent to {$staffName}  SSP={$sspBal}  USD={$usdExposure}");` The name and the balances stay (Q1).
- **Readers:**
  - PHP's log, from a CLI master job run daily at 18:00 (`cron/master.php:265`). No code reads it.
  - No test runs the file; `tests/test_currency_sweep.php:36` only exempts it by name.
  - 5.18.104 neither declares nor holds the file, so the r105 layer takes the hunk out before 5.18.104's N01 and before the 5.18.103 and 5.18.102 boundaries' I1 (§H.1.24).
- **Uganda:** conditional.
  - The job returns at `:8-11` unless SSP is bookable.
  - `dn_book_currencies()` defaults to USD,SSP when `cashbook_currencies` is unset (`lib/currency.php:124`). The live vault was not read.
  - On South Sudan the job runs daily.
- **Proof:** the suite and the rehearsal's 105-5.
  - The drive: a South Sudan sandbox (`cashbook_currencies` USD,SSP; one active field staff member with a fixture phone and an SSP `staff_ledger` 'in' row; a fake Evolution that accepts the send), run under `php -d error_log=FILE`.
  - Control: on a UGX,USD book the job prints `SSP is not bookable on this install` and writes no `Sent to` line.
  - Differential (tests section B): every other field of the line equals `994d660`'s.

### H.1.3 `lib/QuotationService.php` — LN-03

**LN-03 — `:1585` — mask.**
- **Old:** `error_log("[QuotationService] WA send failed to {$phone}: " . $e->getMessage());`
- **New**, two lines:
  - `if (!class_exists('PluginLog')) require_once __DIR__ . '/PluginLog.php';`
  - `error_log('[QuotationService] WA send failed (' . $event . '): ' . PluginLog::mask($e->getMessage()));`
  - `$event` is the parameter `sendWA()` already takes (`:1578`): `quote_kyc`, `quote_lead`, `quote_cash` or `quote_manual`.
  - The exception text is unbounded, so it goes through `PluginLog::mask()`. This is the 5.18.102/103 pattern (`:851-853`).
- **Readers:**
  - PHP's log. No code reads it.
  - The callers read only the bool (`:121`, `:233`, `:312`, `:439`), which is unchanged.
  - `quote_agent_copy` H compares PHP's log with `221c9dc`'s (`tests/quote_agent_copy/H.php:24-56`). Its drive calls `createCrmQuote` and `whQuotationEmail` (`tests/quote_agent_copy/drive.php:188`, `:194-204`), never `sendWA`, so H holds.
  - J3 counts exactly 12 hunks (`tests/quote_agent_copy/J.php:130`) and needs the r105 layer (§H.1.24).
- **Uganda:** yes, but rare. The line fires only when `NotificationService::sendVia` throws; its own failures return instead (`lib/NotificationService.php:2332-2347`).
- **Proof:** the suite and the rehearsal's 105-5.
  - The drive: `sendManualQuote`, then `sendLeadQuote`. `$this->ns` is replaced by reflection, as `tests/quote_agent_copy/drive.php:184-186` replaces `crm`, with a NotificationService subclass whose `sendVia` throws an exception that carries the fixture number. `ini_set('error_log', FILE)`.
  - Extra assertions:
    - the line reads `[QuotationService] WA send failed (quote_manual): `;
    - the exception's number appears as `<number>`;
    - the method returns its failure as before.

### H.1.4 `lib/WaAutoReplyService.php` — LN-04

**LN-04 — `:1576` — mask.**
- **The edit:** the statement ends `… (uid:{$bidalUcrmId}) for {$phone}");`. The edit deletes ` for {$phone}`; the anchor `(uid:{$bidalUcrmId}) for {$phone}");` occurs once in the file (measured). The literal earlier in the statement names the support leader and is kept as it is.
- **New ending:** `… (uid:{$bidalUcrmId})");`. The job id identifies the case. The uCRM job carries the client (`:1551`) and the phone in its description (`:1547`).
- **Readers:**
  - PHP's log. No code reads it, and nothing deduplicates by it.
  - The content tests on the file anchor other lines: `tests/test_guard_blocks_send.php:173-186`, `test_payment_details.php:175-179`, `test_plan_fence.php:103-107` and `:170-173`, `test_price_check_plain.php:256`.
  - 5.18.104 does not declare the file (r105 layer).
- **Uganda:** unlikely (inferred). The service is built only by `wa_webhook.php:221` (WASender), `cron_wa_sync.php:198` (the WASender feed) and the admin test action `includes/api/api_whatsapp.php:144`. Uganda's Evolution path does not use it.
- **Proof:** the suite. The rehearsal's 105-5 also drives it, through a WASender stand-in (inferred: one is needed).
  - The drive: `createCrmJobForBidal` by reflection (it is private, `:1488`), with a fake CRM whose post returns id 4711 and a `retailers.json` `support_leader` row with a fixture phone.
  - Extra assertion: `CRM Job #4711 created and assigned to` is present.

### H.1.5 `cron/bidal_summary.php` — LN-05

**LN-05 — `:97` — mask.**
- **Old:** `log_msg_bidal_summary("Morning summary sent to {$leader['name']} ({$leader['phone']}) — pending:{$pending} testing:{$testing} done_today:{$completedToday}");`
- **New:** `log_msg_bidal_summary("Morning summary sent to {$leader['name']} — pending:{$pending} testing:{$testing} done_today:{$completedToday}");`
- **Readers:** `plugin.log` (§H.1.21); no reader reads this line. The once-a-day rule is master's schedule (`cron/master.php:261`), not the log. No test names the file.
- **Uganda:** likely yes. It is an ungated master job at 07:00. It needs any `support_leader` row with a phone, and does not check `is_active` (`:41-46`).
- **Proof:** the suite. The drive: an SjSandbox `$s->run` with a `support_leader` row carrying a fixture phone; stdout is captured.

### H.1.6 `cron/cashbook_reconcile.php` — LN-06

**LN-06 — `:841` — mask.**
- **Old:** `rclog('Sent to ' . $rec['name'] . ' (' . $phone . ')');`
- **New:** `rclog('Sent to ' . $rec['name']);`
- **Readers:** `plugin.log`; none reads this line. The job runs nightly at 23:00 (`cron/master.php:266`). No test names the file.
- **Uganda:** yes. It is an ungated master job with no tenant gate in the file. It needs any active `is_admin` or accountant row with a phone.
- **Proof:** the suite. The drive: a sandbox with one accountant row carrying a fixture phone and a fake Evolution.

### H.1.7 `cron/cash_carry_reminder.php` — LN-07

**LN-07 — `:90` — mask.**
- **Old:** `_cclog("Reminded {$name} ({$phone}): \${$exposure} exposure");`
- **New:** `_cclog("Reminded {$name}: \${$exposure} exposure");`
- **Readers:** `plugin.log`; none reads this line. The once-a-day rule is `$state['reminded']` (`:76`, `:88`), never the log.
- **Uganda:** yes, when an active agent's cash exposure is over the carry limit. It is an ungated master job at 08:00 (`cron/master.php:263`).
- **Proof:** the suite.
  - The drive: one agent whose snapshot exposure is over the limit.
  - Extra assertion: a second run the same day prints no `Reminded` line, which shows the state dedup is untouched.

### H.1.8 `cron/wa_watchdog.php` — LN-08

**LN-08 — `:94` — mask.**
- **Old:** `echo "wa_watchdog: alerted for conv {$c['id']} ({$who}, {$mins} min)\n";`
- **New:** `echo "wa_watchdog: alerted for conv {$c['id']} ({$mins} min)\n";`
  - `$who` is the display name, or the phone when there is none (`:85`).
  - The alert text sent to staff (`:89`) keeps `$who`. It is a message, not a log, and is unchanged.
- **Readers:** `plugin.log`. The dedup is `wa_watchdog_state.json` (`:64-67`, `:95`) plus the AlertService key `watchdog:<id>:<time>` (`:87-88`).
- **Uganda:** likely yes. The job runs every 900 s (`cron/master.php:219`) in alert hours, with an alert number set.
- **Proof:** the suite.
  - Harness: `tests/test_pilot_safety.php:757`'s, with an unanswered conversation that has no `display_name`.
  - That test's weakened copy anchors `:1565-1566`, another line (verified).

### H.1.9 `cron_invoice_notify.php` — LN-09, LN-10

Both lines write to INV (§H.1.21).

**LN-09 — `:175` — mask.**
- **Old:** `ilog("SENT: #{$invoiceNum} " . dn_code($config) . " {$total} → {$fullName} ({$phone})");`
- **New:** `ilog("SENT: #{$invoiceNum} " . dn_code($config) . " {$total} → {$fullName} (client #{$clientId})");`
  - `$clientId` is set at `:106`.
  - This follows the older Uganda convention: log an id, never the number (`lib/InvoiceReminders.php:166`).
- **Readers:**
  - No code reads INV; `:323` only names it in an alert text.
  - `tests/test_reminders_one_path.php:254` and `:357` read INV only for `Fetched`.
  - The dedup is `notification_dedup` through `dedupMark` (`:75`, `:130-132`), shared with `webhook.php`.
- **Uganda:** yes, and on Uganda only, because the file is written only where `$_ilogUg` is set (`:37`). It runs every 900 s (`cron/master.php:246`), in daytime.
- **Proof:** the suite.
  - Harness: NotifyHarness, as in `tests/test_reminders_one_path.php:251-254`, with an unclaimed invoice for a client with a fixture phone.
  - Extra assertion: `SENT: #<n> … (client #<id>)`.

**LN-10 — `:356` — mask.**
- **Old:** `ilog("RENEWAL REMIND: {$c['svcName']} \${$c['price']} -> {$custName} ({$custPhone}) renews {$c['nextBilling']}");`
- **New:** `ilog("RENEWAL REMIND: {$c['svcName']} \${$c['price']} -> {$custName} (client #{$c['clientId']}) renews {$c['nextBilling']}");` The candidate carries `clientId` (`:300`).
- **Readers:** as LN-09. The renewal dedup is `renewal_remind_log.json[$c['rKey']]` (`:358`).
- **Uganda:** yes, after the renewal first-run dry run, for services renewing inside the window.
- **Proof:** the suite. The same harness, second run after the first-run stamp.

### H.1.10 `cron_quote_wa.php` — LN-11 … LN-15

All five lines write to QWA (§H.1.21).

**Common to the five:**
- 5.18.104 holds the file byte for byte (QF_HELD F and P). So do `test_quote_company` I4 and `CD_I_KEPT`. The re-points are in §H.1.24.
- It is an ungated master job every 300 s (`cron/master.php:178`).
- The dedups are `wa_sent_quotes` (`:197`, `:337`) and, for receipts, `$_receiptSentIds` and `receipt_pdf_queue.json` (`:491`, `:665-668`). None uses the log.
- Harness: `tests/test_quote_cron_log.php`'s (SjSandbox, `:44-56`).
- `tests/test_kyc_crm_messages.php:365`, `:375` and `tests/test_notify_quote_once.php:140` read other lines.

**LN-11 — `:212` — mask.**
- **Old:** `qwa_log(qwa_said($notify, 'TEXT', $_qwaSaid) . " KYC app #{$appId} quote #{$quoteId} {$quoteRef} → {$phone}");`
- **New:** `qwa_log(qwa_said($notify, 'TEXT', $_qwaSaid) . " KYC app #{$appId} quote #{$quoteId} {$quoteRef}");`
- **Uganda:** yes, on Flow A: KYC quotations are deferred to this job (`lib/KycService.php:2660`).
- **Proof:** the suite, on a deferred KYC quotation.

**LN-12 — `:229` — mask.**
- **Old and new:** as LN-11, with `'PDF'` in place of `'TEXT'`.
- **Uganda:** yes, when the PDF is available.
- **Proof:** the suite, in the same Flow A run with a PDF served by the fake CRM.

**LN-13 — `:347` — mask.**
- **Old:** `qwa_log(qwa_said($notify, 'TEXT', $_qwaSaid) . " UCRM quote #{$qId} {$qNumber} → {$phone} ({$clientName})");`
- **New:** `qwa_log(qwa_said($notify, 'TEXT', $_qwaSaid) . " UCRM quote #{$qId} {$qNumber} → {$clientName}");` `$clientName` comes from `_qwa_resolveName` (`:301`).
- **Readers:** `tests/test_quote_cron_log.php:50-53` keeps lines that start `TEXT (NOT )?SENT` and contain `Q-3401`. Both still hold.
- **Uganda:** yes, on Flow B (quotations made in uCRM).
- **Proof:** the suite. The existing Q-3401 case is extended so the client's contact phone is a fixture number.

**LN-14 — `:433` — mask.**
- **Old:** `… " from pending queue: #{$pqNum} → {$pqName} ({$pqPhone})");`
- **New:** `… " from pending queue: #{$pqNum} → {$pqName}");`
- **Uganda:** possible. It needs the webhook's auto-approve PDF queue (`:395-440`).
- **Proof:** the suite, with one pending entry (older than 30 s, fresher than 1 h) carrying a fixture phone.

**LN-15 — `:670` — mask.**
- **Old:** `qwa_log("Receipt PDF SENT: PAY-{$rPayId} → {$rName} ({$rPhone}) — " . round(strlen(base64_decode($pdfRaw))/1024) . " KB");`
- **New:** `qwa_log("Receipt PDF SENT: PAY-{$rPayId} → {$rName} — " . round(strlen(base64_decode($pdfRaw))/1024) . " KB");`
- **Readers:** `receipt_pdf_debug` (`includes/api/api_cron_debug.php:892-910`) still finds the line by `Receipt`.
- **Uganda:** possible. It needs a queued receipt.
- **Proof:** the suite, with one `receipt_pdf_queue.json` entry carrying a fixture phone.

### H.1.11 `webhook.php` — LN-16 … LN-23

All eight lines write to WH (§H.1.21).

**Common to the eight:**
- 5.18.104 declares the file (P). `quote_agent_copy` J3 counts its hunks, and `CD_I_KEPT` and QFU I keep it. All are re-pointed through `r105_less` (§H.1.24).
- No test reads `data.phone`.
- Harnesses: `tests/fixtures/notify_harness.php` or `tests/fixtures/staff_jobs_sandbox.php` with `fire(...)`.
- **One added hunk after `:129`:**
  - `require_once __DIR__ . '/lib/PluginLog.php';`, with its marker. `webhook.php` loads no PluginLog today.
  - The anchor is the two lines `require_once __DIR__ . '/lib/CustomerEmailDispatcher.php';` and `require_once __DIR__ . '/lib/currency.php';` at `:128-129`, which occur together once. `currency.php` alone is required twice, at `:9` and `:129`.
  - Every other loader of PluginLog uses `require_once` (measured), so a second load is harmless.

**LN-16 — `:952-953` — mask.**
- **Old:** `whLog($changeType, "Welcome sent to {$name} ({$phone})" . ($kycLikeCrm ? ' — KYC customer, kyc_messages_like_crm' : ''), ['crm_id' => $clientId]);`
- **New:** `whLog($changeType, "Welcome sent to {$name}" . ($kycLikeCrm ? ' — KYC customer, kyc_messages_like_crm' : ''), ['crm_id' => $clientId]);` The client id is already in the entry's data.
- **Readers:**
  - `tests/test_kyc_crm_messages.php:344` requires `Welcome sent to Test Onboarded` and `kyc_messages_like_crm`. Both stay.
  - The badge words `sent to` (`includes/navigation.php:155`) stay.
- **Uganda:** yes, on `client.add` for a client made in uCRM, or a KYC client with `kyc_messages_like_crm`.
- **Proof:** the suite. The drive: `client.add` for a client whose contact phone is a fixture number. `:344` must still pass.

**LN-17 — `:1102` (the entry at `:1097-1106`) — mask.**
- **Old:** `'phone'        => $phone ?: '(EMPTY)',`
- **New:** `'phone'        => PluginLog::tail((string)$phone),`. This gives `••••` and two digits, or `none` (§H.1.22, §B.2).
- **Readers:**
  - The CRM Events tab shows the `phone` key (`tabs/engage/failed_queue.php:316-317`) and searches the message plus the data JSON (`:272`).
  - `client_id` in the same data (`:1100`) stays searchable (Q3).
  - The dedup is `dedupMark`, shared with `cron_invoice_notify.php:75`.
- **Uganda:** yes, on every non-draft unpaid or partial `invoice.add`.
- **Proof:** the suite. The drive: `invoice.add` for a client with a fixture phone and for a client with none.
  - Extra assertions:
    - `data.phone` is `••••31` for the first and `none` for the second;
    - the CRM Events page (`fqsub=crm_events`) shows no fixture form.

**LN-18 — `:1152` — mask.**
- **Old:** `'client_id' => $clientId, 'phone' => $phone ?: '(none)', 'amount' => $amount,`
- **New:** `'client_id' => $clientId, 'phone' => PluginLog::tail((string)$phone), 'amount' => $amount,`
- **Readers:** the message `Skipped — no phone or zero amount` keeps its `skipped` class.
- **Uganda:** yes, on a zero-amount invoice for a client with a phone.
- **Proof:** the suite.

**LN-19 — `:1706` — mask.**
- **Old:** `whLog($changeType, "Service.add logged (no notification - status={$status}, phone=" . ($phone ?: 'none') . ")");`
- **New:** `whLog($changeType, "Service.add logged (no notification - status={$status}, phone=" . PluginLog::tail((string)$phone) . ")");` The empty case still prints `none`.
- **Readers:** the class stays `skipped`, through the words `no notification`.
- **Uganda:** yes, on `service.add` with a status other than active.
- **Proof:** the suite. Extra assertion: `phone=••••31`.

**LN-20 — `:3092` — mask.**
- **Old:** `whLog($changeType, "Quote #{$quoteNum} → preparing PDF+caption for {$name} ({$phone})", ['amount' => $amountFmt]);`
- **New:** `whLog($changeType, "Quote #{$quoteNum} → preparing PDF+caption for {$name}", ['amount' => $amountFmt]);`
- **Readers:** `quote_agent_copy` H extracts only `whLog` and `whQuotationEmail` by function (`tests/quote_agent_copy/drive.php:194`), never this switch arm.
- **Uganda:** yes. The block has a Uganda branch for quotations typed in uCRM.
- **Proof:** the suite, with `quote.add` on Uganda, as `tests/test_notify_quote_once.php` fires it.

**LN-21 — `:3345` — mask.**
- **Old:** `whLog($changeType, "Credit note notification SENT: #{$cnNum} → {$name} ({$phone})");`
- **New:** `whLog($changeType, "Credit note notification SENT: #{$cnNum} → {$name}");`
- **Readers:** the badge word `notification ` stays. The CREDIT_NOTE dedup sits before the send, not in the log.
- **Uganda:** yes, on the first delivery after that dedup.
- **Proof:** the suite.

**LN-22 — `:3388` — mask.**
- **Old:** `whLog($changeType, "Client message forwarded to WA: {$name} ({$phone})");`
- **New:** `whLog($changeType, "Client message forwarded to WA: {$name}");`
- **Uganda:** yes, on the `client.message` event.
- **Proof:** the suite.

**LN-23 — `:3701` — mask.**
- **Old:** `whLog($changeType, "Alert sent to accountant: {$acctPhone}");`
- **New:** `whLog($changeType, "Alert sent to accountant");`
- **Readers:** the recipient is fixed: the first accountant or admin row with a phone (`:3691-3698`). The words `sent to` stay for the badge and the class.
- **Uganda:** possible. It needs a payment delete that reversed a cashbook entry or voided a collection.
- **Proof:** the suite, with an accountant row carrying a fixture phone.

### H.1.12 `cron_evo_sync.php` — LN-24

**LN-24 — `:261`, inside `evoLog` (`:257-270`) — mask, the one central edit.**
- **Old:** `$line = '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL;`
- **New**, two lines:
  - `if (!class_exists('PluginLog')) require_once __DIR__ . '/lib/PluginLog.php';`
  - `$line = '[' . date('Y-m-d H:i:s') . '] ' . PluginLog::mask($msg) . PHP_EOL;`
- **What it covers:** `:155` (`Syncing {$phone} ({$jid})...`: the JID becomes `<e-mail>`, the number `<number>`), `:204`, and `:213`, whose exception text is masked too.
  - The other `evoLog` messages (`:69-251`) are counts, instance names and statuses, so the mask leaves them as they are.
  - One hunk replaces three, in a file 5.18.104 holds byte for byte (QF_HELD V).
- **Readers:**
  - EVO: `tabs/engage/whatsapp.php:1891-1897` counts the last 10 lines.
  - The sync state is `evo_sync_state.json` (`:150`, `:214-216`), never the log.
- **Uganda:** unknown.
  - The job needs `evo_api_url`, `evo_api_key` and the legacy `evo_instance_name` (`:40-47`).
  - Uganda may set only `evo_instance_sales`, `evo_instance_support` and `evo_instance_account` (`lib/EvolutionApiService.php:146-156`).
  - The live vault was not read. The job runs every 300 s (`cron/master.php:243`).
- **Proof:** the suite.
  - The drive: a new fake Evolution under `tests/log_mask/` serving `chat/findChats` and `chat/findMessages`, with `evo_instance_name` set.
  - Extra assertions:
    - `Syncing <number> (<e-mail>)...` and `→ <number>: N messages imported`;
    - a chat whose messages throw gives `→ ERROR for <number>: …`;
    - the count lines are unchanged.
  - Weakened copy: `evoLog` without `PluginLog::mask`.

### H.1.13 `cron_wa_sync.php` — LN-25

**LN-25 — `:215-216` — mask.**
- **Old:** `wsLog("  {$inbound['modality']} from {$phone} on {$channel}: {$res['action']}" . ($silent ? ' (not answered)' : ''));`
- **New:** `wsLog("  {$inbound['modality']} from conv #" . (int)($conv['id'] ?? 0) . " on {$channel}: {$res['action']}" . ($silent ? ' (not answered)' : ''));` `$conv` is set at `:208`.
- **Readers:**
  - `plugin.log`.
  - The cursor is in `wa_sync_state.json`. Duplicates are caught by the processor (`$res['duplicate']`, `:213`).
  - `tests/test_wa_entry_convergence.php:252-262` only scans the code for function names.
- **Uganda:** unknown. The job is on unless `wa_sync_enabled` is false (`:47`) and runs every 60 s (`cron/master.php:166`). The live value was not read.
- **Proof:** the suite.
  - The drive: a fake WhatsML feed under `tests/log_mask/`, answering `{ok:true, rows:[one inbound row from a fixture number]}`, with `wa_feed_url` pointing at it.
  - Extra assertion: `<modality> from conv #<id> on <channel>:`.

### H.1.14 `cron_overdue_email.php` — LN-26, LN-27

**Readers of both:**
- OVD (§H.1.21).
- The dedup is the `overdue_email_log` table (`$dedupKey`, `:398`).
- `tests/test_notify_ladder_record.php:93-145` runs the job. Its weakened copies anchor `:142` and `:146`, other lines (verified).
- The e-mail lines `:375`, `:387` and `:391` carry addresses, not numbers, and are left (Q7).

**Uganda, for both:** unknown. The job runs weekly, Monday 09:00 (`cron/master.php:247`). It stops when `billing_model` is prepaid; an unset value counts as postpaid (`lib/OverdueDunningHelpers.php:98-107`). The live value was not read.

**LN-26 — `:417` — mask.**
- **Old:** `olog("WHATSAPP stage {$matchedStage['id']} ({$matchedStage['label']}) → {$fullName} {$phone} — {$invNum} {$amtFmt}");`
- **New:** `olog("WHATSAPP stage {$matchedStage['id']} ({$matchedStage['label']}) → {$fullName} — {$invNum} {$amtFmt}");`

**LN-27 — `:442` — mask.**
- **Old:** `olog("WHATSAPP ERROR stage {$matchedStage['id']} → {$phone}: " . $e->getMessage());`
- **New:** `olog("WHATSAPP ERROR stage {$matchedStage['id']} → {$invNum}: " . $e->getMessage());` `$invNum` is set at `:285`. The exception text is left (Q4).

**Proof of both:** the suite.
- Harness: `tests/test_notify_ladder_record.php:93-94`'s (postpaid; an invoice matching a WhatsApp stage), then a run whose sender throws.
- Both stdout and `overdue_cron_status.json`'s `output` are judged.

### H.1.15 `cron_leads.php` — LN-28, LN-29

**Common to both:**
- `customer_name` is the phone for an AI lead that has no name (`lib/AiLeadService.php:301`; `includes/api/api_leads.php:395`). The lead id identifies the lead.
- **Readers:** `plugin.log`. Reassignment is recorded in the lead's history (`:248-253`, `:263-268`).
- `tests/test_sales_numbers.php:701` runs the job. Its weakened copy anchors `:1564-1566`, another line (verified).
- **Uganda:** likely. The `leads` master job runs every 14,400 s, ungated (`cron/master.php:274`).

**LN-28 — `:258` — mask.**
- **Old:** `clog_leads("  ⟲ Lead #{$l['id']} ({$l['customer_name']}) reassigned from {$oldAgName} → {$newAg['name']} ({$hoursIdle}h idle)");`
- **New:** `clog_leads("  ⟲ Lead #{$l['id']} reassigned from {$oldAgName} → {$newAg['name']} ({$hoursIdle}h idle)");`

**LN-29 — `:270` — mask.**
- **Old:** `clog_leads("  ⚠ Lead #{$l['id']} ({$l['customer_name']}) flagged stale ({$hoursIdle}h idle)");`
- **New:** `clog_leads("  ⚠ Lead #{$l['id']} flagged stale ({$hoursIdle}h idle)");`

**Proof of both:** the suite.
- Harness: `tests/test_sales_numbers.php:701`'s, with one lead old enough to be reassigned and one old enough to be flagged, each named by a fixture number in the `AiLeadService::newLead` form.
- A3's decimal guard is exercised here.

### H.1.16 `cron_lead_alerts.php` — LN-30 … LN-32

**Common to the three:**
- `$leadName` is `customer_name` (`:105`): the phone for an AI lead that has no name. `$leadId` is set at `:108`.
- **Readers:** `plugin.log`. The dedups are the lead flags `wa_assigned_notified`, `wa_45min_sent` and `wa_60min_sent` (`:125`, `:152`, `:180`).
- **Uganda:** likely. The job runs every 300 s (`cron/master.php:275`) and is on unless `lead_alert_enabled` is false (`:41-48`).

**The edits:**
- **LN-30 — `:128`:** `… TASK A: Notified {$agent['name']} → {$leadName}\n"` becomes `… TASK A: Notified {$agent['name']} → lead #{$leadId}\n"`.
- **LN-31 — `:155`:** `… TASK B: 45-min warning to {$agent['name']} → {$leadName}\n"` becomes `… TASK B: 45-min warning to {$agent['name']} → lead #{$leadId}\n"`.
- **LN-32 — `:183`:** `… TASK C: Escalated {$leadName} ({$agent['name']}) to supervisor\n"` becomes `… TASK C: Escalated lead #{$leadId} ({$agent['name']}) to supervisor\n"`.

**Proof of all three:** the suite.
- The drive: an assigned lead named by a fixture number, timed so that tasks A, B and C fire.
- One weakened copy per line.

### H.1.17 `includes/notify_actions.php` — LN-33

**LN-33 — `:23` — mask.**
- **Old:** `logActivity($dataDir, 'notify_install', "Install confirmation sent to {$name}", "Phone:{$phone} Date:{$date}");`
- **New:** `logActivity($dataDir, 'notify_install', "Install confirmation sent to {$name}", "Date:{$date}");`
- The flash at `:24` echoes the number the admin just typed, on the admin's own screen (the session). It is not a log, so it is left.
- **Readers:**
  - ACT (§H.1.21). A search by number no longer finds this entry (Q3); the name stays.
  - Nothing deduplicates by ACT.
- **Uganda:** yes, when an admin uses Notify → Installation Confirmed, a manual action.
- **Proof:** the suite. The drive: `POST action=notify_install_confirmed` (`:9`) as admin through the served plugin, with the CSRF token taken from the page.

### H.1.18 `includes/post/post_kyc.php` — LN-34 (review G-1)

**LN-34 — `:269` (the call at `:267-270`) — mask.**
- **Old:** `implode(', ', array_map(fn($f,$v) => "{$f}: '{$v['from']}' → '{$v['to']}'", array_keys($changes), $changes))`
- **New:** `implode(', ', array_map(fn($f,$v) => in_array($f, ['mobile', 'note'], true) ? "{$f}: changed" : "{$f}: '{$v['from']}' → '{$v['to']}'", array_keys($changes), $changes))`
  - The admin may correct eleven fields (`:222-223`). `mobile` is the number itself, and `note` is free text an admin types (Q5).
  - The marker goes above `:267`, inside the hunk's window.
  - `:268`, the title, names the application and the editing admin. It holds no number and is unchanged (Q1).
- **Readers:**
  - ACT. The full before and after stays in the application's own `audit_log` (`:244-252`), shown at `tabs/sales/applications.php:450-453`.
  - No test reads `kyc_edit` (git grep).
  - The file is held byte for byte by 5.18.104 (QF_HELD F, `tests/quote_followup/declared.php:170`), by `CD_I_KEPT` (`tests/catalogue_direct/I_boundary.php:53`) and by `test_quote_company` I4 (`:1856`). The re-points are in §H.1.24.
- **Uganda:** yes, when an admin edits a KYC application (the form at `tabs/sales/applications.php:481`). KYC is Uganda's flow.
- **Proof:** the suite.
  - The drive: `POST action=kyc_admin_edit` as admin through the served plugin, with the CSRF token from the page, changing `mobile` to a fixture number and `note` to a text that holds one.
  - Extra assertions:
    - the detail reads `mobile: changed, note: changed`;
    - control: a `firstname` change in the same post still shows its before and after.

### H.1.19 `includes/post/post_cashbook.php` — LN-35 (review G-1)

**LN-35 — `:612` (the call at `:611-612`) — mask.**
- **Old:** `"Refund WA sent to {$refundName} ({$refundPhone}): \${$_refAmt}", '');`
- **New:** `"Refund WA sent to {$refundName}: \${$_refAmt}", '');`
  - The marker goes above `:611`, the call's first line, inside the hunk's window, as LN-34's does. `tests/fixtures/r105_revert.php` removes exactly that line (§H.1.24).
- **Readers:**
  - ACT. No test reads `crm_refund_wa_sent` (git grep).
  - The file is held byte-identical to 5.18.97 by `tests/test_bank_boundaries.php` A and F (through `src102`, `:142`, `:218-240`, `:263`) and by `tests/test_cashbook_physical_list.php` M (`:1937-1948`). The re-point is in §H.1.24.
  - `tests/test_cashbook_currency.php:131` pins the `USD`/`SSP` literal counts in this file. The edit changes neither.
  - `tests/test_staff_cash_chain.php:276-278` anchors another line.
- **Uganda:** yes. It runs when an accountant records an 'out' entry in Customer Refund or Customer Commission that names a uCRM client (`:538-546`). The block has no tenant gate.
- **Proof:** the suite.
  - Harness: SjSandbox, which `tests/test_staff_cash_chain.php` already uses to drive this file.
  - The drive: a refund naming client #123, a fake CRM answering `refunds` with an id and `clients/123` with a contact carrying a fixture phone, and a fake Evolution.
  - The exception text at `:616` is left (§H.2.2).

### H.1.20 The sites left — K-01 … K-18

Each is in §H.2 with its class. **The test column:** a K site that names a phone variable is an E2 allow-list entry, keyed by its file and the statement's fixed words, with its reason, so any change there is seen. The lines in the table are `994d660`'s, for the reader (§H.1.0, E2). The others name no phone variable; E2 does not flag them.

| K | Site | Decision | Uganda | Why | Readers | Test |
|---|---|---|---|---|---|---|
| K-01 | `webhook.php:2665`, `'tech_phone' => $techPhone` (`:2664-2668`) | out of scope | no: only when `StaffJobsGate::applies` is false (`:2591`, branch `:2651`); South Sudan | a mask changes the WA Events page that `tests/test_staff_jobs_south_sudan.php` requires byte-equal to 5.18.49's (`:107`, `:155-159`) | WH | allow-list |
| K-02 | `lib/NotificationService.php:186-196`, the dry-run capture (phone, 200 bytes of message, `vars`) | out of scope (Q4) | only while `dry_run_mode` is on (`:154`); off by default; live value not read | a deliberate capture whose purpose is to show the message; three fields; the file is declared changed by 5.18.104 (F) | `data/dry_run_notification_log.json`; `tools/wa_lifecycle_test.php:92-98` looks entries up by event | not a log function (§H.2.5) |
| K-03 | `lib/FiberInstallService.php:318` | out of scope | no (inferred): the fibre flow; `profiles/uganda.json` lists only `starlink` | — | PHP's log | allow-list |
| K-04 | `wa_webhook.php:214`, `:227`, `:242` (`data.phone`; `:242` also 300 characters of a trace) | out of scope | unlikely (inferred): the WASender webhook; Uganda runs on Evolution | held byte for byte by 5.18.104 (QF_HELD V) | `data/wa_webhook_log.json`; `includes/api/api_whatsapp.php:39-47` | allow-list |
| K-05 | `cron_maintenance.php:794`, `:1266` | out of scope | no: on Uganda these tasks print MOVED (`:69`, `:712`, `:1207`) | — | `plugin.log` | allow-list |
| K-06 | `cron_maintenance.php:1163` | out of scope | effectively no on either tenant: task 6 needs hour ≥ 17 (`:1081`); master runs maintenance at 02:00 (`cron/master.php:267`) | — | `plugin.log`; a forced run's output goes to the admin's browser (`api_cron_debug.php:941`) | allow-list |
| K-07 | `cron/cashbook_summary.php:192` | out of scope | no on a book without SSP: `:57-109` logs counts and returns | — | `plugin.log` | allow-list |
| K-08 | `cron/staff_jobs_summary.php:261` | out of scope | no: the else-branch of NotifyGate STAFF_SIDE; the Uganda branch (`:248-257`) logs no number | — | `plugin.log` | allow-list |
| K-09 | `cron/job_assignment_notify.php:90`, `:226`, `:247`, `:297` | out of scope | no: not dispatched (`cron/master.php:189` is commented out), included nowhere | dead code | none | allow-list |
| K-10 | `cron_lte_usage.php:136` | out of scope | no (inferred): returns when Magma is not configured (`:94`) | — | `plugin.log` | allow-list |
| K-11 | `dishnet_wa_pusher.php:242`, `:302`, `:308`, `:314` | out of scope | no: runs on the South Sudan WASender host, not under the plugin's master | an edit proves nothing at runtime | that host's stdout | allow-list |
| K-12 | `workers/lte/worker_jobs.php:486`, `workers/lte/worker_report.php:183` | out of scope | no: LTE workers the plugin does not dispatch | as K-11 | stdout | allow-list |
| K-13 | `lib/error_handler.php:27`, 1,000 characters of an uncaught exception's trace | leave (Q4) | possible, on any uncaught exception whose stack holds a phone argument, unless `zend.exception_ignore_args` is On (not read on the server) | a mask would collapse the trace's newlines and cut it to 400 characters, on both tenants | PHP's log | — |
| K-14 | `lib/KycService.php:2628` (uCRM's quotation answer), `:877` (uCRM's 4xx body after a client create) | leave (Q4) | the path runs; a number appears only if uCRM echoes one (not established) | held byte for byte by 5.18.104 (QF_HELD F, P) | PHP's log | — |
| K-15 | `lib/CashbookService.php:1186`, SQL parameters (50 characters each) after a failed write | leave (Q4) | possible, on a database failure; a phone only if the statement carried one (not established) | — | `data/cashbook_errors.log`; `api_cron_debug.php:1194-1224`, `:1338-1352` (display) | — |
| K-16 | `lib/EfrisService.php:319`, URA's error text | leave (Q4) | the path runs; a number only if URA echoes the buyer's phone (`lib/EfrisInvoiceMapper.php:88-135`); not established | — | `data/efris.log`; no reader found | — |
| K-17 | `webhook.php:991`, the whole invoice entity when the event has no invoice id | leave (Q4) | possible, rare; the entity is probably empty without an id (inferred) | — | WH | — |
| K-18 | `tools/wa_conversation.php:55`, `:84` and the other operator tools (`tools/wa_answering.php:128`, `tools/followup_doctor.php:122`, `:158`, …) | leave | no automatic capture: neither master nor the deploy runs them; `scripts/sales-followup.sh` captures only `tools/sales_followup.php`, whose output 5.18.104 keeps free of numbers | the operator's own terminal, not a log | the terminal | not log functions |

### H.1.21 The shared sinks: edited line by line, except `evoLog`

| Sink | Writer | Readers | Central mask? | Why |
|---|---|---|---|---|
| PHP's log | `error_log()` (built-in) | no code reader; `scripts/deploy-5.18.104.sh` `log_since` (`:1572-1576`) counts other lines (QF-N6 `:6411`, OF-N2 `:8324`) | no | no hook short of an ini change; `lib/error_handler.php:25-27` is central only for uncaught exceptions (K-13) |
| `plugin.log` (master's stdout) | `log_msg_bidal_summary`, `rclog`, `_cclog`, the watchdog's echo, `wsLog`, `olog`, `clog_leads`, the lead alerts' echo | `lib/SalesFollowupChecks.php:299-325` (`hook_write_lost`), `tools/sales_followup.php:845-850` (`SLOW JOB`), deploy QF-N5 `:6394-6406`, Q-CA3 `:5070-5304`; none reads a masked line | no | an `ob_start` filter in `cron/master.php` would catch every echo, but master is held and pinned (`MASTER_PIN_SHA256` and `MASTER_BASE_SHA256`, `scripts/deploy-5.18.104.sh:1358-1359`; A15 `:11575-11590`), the filter would mask each job's own `[YYYY-MM-DD HH:MM:SS]` prefix that `tools/sales_followup.php:845-850` and QF-N5 read, and a forced run through `api_cron_debug` bypasses master; `rclog`, `_cclog` and `cb_log` build their prefix inside the function |
| WH, `data/webhook_log.json` | `whLog` (`webhook.php:139-158`; 300 entries; `JSON_UNESCAPED_UNICODE`) | `tabs/engage/failed_queue.php:262-323` (classes by message words `:108-122`, search `:272`, data keys `:316-317`); `tabs/engage/whatsapp.php:364-367`, `:1412-1420`; `tabs/admin/settings.php:1512-1514`, `:1635-1644`; `includes/navigation.php:143-157` (badge words `:155`); `includes/api/api_cron_debug.php:311-319`, `:1006-1008`; `tools/mail_log_doctor.php:99-140`; `tools/quote_email_doctor.php:62-112`; `scripts/deploy-5.18.104.sh:11318-11322` | no | a free-text mask would turn `whQuotationEmail`'s `Quotation email sent to {$r['to']}` (`:314`) into `<e-mail>`, which `quote_agent_copy` H compares with `221c9dc`'s (`tests/quote_agent_copy/H.php:24-56`), so H fails and the scope widens to e-mail; the mask regex also eats dates, times and 7+ digit references shown on four screens; a key-walk over `data` would also mask K-01 |
| QWA, `data/quote_wa_log.json` | `qwa_log` (`cron_quote_wa.php:915-927`; 200 entries) | `includes/api/api_cron_debug.php:850-854` (30 entries), `:892-910` (receipts); the tests in §H.1.10 | no | the other lines carry a datetime (`:272`), receipt URLs (`:526`, `:552`, `:600`) and PDF paths (`:796`), shown by `api_cron_debug` |
| INV, `data/invoice_notify_cron.log` | `ilog` (`cron_invoice_notify.php:33-43`) | none in code; the tests read `Fetched` | no | the renewal lines carry dates and amounts |
| EVO, `data/evo_sync.log` | `evoLog` (`cron_evo_sync.php:257-270`) | `tabs/engage/whatsapp.php:1891-1897` (counts) | **yes** (LN-24) | every other message is a count, an instance name or a status; one hunk replaces three in a held file |
| OVD, stdout and `overdue_cron_status.json` | `olog` (`cron_overdue_email.php:76-107`) | `tabs/admin/overdue_email_log.php:112-166` (live output) | no | the header (`:109`) prints `date('Y-m-d H:i:s')`, which the mask would eat |
| ACT, `data/activity_log.json` | `logActivity` (`public.php:730-739`; 500 entries; 103 callers) | `tabs/admin/activity_log.php:5-34`; the CSV export (`includes/post_handlers.php:52-58`); `api_cron_debug.php:1344-1350` (cashbook actions only); backups (`includes/post/post_sync.php:1674`, `:1689`) | no | details carry dates (`Date:…`) and amounts |
| `wsLog` | `cron_wa_sync.php:40-42` | `plugin.log` | no | central is possible, but the cursor figures (`:68`, `:230`, `:244`) may reach seven digits and be masked (inferred) |
| `PluginLog::write` | `lib/PluginLog.php:35-45` | — | already central (the mask at `:37`) | unchanged |
| `mlog`, `cb_log`, `log_msg_staff_jobs`, `waLog` | — | — | no | their sites are South Sudan only or unreachable (K-04 … K-08); `waLog`'s file is held by 5.18.104 |

### H.1.22 The helper: `PluginLog::tail()`

**Where.** `lib/PluginLog.php`, after `mask()` (`:48-55`) and before the docblock of `now()` at `:57`. That docblock line is the anchor; it occurs once (measured).

```php
    /** 5.18.105 (root docs/74 §H.1.22): a phone number as a log line may show it — '••••' and its last two digits; 'none' without a digit. */
    public static function tail(?string $number): string
    {
        $d = (string)preg_replace('/\D+/', '', (string)$number);
        return $d === '' ? 'none' : '••••' . substr($d, -2);
    }
```

**Why this form.**
- It is exactly `ChannelRegistry::mask`'s output (`lib/ChannelRegistry.php:403-408`).
- It is the form the Sales numbers admin, `tools/channels.php:88` and 5.18.104's report (`tools/sales_followup_report.php:706`) already show.

**Why PluginLog.**
- It has no dependencies, so every log site can load it.
- Its class doc (`:14-15`) already promises that a line never carries a phone number.
- The file already holds multibyte text (`…`, `:54`).

**Why not elsewhere.**
- Not `ChannelRegistry::mask`: it would load `ChannelRegistry.php` and `ChannelContext.php` into `webhook.php`.
- Not `InstallAuth::maskPhone`: it keeps three digits and lives in an authentication class.
- Never `lib/PhoneNumber.php`: 5.18.104's N03 holds it byte for byte (QF_HELD F and O).

**Who uses it.**
- Only LN-17, LN-18 and LN-19, each as `PluginLog::tail((string)$phone)`, because `webhook.php` is `strict_types`.
- Free text that may hold a number goes through the existing `PluginLog::mask()` (LN-03, LN-24).

**Holds.**
- No test holds `lib/PluginLog.php`.
- `tests/test_job_records_race.php:377-381`'s weakened copies anchor `:23`, `:32`, `:37` and `:41`, which `tail()` leaves alone.
- The file moves from the deploy's kept list (PREV_KEPT) to 5.18.105's delta. The tests spec's R105_HELD drops it.

**Test (section A).**
- `tail('+256700000731')` is `••••31`; `tail('0700 000 731')` is `••••31`.
- `''`, `null` and `'abc'` each give `none`; `'7'` gives `••••7`.
- Weakened copy: `tail()` returning its argument fails this test and LN-17's drive.

### H.1.23 The report's columns

**The fault** (`tools/sales_followup_report.php:707-709`, the BY NUMBER data row): `str_pad()` counts bytes.
- `'••••57'` is 6 characters but 14 bytes, so `str_pad($sfrLine, 7)` adds nothing.
- The `%4d` that follows adds no space once `conv` has four digits, so the two run together. This was seen in the live report on 9 Nov (root `docs/07`:16705-16708).
- Every row with a multibyte cell also drifts left of the header (`:696-697`) and of the ALL row (`:712`): `—` (1 character, 3 bytes) in the role, owner or line column, and NEVER_WROTE (`:57`; 43 characters, 45 bytes).

**The change, two hunks, each with its own marker:**
1. After `:663`:
   ```php
   $sfrPad = function (string $s, int $w): string { return $s . str_repeat(' ', max(1, $w - mb_strlen($s, 'UTF-8'))); };
   ```
2. `:707`:
   - **old:** `$sfrSay('  ' . str_pad($sfrN, 46) . str_pad($sfrRole, 9) . str_pad($sfrOwn, 13) . str_pad($sfrLine, 7)`
   - **new:** `$sfrSay('  ' . $sfrPad((string)$sfrN, 46) . $sfrPad($sfrRole, 9) . $sfrPad($sfrOwn, 13) . $sfrPad($sfrLine, 7)`
   - The file declares `strict_types` (`:2`), hence the cast.

**What stays:** `:708-709`, the header (`:696-697`), the ALL row (`:712`) and the by-owner row (`:745`), which are all ASCII.

**Why it is safe.**
- `max(1, …)` keeps one space before the next column, even when a cell fills its width. Only such a cell moves one character right; today no channel name comes near 46 characters.
- The report already needs mbstring. It calls `ContactOptOut::detect` on every stored message body (`:384`), and that calls `mb_strlen` (`lib/ContactOptOut.php:240`).
- The JSON `by_number` rows are unchanged: `'line'` keeps its value. Test K reads them at `tests/test_sales_followup_report.php:92`, `:110`, `:209` and `:232`.
- `:181`'s assertions read `tenant …` and `BY NUMBER (30 d)` only.
- The existing weakened copy at `:278` anchors `:745`.

**Readers.**
- The operator, through `scripts/sales-followup-report.sh`. That wrapper pins the release commit and `REPORT_SHA256` (`:39-41`, checked at `:83`), so it is re-rendered for 5.18.105 by the deploy derivation.
- 5.18.104 declares the file as new (QF_DECLARED F), so the r105 layer takes both hunks out before 5.18.104's N and M01 judge it.

**Test** (section F of `tests/test_log_mask.php`, in its own file `tests/log_mask/F_report.php`).
- The book: the fixture's own, built by `sfr_fixture()` (`tests/fixtures/sales_followup_report_book.php:87`), whose channels already carry synthetic business numbers (`:97-101`), plus rows of F's own added through the fixture's helpers (`sfr_conv`, `:37`): 1,000 sessions on one channel with a business number.
- Neither the fixture nor `tests/test_sales_followup_report.php` is edited. Sessions added to the shared book would change test K's exact counts (K01, `tests/test_sales_followup_report.php:98-112`; ALL's `conv` is 18 at `:108`).
- Counted in characters: the header's `conv` field ends at character *c*. Every BY NUMBER row's `conv` field ends at *c* and is preceded by a space. This includes the `••••NN` row, the `—` rows and the NEVER_WROTE row.
- Weakened copy: `:707` with `str_pad` put back fails on the `••••NN` row (run together at 1,000 and over), on the `—` rows and on NEVER_WROTE.

### H.1.24 The holds these lines move (re-points)

| Files changed | Held or judged by | Re-point |
|---|---|---|
| `cron_quote_wa.php`, `includes/post/post_kyc.php` | 5.18.104's N03 (QF_HELD F/P, `tests/quote_followup/declared.php:166-171`); catalogue_direct I and quote_from_ucrm I (`CD_I_KEPT`, `tests/catalogue_direct/I_boundary.php:50-54`; `QFU_I_KEPT`, `tests/quote_from_ucrm/I_boundary.php:72-76`, the two files at `:74`); **`test_quote_company` I4** (`:1855-1858`, `:1908-1912`) | `r105_less`: N's tree (`tests/quote_followup/N_boundary.php:20`), both I0s, and **I4's comparison for the files outside `QC_I4_102`** (G-2) |
| `cron_evo_sync.php` | N03 (QF_HELD V) | `r105_less` |
| `evo_webhook.php`, `lib/QuotationService.php`, `webhook.php` | N05's markers; `quote_agent_copy` J3's 12 hunks (`tests/quote_agent_copy/J.php:130`), which `webhook.php`'s hunks also fail; CD/QFU I (`webhook.php` in `CD_I_KEPT`, `QuotationService.php` in the runtime lists) | `r105_less` |
| the 14 files 5.18.104 neither declares nor holds: `cron/staff_ssp_report.php`, `lib/WaAutoReplyService.php`, `cron/bidal_summary.php`, `cron/cashbook_reconcile.php`, `cron/cash_carry_reminder.php`, `cron/wa_watchdog.php`, `cron_invoice_notify.php`, `cron_wa_sync.php`, `cron_overdue_email.php`, `cron_leads.php`, `cron_lead_alerts.php`, `includes/notify_actions.php`, `includes/post/post_cashbook.php`, `lib/PluginLog.php` | N01 (an undeclared change); TL14's control (`TL_tool.php:624`); the 5.18.103/102 boundaries' I1 | `r105_less`; TL14's main scan only (G-8) |
| `includes/post/post_cashbook.php` | **`tests/test_bank_boundaries.php` A and F** (`src102`, `:142`, `:218-240`, `:263`); **`tests/test_cashbook_physical_list.php` M** (`:1937-1948`, found here) | a new `tests/fixtures/r105_revert.php`, not the layer's per-file revert: `994d660`'s text recovered by removing LN-35's marker line and putting `:612`'s old line back in place of its new one, each found exactly once; otherwise null. It is generated from Git and checked there: `994d660`'s bytes come back exactly. Each caller applies it first: `r103_revert(r104_revert(r105_revert(…)))`, the pattern `tests/fixtures/r104_revert.php:4-11` documents. Being exact, it leaves every other edit in place wherever it sits. Its weakened copy: an unmarked edit at `:541` (`$crmRefundCats = …`, found once), 70 lines above LN-35's marker and outside every window (§H.1.0), still fails both tests |
| `tools/sales_followup_report.php` | 5.18.104's N05 and M01 (declared new, F) | `r105_less` |
| `manifest.json` | N06 and the eight version pins | the tests section |

## H.2 Known limits

### H.2.1 Lines only South Sudan runs, or that nothing runs on Uganda

These are K-01 and K-03 … K-12, and they are left. The reasons:
- 5.18.104 kept South Sudan as 5.18.103.
- These lines change nothing on the server 5.18.105 is for.
- K-01 would break `test_staff_jobs_south_sudan`'s byte comparison with 5.18.49.
- K-04 is held by 5.18.104.
- K-11 and K-12 are not run by the plugin, so an edit would prove nothing at runtime.

They stay in E2's allow-list, so any change to them is seen.

### H.2.2 Text that holds a number only if someone else puts one there (Q4)

- **Exception messages.** Some sit beside masked lines:
  - `cron/staff_ssp_report.php:125`;
  - `lib/WaAutoReplyService.php:1556` (uCRM's last error) and `:1580`;
  - `cron_quote_wa.php:143`, `:214`, `:231`, `:349`, `:361`, `:672`, `:800`;
  - `cron_overdue_email.php:442`, whose number itself LN-27 removes;
  - `includes/post/post_cashbook.php:616`;
  - `evo_webhook.php:292`, `:385`;
  - `cron_lead_alerts.php:130`, `:157`, `:185`;
  - `cron_leads.php:193`.

  `webhook.php` also has 34 `whLog` lines and 4 `error_log` lines that end with an exception's message.
- **Remote answers:** uCRM (K-14), URA (K-16), an invoice entity (K-17), SQL parameters (K-15).
- **Traces:** K-13.

No thrower on these paths is known to put a number in its message, but that is not proven. Each of these is masked only where its line is edited anyway: LN-03, LN-24.

### H.2.3 Names and free text that hold a number

A masked line that keeps a name (Q1) logs that name as it is, so if a customer's uCRM name or a KYC field holds a number, it is logged.
- The code itself puts a number in a name in two places, both masked:
  - the watchdog's `$who` (`cron/wa_watchdog.php:85`, LN-08);
  - an AI lead that has no name (`lib/AiLeadService.php:301`, LN-28 … LN-32).
- LN-34 keeps before and after for the names, `address_1` and `company_name` (Q5).

### H.2.4 `PluginLog::mask()`'s reach (LN-03, LN-24)

- It replaces runs of seven or more characters of digits, spaces, brackets and hyphens that start and end with a digit (`lib/PluginLog.php:52`).
- A number written with dots or slashes, or in words, passes.
- It also replaces dates and long references. That is acceptable in `evo_sync.log` and in an exception text.

### H.2.5 Stores that code reads back are not logs (out of scope, G-10)

E never scans the writers of these stores:
- `notification_audit_log`, the Message Log. `NotificationService::writeLog` (`lib/NotificationService.php:3089`) stores the recipient on every send. `getAuditLog($limit, $since, $phone)` (`:3132`) reads it back for the phone filter (`includes/api/api_notifications.php:234`).
- `quotes_log.json`. `cron_quote_wa.php:248` and `:369` append to it. `lib/QuoteTracker.php:714-716` reads it by `quote_ref`, and `tabs/sales/quote_logs.php:25-27` searches `customer_phone`.
- The KYC application's own audit record (`includes/post/post_kyc.php:244-252`), shown at `tabs/sales/applications.php:450-453`.
- `evo_sync_state.json`. `cron_evo_sync.php:214-216` keeps `phone` and `error` per chat as the sync's state.
- `data/dry_run_notification_log.json` (K-02), a capture mode, off by default.

### H.2.6 How the sweep looked, and what it may still miss

- A grep for log calls that name a phone variable on one line.
- A scan that joins multi-line statements. It found G-1's two lines and nothing else among log calls; its other hits were stores (§H.2.5) and collection records.
- A grep for names the code fills with a number.
- **Not covered:** a whole array passed to a log (K-17), and a value that reaches a log under a name that does not say phone, beyond the cases found in H.2.3.

### H.2.7 Lines already written stay

The masking binds from the moment 5.18.105 serves (Q6).

### H.2.8 A rollback brings all 37 statements back

`994d660`'s code writes the numbers again. LM-RB1 says so in capitals, with the count (Q8).

### H.2.9 E-mail addresses, names and amounts stay (Q1, Q7)

Examples of e-mail addresses: `webhook.php:314`; `cron_overdue_email.php:375`, `:387`, `:391`; `includes/api/api_lte.php:750`.

### H.2.10 Observed, not in scope

- `cron_wa_sync.php` polls `wa_feed_url`, which defaults to the South Sudan WASender feed (`:45-47`), unless `wa_sync_enabled` is false.
- If it is on and the feed returns rows, Uganda would take in that feed's messages.
- This is inferred from the defaults; the live value was not checked.

### H.2.11 The `071_dpo_payments.sql` checksum mismatch

It is untouched (root `docs/07`:16671-16674).

## H.3 The release and its tests

A bare `path:line` is under `dishnet-hybrid-sudan/` at `994d660` (5.18.104, live since the copy of 9 Nov 03:50:25 UTC). `scripts/…`, `wip/…` and `docs/NN` are the development branch. Nothing below has run. Every "proved" names a test still to be written, and each test has a weakened copy that must fail it.

**H.3.1 The commit**

- `release/5.18.105` is one commit on `994d660`. It is pushed only on the owner's word.
- It holds:
  - option B's masked lines (§H.1–§H.2): LN-01…LN-35, 35 edits covering 37 log statements in 19 files. Two of them are the lines the review found (gap 1, resolved here): `includes/post/post_kyc.php:269` (LN-34; the call is `:267-270`) and `includes/post/post_cashbook.php:612` (LN-35). With the helper and the report, that is 21 runtime files and about 40 hunks;
  - `PluginLog::tail()` in `lib/PluginLog.php`;
  - the report's column fix, `tools/sales_followup_report.php:707` and one closure after `:663`;
  - `manifest.json`, `SAFETY.md` and the tests.
- **No migration.**
- **Every hunk carries `5.18.105 (root docs/74 §H…)`.** The marker goes in the hunk's own added lines or in the 30 lines above it. That is `qf_n_hunks`' rule (`tests/quote_followup/declared.php:262-285`), applied with 5.18.105's marker.
- **No hunk carries `5.18.104 … root docs/73 §H` in its own added lines.** 5.18.104's N05 would read such a hunk as one of its own and revert it without a word.
- **One script makes the edits** (`wip/build-5.18.105/apply.py`). For each site it asserts:
  - the old text occurs exactly once;
  - after the edit, the new text occurs once and the old text not at all.

  On any miss it exits non-zero and writes nothing. Section D (below) checks the result again from Git.
- **`manifest.json:8`** changes from `"5.18.104"` to `"5.18.105"`. Nothing else in the file changes.
- **`SAFETY.md`** gets a new 5.18.105 section at the top of the VERSION LOG, above 5.18.104's at `:545`. 5.18.104's section is not edited. The new section gives the owner's rulings of 8 and 9 Nov (Why), the files (What), and four rules:
  1. On Uganda no log line carries a phone number. A new or changed log line names an id, a count or a code. A number goes through `PluginLog::tail()`, or is not logged.
  2. 5.18.104's rule 1, "a redeploy starts dark", governs a redeploy of 5.18.104 only. 5.18.105's deploy and rollback keep every ladder key as found and never run the switch tool.
  3. The 5.18.105 boundary is `tests/log_mask/declared.php`. Edit the list and the marker together.
  4. Names, e-mail addresses and amounts are not ruled on. The ruling covers numbers only.

**H.3.2 The 5.18.105 layer: one mechanism for every older boundary (gaps 2 and 8)**

5.18.104's boundaries judge the tree against `221c9dc`. To them a 5.18.105 hunk is an unmarked change, and a 5.18.105 file is undeclared. The fix is a two-level revert, the role `r104_revert` plays for `r103_revert`: 5.18.105 is taken out first, against `994d660`, and then each old test judges what is left, unchanged.

The new test-only file `tests/log_mask/declared.php` holds:
- `R105_BASE` (`994d660`, short and full);
- `R105_DECLARED`: every file 5.18.105 changes or adds, each with its kind and its reason;
- `R105_HELD`: the files 5.18.105 must leave byte for byte:
  - `tools/sales_followup.php`, `lib/SalesFollowupLadder.php` and `lib/SalesFollowupChecks.php`;
  - the report wrapper's five: `lib/PhoneNumber.php`, `lib/TenantProfile.php`, `lib/PluginConfig.php`, `lib/ConfigVault.php` and `lib/ContactOptOut.php`;
  - `cron/master.php`, `cron/event_processor.php`, `lib/NotificationService.php`, `lib/AlertService.php` and `workers/StarlinkMailWorker.php`;
  - `migrations/`.

  `lib/PluginLog.php` is not held: it gains `tail()`.
- `R105_MARK` = `#5\.18\.105[^\n]{0,40}root docs/74 §#u`.
- `r105_base()`: `994d660` from Git, by `git archive`. Its manifest must read 5.18.104. If either fails, the test fails; it never skips.
- `r105_hunks()` and `r105_revert_file()`: the same as `qf_n_hunks` and `qf_n_revert_file`, but against `994d660` and `R105_MARK`.
- `r105_revert_tree($tree, $base, $dest)`:
  - it removes 5.18.105's new files;
  - it puts each marked hunk back to `994d660`'s lines;
  - it puts the manifest's version back from `5.18.105` to `5.18.104`, only if that line occurs exactly once;
  - it returns the count of unmarked hunks it left.
- `r105_less($root, $tmp)`: the judged tree with 5.18.105 taken out.
  - It is cached per process. The cache key is the root's real path plus the sha256 of every `R105_DECLARED` file in it at build time.
  - The key is re-checked at every call, and the tree is rebuilt on any difference.
  - It is built only from the judged root, never from a tree that a control plants into. That is what keeps TL14's controls honest (gap 8).

One more test-only file, `tests/fixtures/r105_revert.php` (§H.1.24), serves the tests that hold `includes/post/post_cashbook.php` as text (the table below).
- `r105_revert($rel, $text)` gives back `994d660`'s text of that file. It removes LN-35's marker line, and puts `994d660`'s line back in place of LN-35's new line, each found exactly once; otherwise it returns null.
- Taking out one line would not give `994d660`'s text back: LN-35's hunk both adds its marker on the line above and replaces the logged line (§H.1.0, §H.1.19).
- It reverts by exact text, never by a marker's window, so an edit anywhere else in the file is left for the test to see.

The older boundaries then judge `994d660`'s tree exactly as they do today. 5.18.105's own hunks are judged once, by section D.

**The re-points.** Each is a marked 5.18.105 hunk, so the layer itself puts it back. Each anchor was measured to occur exactly once at `994d660`.

| Test | Anchor at `994d660` | Re-pointed to | What fails without it |
|---|---|---|---|
| quote_followup N | `N_boundary.php:20` `$T = $ctx->root;` | `r105_less($ctx->root, $ctx->tmp)`, plus an N00 control: the tree was built, its `evo_webhook.php` opt-out line reads `994d660`'s form, and `tests/test_log_mask.php` is absent | N01 (14 runtime files undeclared to 5.18.104); N03 (`cron_quote_wa.php`, `cron_evo_sync.php` and `includes/post/post_kyc.php` are in `QF_HELD`, `declared.php:165-201`); N05 (every 5.18.105 hunk unmarked); N06 (manifest) |
| wa_offers M01 | none | Nothing. It runs N as a subprocess on the same root (`M_boundary.php:28-38`), and N builds the layer itself. M04 (3 migrations) is unchanged. | — |
| quote_followup M01 | `M_logging.php:117` `qf_n_walk($ctx->root)` | the walk over `r105_less`; M01's rules are not relaxed | the kept names and `client #` labels in 5.18.105's lines of the declared `webhook.php`, `evo_webhook.php` and `lib/QuotationService.php` |
| quote_agent_copy J | `J.php:70` `$root = $ctx->root;` | `r105_less` | J1: `cron_quote_wa.php` is in `QF_HELD['P']` and is compared byte for byte. J3: exactly 12 hunks at `J.php:130`; every `webhook.php` and `QuotationService.php` hunk of 5.18.105 would add to that count. |
| catalogue_direct I0 | `I_boundary.php:63` `qf_n_revert_tree($c->root, $B221, $T)` | `qf_n_revert_tree(r105_less($c->root, …), $B221, $T)` | I1 (undeclared files); I2 (`CD_I_KEPT`, `:50-54`: `webhook.php`, `cron_quote_wa.php` and `post_kyc.php` byte for byte; the manifest); I3 |
| quote_from_ucrm I0 | `I_boundary.php:101`, the same text | the same | the same, against `f2055baf` |
| TL14 | `TL_tool.php:616` `$reads = sft_key_reads($ctx->tree(), $base);` and `:624` `sft_git_php($ctx->root, $base)` | both read `r105_less` | `:624`'s control: every runtime file 5.18.104 did not declare appears in Git's set. The plant controls at `:628-643` keep reading `$ctx->tree()`; the planted files are 5.18.104's and unchanged by 5.18.105. |
| test_quote_company I4 | `:1911` `$same = $RBASE !== null …` | for the `$DECLARED` files not in `QC_I4_102`, hash the file in `r105_less`'s tree, not in `$T`, against `$RBASE` (§B.1 G-2) | `:1855` holds `cron_quote_wa.php` and `includes/post/post_kyc.php` byte for byte as `ca05ada5` left them, on the plain judged tree (`:162-163`) (gap 2) |
| test_quote_company I5 | `:1988-1989` | `5.18.105` | the version half |
| test_bank_boundaries A | `:142` `function src102(` | `r103_revert(r104_revert(r105_revert(…)))` | `$UNTOUCHED` (`:218-222`) holds `includes/post/post_cashbook.php` byte for byte (gap 1) |
| test_cashbook_physical_list | `:1945`, the `$r103` closure, which reads every file but `navigation.php` raw | `post_cashbook.php` read through `r105_revert` | `:1938` holds `post_cashbook.php` byte-identical to `PL_BASE`. Found here; the review named only test_bank_boundaries. |

**What stays as it is:**
- N02, `test_bank_store.php:285-289`, test_quote_company I5's migration half (`:1976-1984`) and wa_offers M04: no migration is added.
- `tests/fixtures/r104_revert.php` and `r103_revert.php`: they touch `navigation.php`, `public.php`, `post_handlers.php` and `test_bank_store.php`, none of which 5.18.105 changes.
- N04 compares test_quote_company's I3 text with `221c9dc`. Through the layer it reads `994d660`'s text.
- TL13 (`TL_tool.php:498-517`) and the tool's `on chase`: they need no re-point while the two W2 strings stay (section C).
- VK1 (`VK_tool.php:55-80`): unchanged while 5.18.105 reads no new `$config` key (section D).
- quote_agent_copy H (`H.php:24-56`) compares PHP's error log on South Sudan and with P7 off. Its drive calls `createCrmQuote` and `whQuotationEmail`. 5.18.105 leaves `webhook.php:314` (an e-mail address, out of scope), and `whLog` writes to `data/webhook_log.json`, not the PHP log. Inferred: H holds.
- The weakened copies anchored in B files must still find their anchors exactly once. The suite's own copies prove it, because a missing anchor fails them. Read before the build:
  - `test_job_records_race.php:377-378`: two strings in `lib/PluginLog.php`. `tail()` is added beside them, never over them.
  - `test_pilot_safety.php:1565` (`cron/wa_watchdog.php`), `test_sales_numbers.php:1564` (`cron_leads.php`) and `test_notify_ladder_record.php:141-145` (`cron_overdue_email.php`).
  - the `evo_webhook.php` and `QuotationService.php` anchors mech105 lists.
- T1: 5.18.105 adds no test to the set the server runs, so `T1_SIGNATURE` (548) is carried. Inferred; both suite passes re-measure it.

**H.3.3 The version pins: 5.18.104 → 5.18.105**

Each is a marked hunk, so the layer puts it back:
- `tests/test_distributor_apply.php:130`;
- `tests/test_distributor_link_ucrm.php:146`;
- `tests/test_distributor_notify.php:218`;
- `tests/test_distributor_registry.php:153`;
- `tests/test_distributor_territory.php:124`;
- `tests/test_bank_boundaries.php:245-248`, and its weakened copy at `:546`;
- `tests/test_cashbook_physical_list.php:1934-1935`;
- `tests/test_quote_company.php:1988-1989`.

N06 and `declared.php:321` do not move: the layer hands them a manifest that reads 5.18.104.

**H.3.4 `tests/test_log_mask.php`, sections under `tests/log_mask/`**

Every person and number in these tests is fictitious. Numbers are of the `+256700000xyz` kind, in four forms: E.164, digits only, national (`0700000xyz`) and spaced. Staff are named by role.

**A. Each masked site, driven through its real path.**
- The sink is captured: the file, the job's stdout, or the PHP log through `-d error_log=FILE`.
- Each test asserts:
  1. the line exists, found by its fixed words (the positive control);
  2. no form of the number appears, and neither do its last 9 digits;
  3. with the line's own time prefix removed, nothing matches `(?<![\d.])\d{9,}(?!\d)`. The decimal guard is there because `cron_leads.php` prints `{$hoursIdle}` as a raw float.
- Weakened copy: `994d660`'s line put back by its anchor must fail assertion 2.
- Harnesses that exist:
  - F11 (`tests/quote_followup/F_stops.php:257-300`, the served `evo_webhook.php` and a fake Evolution);
  - `test_quote_cron_log` (for `cron_quote_wa.php`);
  - NotifyHarness, SjSandbox, `test_notify_ladder_record`, `test_sales_numbers` and `test_pilot_safety`.
- Harnesses to add, both new files under `tests/log_mask/` (§B.2 item 5):
  - a fake Evolution serving `chat/findChats` and `chat/findMessages`, for `cron_evo_sync.php`;
  - a fake WhatsML feed, for `cron_wa_sync.php`.

  The shared `tests/fixtures/fake_evo_server.php` (5.18.104's, declared changed at `tests/quote_followup/declared.php:111`) is left alone.
- The special set-ups:
  - `QuotationService::sendWA` with a `NotificationService` whose `sendVia` throws;
  - `WaAutoReplyService::createCrmJobForBidal` with a recording fake CRM;
  - `cron/staff_ssp_report.php` on a book where SSP is bookable. Its control is a UGX,USD book, on which the job returns at `:8-10`.
- `PluginLog::tail()`: its output is `••••` plus two digits, or `none`, for every form and for an empty input.
- 35 drive tests, one per LN (LN-24's covers its three statements), in 21 harness groups, each with its weakened copy (§B.1 G-6).

**B. South Sudan.** 5.18.105 gates nothing by tenant, so the same lines are masked on South Sudan. D7 proves that log text is all that moves. One runtime differential remains: `cron/staff_ssp_report.php`, which runs on South Sudan, is covered by no 5.18.104 differential, and has its log line changed by 5.18.105. Every output of that job but its log line (the sends and the rows) must equal `994d660`'s.

**C. The chase's precondition.** `lib/SalesFollowupChecks.php:68-72` refuses `on chase` with `w2_missing` unless `evo_webhook.php` holds two strings: `WaSeenMarks::note($pdo, $config, $dataDir, 'in', $phone,` (`994d660:300`) and `$convId === null && $text === ''` (`:298`). Section C asserts:
- each string occurs exactly once in 5.18.105's `evo_webhook.php`;
- `SalesFollowupChecks.php` is `994d660`'s byte for byte;
- `on chase` through the tool on the judged tree reaches `W2: installed`.

Weakened copy: `$phone` renamed on line 300 must be caught. The opt-out edit is `:381-382` only, 81 lines below.

**D. The boundary against `994d660`, from Git.**
- D1: exactly the files in `R105_DECLARED` change or are added, and none is removed.
- D2: `migrations/` is `994d660`'s byte for byte, with 091 last.
- D3: every file in `R105_HELD` is byte for byte `994d660`'s.
- D4: every hunk carries `R105_MARK`. No hunk carries 5.18.104's marker in its own added lines. Taken out, the tree is `994d660`'s file for file.
- D5: in `manifest.json` only the version line changes.
- D6: `php -l` passes, and `QFU_I_RULES` (PHP 7.4) holds on every added code line. TL14's key scan over 5.18.105's hunks finds no new `$config` key.
- D7: a hunk in a runtime file touches only:
  - log statements (E's reader) and comments;
  - three declared exceptions: `PluginLog::tail()`, the report's column closure, and the report's line 707;
  - four declared lines, each by its exact text (its indentation aside), each once among the release's hunks. None is a log statement to E's reader, so without them D7 fails on the release's own hunks:
    - `if (!class_exists('PluginLog')) require_once __DIR__ . '/PluginLog.php';` in `lib/QuotationService.php` (LN-03, §H.1.3);
    - `if (!class_exists('PluginLog')) require_once __DIR__ . '/lib/PluginLog.php';` in `cron_evo_sync.php`, inside `evoLog` (LN-24, §H.1.12);
    - `require_once __DIR__ . '/lib/PluginLog.php';` in `webhook.php`, after `:129` (§H.1.11);
    - `$line = '[' . date('Y-m-d H:i:s') . '] ' . PluginLog::mask($msg) . PHP_EOL;` in `cron_evo_sync.php`, inside `evoLog` (LN-24): the sink's own text line, an assignment, not a call to a logger.

**E. The logging rule, scoped to changed lines (gap 3).** E has its own reader, modelled on `qm_log_statements` (`M_logging.php:61-95`), with statements joined across lines. `M_logging.php` is not edited.
- The loggers it recognises are §H.1.0 E2's: `error_log`, `whLog`, `qwa_log`, `ilog`, `olog`, `evoLog`, `wsLog`, `waLog`, `mlog`, `rclog`, `_cclog`, `cb_log`, `clog_*`, `log_msg_*`, `log_ja`, `pusherLog`, `logActivity` and `PluginLog::write`.
- `echo` and `print` count only in the CLI jobs (`cron/`, `cron_*.php`, `workers/`). The operator tools are the operator's terminal, not a log (K-18), so their `echo` and `printf` lines are not read.
- `QM_LOGGERS`' `printf`, `fwrite`, `master_log` and `sf_out` (`M_logging.php:28`) are not on the list. Outside the tools, none of them names a phone-carrying expression at `994d660` (grep).

- **E1, 5.18.105's own added lines only.** No log statement names a phone-carrying expression, except as the whole argument of `PluginLog::tail()` or `PluginLog::mask()` (a `(string)` cast allowed, as LN-17…LN-19 write it). Phone-carrying means any of:
  - a variable in `$phone`, `$to`, `$msisdn`, `$number`, `$digits`, `$recipient`, `$remoteJid` or `$jid`;
  - a variable whose name contains the word phone, msisdn, jid, digits or recipient (`$custPhone`, `$refundPhone`, `$acctPhone`, `$techPhone`);
  - an array element read whose key contains one of those words: `$leader['phone']`, `$x['customer_phone']`, `$row['remoteJid']`, `$inbound['phone']`. A literal key written into an array (`'phone' => …`, `'tech_phone' => …`) is not a read; its value is judged like any other expression. That is §H.1.0 E's rule. LN-17's and LN-18's added `'phone' => PluginLog::tail((string)$phone)` therefore pass, and K-01's `'tech_phone' => $techPhone` is still found, by `$techPhone`;
  - an expression that falls back to the number, file by file:
    - `cron/wa_watchdog.php`'s `$who` (`:85`);
    - `cron_lead_alerts.php`'s `$leadName` (`:105`);
    - `cron_leads.php`'s `['customer_name']` (`:258`, `:270`). `lib/AiLeadService.php:301` sets it to the phone when no name was captured.

  Names, ids, `client #` labels and amounts are not flagged: the ruling covers numbers, and §H.1–§H.2 keep names at several sites. 5.18.104's wider M01 rule stays where it was written, on 5.18.104's own lines.

  Controls:
  - a planted `error_log('x ' . $phone)` in a 5.18.105 hunk is found;
  - `PluginLog::tail($phone)` passes;
  - a planted `'phone' => PluginLog::tail((string)$phone)` passes;
  - a planted `$c['phone']` is found.

- **E2, the rest of the plugin, as a ratchet.** Every log statement in runtime PHP (outside `tests/`, `docs/` and Domain B), as E's reader finds it, that names a phone-carrying expression must be on E2's list. The list is keyed by file and by the statement's words, never by line number: 5.18.105 inserts lines above unchanged ones (the `webhook.php` loader after `:129`, every hunk's marker), so a line number taken at `994d660` is wrong in the judged tree. Each entry carries one reason:
  - **(a)** a sink that masks centrally (LN-24): `cron_evo_sync.php`'s three `evoLog` call sites (`Syncing `, `messages imported`, `ERROR for `);
  - **(b)** a known limit, South-Sudan-only or unreachable on Uganda (§H.1.20):
    - `webhook.php`'s job notification, `notification sent to {$techName}`, with its `'tech_phone'` field (K-01);
    - `lib/FiberInstallService.php`'s `Delivery note skipped` (K-03);
    - `wa_webhook.php`'s three `waLog` calls (K-04);
    - `cron_maintenance.php`'s three `mlog` calls (K-05, K-06);
    - `cron/cashbook_summary.php`'s `cb_log` (K-07);
    - `cron/staff_jobs_summary.php`'s `log_msg_staff_jobs` (K-08);
    - `cron/job_assignment_notify.php`'s four `log_ja` calls (K-09);
    - `cron_lte_usage.php`'s `clog_lte_usage` (K-10);
    - `dishnet_wa_pusher.php`'s four `pusherLog` calls (K-11);
    - `workers/lte/`'s two `echo` lines (K-12);
  - **(c)** not a phone number, and unchanged by 5.18.105 (§H.1.0 E2):
    - `includes/api/api_lte.php`'s `Daily summary email sent`: `$recipients` holds e-mail addresses, on LTE;
    - `lib/KycService.php`'s `deferred to cron_quote_wa with PDF`: `$number` is a quotation's number.

  The dry-run capture (K-02) is not on the list. It is written with `file_put_contents` (`lib/NotificationService.php:207`), not through a log function, so E's reader never sees it; §H.2.5 records it among the stores.

  E1's exception holds in E2 too: a phone variable that is the whole argument of `PluginLog::tail()` or
  `PluginLog::mask()`, a `(string)` cast allowed, is not a hit (§H.1.0 E2).

  An unlisted statement fails E2. A listed statement no longer found also fails, so no entry goes stale.

  Two stores are not logs, and E never scans them (gap 10). Both are business records read back by code, and both are out of scope:
  - `NotificationService::writeLog` writes `notification_audit_log.phone`. `getAuditLog`'s phone filter reads it back (`lib/NotificationService.php:3089-3132`).
  - `cron_quote_wa.php:248` and `:369` append to `quotes_log.json`. `lib/QuoteTracker.php:714-748` and `tabs/sales/quote_logs.php:27` read it back.

  E's logger list leaves out `writeLog` and `appendWithId`, and E2's header says why.

- **E3, the runtime half.** It is A's assertion 3, on every drive.

**F. The report's column fix** (§H.1.23). F is its own file, `tests/log_mask/F_report.php`, a section of
`tests/test_log_mask.php`; neither `tests/fixtures/sales_followup_report_book.php` nor
`tests/test_sales_followup_report.php` is edited.
- Its book is `sfr_fixture()`'s (`tests/fixtures/sales_followup_report_book.php:87`), whose channels already carry
  synthetic business numbers (`:97-101`), plus F's own rows added through the fixture's helper `sfr_conv` (`:37`):
  1,000 sessions on one channel, in F's own copy of the book only.
- Sessions added to the shared book would change test K's exact counts (K01,
  `tests/test_sales_followup_report.php:98-112`; ALL's `conv` is 18 at `:108`), which is why F builds its own.

F asserts, counting characters with `mb_strlen`:
- every BY NUMBER row's `conv` field ends at the end of the header's `conv` column, with a space before it;
- the JSON `by_number` rows are unchanged. `test_sales_followup_report.php` K reads them at `:92`, `:110`, `:209` and `:232`.

Weakened copy: `str_pad` back at `:707` must fail on the `••••NN`, `—` and NEVER_WROTE rows.

**Weakened copies.** Each is caught by the section named:
- every masked line put back (A);
- `tail()` and `mask()` each stubbed to return their input (A, E3);
- a W2 string altered (C);
- an unmarked 5.18.105 hunk (D4);
- a hunk that wears 5.18.104's marker (D4);
- a planted migration (D2);
- `tools/sales_followup.php` touched (D3);
- a non-log code change inside a masked hunk (D7);
- a new `$config` read (D6);
- a new `$phone` log line, once inside a 5.18.105 hunk (E1) and once outside one (E2);
- a stale E2 entry (E2);
- `str_pad` restored (F).

Through the layer:
- an unmarked edit to `cron_quote_wa.php` fails test_quote_company I4, quote_followup N03 and CD I2;
- an unmarked edit to `post_cashbook.php` fails test_bank_boundaries A and test_cashbook_physical_list.

Where each planted edit stands:
- The layer counts a hunk as marked when a marker stands in its own added lines or in the 30 lines above it, or when it only closes a block. That is `qf_n_hunks`' rule (`tests/quote_followup/declared.php:277-281`), which `r105_hunks` copies. An unmarked edit inside a 5.18.105 marker's window would be put back by `r105_less` without a word, and the weakened copy would pass.
- So every copy that plants an unmarked edit (D4's, and the `cron_quote_wa.php` copy above) names its line, at least 31 lines from every 5.18.105 marker in its file, and is not a hunk that only closes a block.
- Before it is judged, the copy asserts from the planted file that its hunk lies outside every marker's window, and fails as not planted otherwise.
- `r105_revert` puts back LN-35's two lines by exact text, with no window, so the `post_cashbook.php` copy stays in place wherever it stands. Its line is named all the same, at least 31 lines from LN-35's marker.

The control on the controls: N with `r105_less` replaced by `$ctx->root` must fail on 5.18.105's hunks. That shows the layer is what makes N pass, and that N still sees what it should.

## H.4 `scripts/deploy-5.18.105.sh`

**H.4.1 How it is made**

- `wip/derive-5.18.105/derive105.py` derives it from `scripts/deploy-5.18.104.sh` by asserted edits. It has parts A, B, F→LK, R, V, O, P, L (new), H, C and Z, and a copy of `d104lib`'s `Doc`.
- For every edit it asserts the anchor, its count, the number of replacements and the result. Then it runs `must`/`mustnot` over the whole file. Any failure exits non-zero and writes nothing.
- It takes `PIN_FULL` as a parameter. It asserts:
  - the pin's parent is `994d660628cc5b1b42bee648b44b58e389f44bf2`;
  - the pin's manifest says `"version": "5.18.105"`.
- Every pin, list, count and sha256 comes from Git, `994d660` → pin.
- The result and both wrappers must pass `bash -n`. A `keep_check` with four weakened copies must refuse each one for its own reason:
  - the clear call re-inserted;
  - LK-CA1 removed;
  - QF-RB1 called again in V1;
  - `quote_followup_state`'s "never fell" count taken over every row, its `list_last:%` filter removed.
- It is run twice; the two results must be byte for byte the same. If the suite forces a new commit, it is run again with that commit.

**H.4.2 Identity and lists**

| Item (`deploy-5.18.104.sh`) | 5.18.105 |
|---|---|
| `EXPECTED_PLUGIN_COMMIT`/`_FULL`, `EXPECTED_VERSION` (`:667-669`) | the pin, short and full; `5.18.105` |
| `BASELINE_COMMIT`/`_FULL`/`_VERSION` (`:670-672`) | `994d660` / `994d660628cc…44bf2` / `5.18.104`. Its comment: live since the copy of 9 Nov 03:50:25 UTC, steps on from 04:26:06 UTC. BR1 then compares every file `994d660` tracks; A0 requires the pin to be cut on it; `do_rollback` checks it out. |
| `RELEASE_BRANCH`, `OUT`, R5's range, CIH's range (`:673-687`) | `release/5.18.105`; `/root/dnb-5.18.105`; "through 5.18.104"; "5.18.97 through 5.18.105" |
| `PREV_STATE`, `PREV2`–`PREV7` (`:1395-1410`); A14's loop (`:11483`) and its messages | `PREV_STATE` moves to `/root/dnb-5.18.104/state-5.18.104.env` by itself, and holds production's `COPY_AT`. `PREV2`=5.18.103 … `PREV7`=5.18.98, plus a new `PREV8`=5.18.97, so that 5.18.97's `STRAY_Q_FAILED` record is not dropped. The loop and the messages gain `PREV8`. |
| `PREV_KEPT_SHA256S` (158 files, `:764-925`) | Every file 5.18.105 must not touch, at `994d660`'s sha, asserted identical at the pin: (5.18.104's kept ∪ its 260 pinned) − 5.18.105's delta. This brings under sha the ladder trio, `cron/master.php`, `cron/event_processor.php`, `lib/NotificationService.php`, 089–091 and `lib/QuoteDailyList.php`, which today are only in `PIN_SHA256S`. Four of 5.18.104's kept files leave this list, because 5.18.105 changes them: `cron_quote_wa.php`, `lib/PluginLog.php`, `includes/post/post_kyc.php` and `includes/post/post_cashbook.php` (measured against both lists). |
| `PREV_CHANGED_SHA256S` (45, `:929-977`) | Exactly 5.18.105's changed files, at `994d660`'s sha (about 38, inferred). Q-G1 reads them as live 5.18.104; RB reads them back after a rollback. |
| `PIN_SHA256S` (260, `:980-1243`), `EXPECTED_ADDED`/`EXPECTED_CHANGED` (`:1294-1295`) | 5.18.105's delta at the pin. The added files are tests only (`tests/test_log_mask.php`, `tests/log_mask/…` with both fakes, `tests/fixtures/r105_revert.php`). |
| `MASTER_PIN_SHA256`, `MASTER_BASE_SHA256` (`:1358-1359`) | Both `b59e7ce56ec7…8e06`, `994d660`'s master. A15's and RB's words become "5.18.104's master.php: 5.18.92's lock, ruling R1's four jobs last". Left as it is, A15 would be NO-GO over live 5.18.104. |
| `UG_SIGNATURE` (`:1253-1260`) | `994d660`'s value, which is 5.18.104's `UG_PIN_SIGNATURE` string (`ai.media=pending/0`). `UG_PIN_SIGNATURE`, `SS_SIGNATURE` and `EP_PIN_LIST` stay the same; only their comments are reworded. 5.18.105 holds `cron/event_processor.php` and `NotificationService.php`, so nothing needs re-measuring. |
| `COPY_MARK` (`:1448`) | `evo_webhook.php`, a file 5.18.105 changes. `stamp_copy` touches only the delta, so `COPY_AT`, R19, V4 and A14 read the right moment. |
| The report wrapper `scripts/sales-followup-report.sh` | Re-rendered, because the column fix is in scope: `RELEASE_COMMIT`/`_FULL`, `RELEASE_BRANCH` and `REPORT_SHA256` (`:39-41`). `QSE_SHA256` and the five installed sums (`:42-49`) stay the same, and are asserted equal at `994d660` and at the pin. While those five are held, the wrapper works on both releases. |

**H.4.3 The ladder rule (LK)**

- **The keys.** The rule covers every key of `SalesFollowupLadder::allKeys()` and of `settingKeys()`, read through the pin's `lib/SalesFollowupLadder.php`. That file is in `PREV_KEPT` at `994d660`'s sha, so the pin's key set and the live one are the same.
  - **Only an `allKeys()` key can refuse** (gap 11). A `settingKeys()` key held in a `config.json` is a note. Settings are compared like the other keys after the copy, because no run writes them.
- **The four places**, all read-only:
  - `<data>/kyc_config.json` (copy code `k`);
  - the store row: `kyc_config`, id 0, in `<data>/plugin.sqlite3`, opened `SQLITE_OPEN_READONLY` (`s`);
  - `<plugin>/data/config.json` (`p`);
  - `<data>/config.json` (`d`).

  `<data>` is `ucrm.json`'s `pluginDataDir`, else the sibling `.dishnet-hybrid-sudan-data`.
- **The reading.** A new `QF_PHP` mode, `lk ROOT LIVEROOT PDD KEYFILE`, prints:
  - `keys=`, `settings=`, `file=`, `store=`, `held=` (`allKeys()` only), `held_settings=`, `differ=` (keys where file and store disagree), `on=`, `skipped=` and `ledger=`. Step names are derived from `rowKeys()`, as the tool's `show` derives them.
  - then `map=<key|copy|hex,…>`. `hex` is the first 16 hex digits of HMAC-SHA256 over the JSON value, under `LK_KEY`. Absent keys are left out.

  The log gets the line up to ` map=`. The map goes only to the state file and the 0600 sheet. No value, date, staff id or number is ever logged.
- **The key, kept with the run** (gap 5).
  - `LK_KEY` is 24 random bytes. It is kept in `LK_KEY_FILE="$OUT/lk-key-$EXPECTED_VERSION"`, mode 0600, beside the state file and outside its glob. That is the `QL_KEY_FILE` precedent (`:10806`); `MAP_SALT` (`:10805`) is renewed every run and cannot serve.
  - The deploy run makes the key if it is absent. `--after-only` and `--rollback` read it back. `--check` uses a key of its own and writes nothing.
  - The state file keeps `LK_MAP`, `LK_ON`, `LK_SKIP` and `LK_AT`: hashes and step names, never a value. The key is never printed, and never goes into the state file or the sheet.
- **LK-G1** (stage A, and `--check`):
  - **NO-GO** if any place cannot be read.
  - **NO-GO** if `held≥1`, with 5.18.104's remedy text: remove the key there by hand, never with this script. The reason: the tool's `off` would read back `FAIL key_outside_overrides`, and the operator must always be able to turn a step off.
  - **Otherwise it is never NO-GO for a key that is on.** A note gives the steps on, the steps skipped, the ledger, the count per place, and every key where file and store disagree.
  - **A NOTE IN CAPITALS** when `offers_live_enabled` is set: step 12 is already on under 5.18.104, against the owner's ruling of 9 Nov. This deploy is the remedy, and it changes the key neither way.
  - `quote_chase_enabled` set is a plain note. The owner's later ruling of 9 Nov: the chase does not wait.
- **LK-B1** runs after `DEPLOY` is typed and before the copy, inside `qf_keep_and_baseline` (H.4.4).
  - It takes the same reading and requires it equal to LK-G1's, key by key and place by place.
  - Otherwise it is **NO-GO**: "a ladder key changed during this run — the switch tool was run meanwhile; nothing was copied". It names the keys.
- **LK-CA1** (stage R). The reading on the live places must equal LK-B1's. A key changed, added or removed is a **FAIL**. The log names the key and the place; the sheet holds both hashes.
- **LK-CA2.** It compares with the baseline taken at the copy's start:
  - **Row counts never fell (FAIL):** `quote_tracker`, `followups` of kind `quote`, `wa_auto_sends`, `quote_list_sends`, `wa_seen_marks`, `wa_media`, `wa_voice_calls`, `wa_voice_acks`, `wa_offers`, `wa_offer_recipients`, `wa_offer_tests`, `wa_offer_events`, P7's `quote_agent_makers` and `quote_agent_copies`, and `quote_followup_state` counted only over rows `NOT LIKE 'list_last:%'` (gap 4, §B.1 G-4).
  - **`quote_followup_state`'s whole count** before and after is a note. The daily list's sweep deletes `list_last:*` rows inside its own transaction (`lib/QuoteDailyList.php:542`), so once step 4 is on, a whole-table count across the sweep would read FAIL with nothing wrong.
    - Measured: a grep of runtime code at `994d660` finds only one other DELETE on these tables, `tools/wa_prune_history.php:92` on `wa_media`. That is a tool run by hand that nothing schedules.
  - **Each `_schema_checks` row unchanged** (FAIL).
  - **`master_schedule.json`**: an entry of the four jobs that vanished is a FAIL. Their `last_run` after the copy is a note.
  - **Claims after the copy** are counted, a note: the live steps are expected to claim.
- **LK-ON** (the deploy run). Each step that is on is judged by the installed tool's `--check` on a fresh copy. That is compared with the same `--check` through the pin's tool on stage A's copy. The tool is `994d660`'s bytes in both. PASS before and FAIL after is a **FAIL**; everything else is a note.
- **LK-AO** (`--after-only`). It reads with the kept key and compares with `LK_MAP`, as a note naming every key changed since the deploy. The operator may legitimately turn on `offers-live` by then. Without the key file the note says "cannot be compared", never "same". QF-ON judges each step that is on.
- **V3g.** The excuse branch, `:12434-12449` inside the `else` at `:12433`, is taken out. The equality check at `:12432` and the unreadable case at `:12451` stay. Bash refuses an empty `else`, so one line takes the branch's place: a `bad` naming the changed keys through `ai_changed` (names only, `deploy-5.18.104.sh:1752-1757`). Any change of an `ai_`, `evo_`, `openai_` or `claude_` key is a **FAIL**.
- **Never written.** Nothing in the deploy, its `--after-only` or its rollback runs `tools/sales_followup.php on|off|skip|set|erase`, or `scripts/sales-followup.sh`. The copy never touches the data: `scripts/deploy-hybrid.sh:25-27` says so, and `:119-120` excludes `./data` from the tar.

**H.4.4 The checks that assumed every switch off, made live-aware**

- **QF-G4** (`:6936-6953`) is removed; LK-G1 replaces it.
- **`qf_clear_and_baseline`** (`:6966-6999`, called at `:12140`) becomes `qf_keep_and_baseline`. It runs no tool. It does three things:
  - LK-B1;
  - then the 6 s pause (`busy_timeout` plus 1 s);
  - then the QF, VN, OF and LK-CA2 baselines, always.
- **`--clear-switches`** (`:1504`, `:1518`, `:10687`) is refused at parse, before anything runs: "5.18.105 never clears a ladder key; turn a step off yourself with `scripts/sales-followup.sh off <step>`, its own command".
- **QF-G1** (`:6819-6828`, and every `QF_G1` branch) accepts one form only: `mig=111 sc=111 tables=111 kind=1 control=1`, read as "5.18.104's tables as it left them" (`QF_G1=live`).
  - 088 last is **NO-GO**: "5.18.104 is not live — deploy 5.18.104 first".
  - A part-applied book is **NO-GO**.
  - Every `first` branch is deleted. Every `redeploy` branch becomes the unconditional path, reworded.
- **QF-G2, VN-G7 and OF-G2** keep only the "none pending" form.
- **QF-G3**'s counts are re-read from the pin and asserted equal to `994d660`'s.
- **QF-CA1** keeps its reading, reworded: "089–091 still applied as 5.18.104 left them".
- **QF-CA2's dark-build judgement** goes into LK-CA2.
- **QF-CA3** keeps its text half. In its `cron_status` half, each of the four jobs must read `OFF (switch)` exactly when its flag key is unset on the copy. The flags are read as text from master's own `'flag'` fields.
- **QF-CA5**: the installed tool's `show` on a fresh copy must equal, line for line, stage A's `show` through the pin's tool on QF-G1's copy: every `<label>: on|off|skipped` line and the ledger line. The South Sudan half is kept.
- **QF-CA4, QF-CA6 and QF-CA8** are kept. CA6 forces its keys in memory. CA8 is re-measured on a copy whose ledger is on (inferred to hold).
- **VN-G1** reads LK-G1.
- **VN-G4** compares the pin's whole `fromMe` branch with `994d660`'s, byte for byte, with W1's count checked on both.
- **VN-CA1** must equal the same probe through the pin's code on stage A's copy. That is one new reading in stage A.
- **VN-CA2**'s `u0` must equal `ai_media_enabled` on the copy.
- **VN-CA4**: its keys half is LK-CA1; its rows and late counts become a note.
- **OF-G1**'s words: "the offers as 5.18.104 left them".
- **OF-CA1** must equal stage A.
- **OF-CA3**: the `first` branch is deleted. "No table fell" stays a FAIL. Late claims, echoes and tests become a note.
- **AC-CA1**: `on()` must equal `quote_agent_cc_enabled` on the copy. The control and the South Sudan halves are kept.
- **AC-CA2** compares with BASELINE, now `994d660`.
- **R7** keeps only "same": 091 last, before and after.
- **The on-chase precondition** stays byte for byte. LM-G2 and LM-CA1 hold its two strings, and `PREV_KEPT` holds `lib/SalesFollowupChecks.php`.

**H.4.5 The masked lines (LM)**

The line list comes from the build, never from a fixed four (gap 6). `derive105` computes 5.18.105's hunks in runtime files from Git (`994d660` → pin, `diff -U0`) and pins them as `LM_HUNKS`.
- Each entry is a file and one LN (§H.1.0 LM-G1): the line count and sha256 of the LN's old text and of its new text, each taken as one block, the lines joined by newlines, without the LN's marker. Markers are never counted. So the script carries no source text.
- A block is found in a file as a run of that many consecutive lines whose joined text has that sha256.

The checks count blocks, never single lines, because a single added line need not be unique:
- LN-03's loader line, `if (!class_exists('PluginLog')) require_once __DIR__ . '/PluginLog.php';`, already stands at `lib/QuotationService.php:851`, at the indentation it takes at `:1585`.
- Counted line by line, it occurs twice at the pin, so LM-G1 would refuse its own pin. After a rollback it is still present, so LM-RB1 would read it as not absent.
- A marker line may also repeat within one file, which is why markers are left out of the blocks.

When `derive105` pins the list, it asserts every count below on Git's two trees, so a block that is not unique stops the derivation.

- **LM-G1** (a refusal in stage A and in `--check`). In the pin's files from Git, each LN's new block occurs exactly once and its old block nowhere. The control is the same count on `994d660`: each old block once, each new block nowhere. An entry that only inserts lines (the `webhook.php` loader, `tail()`, the report's closure) has no old block; only its new block is counted.
- **LM-G2** (a refusal). The pin's `evo_webhook.php` holds each W2 string once. The control is a copy with one string cut, which must read 0.
- **LM-CA1.** LM-G1 on the installed files.
- **LM-CA2.** It counts and never prints a line.
  - In the PHP log, old-form opt-out lines (`opt-out recorded for ` followed by a run of digits) stamped 60 s or more after `COPY_AT` must be 0, else a **FAIL**.
  - Old-form lines stamped earlier are a note: a request that was in flight.
  - An unreadable PHP log reads NOT ESTABLISHED, a note. That is what 5.18.104's deploy found on production.
  - In the other sinks 5.18.105's lines write (`data/webhook_log.json`, `quote_wa_log.json`, `invoice_notify_cron.log`, `evo_sync.log`, `plugin.log`, `activity_log.json`, `overdue_cron_status.json`): records stamped 60 s or more after the copy that hold a run of 9 or more digits are counted per sink, as a note. Other lines in those sinks may carry long values that are not numbers of people. The suite and the rehearsal are the strict checks.
  - `overdue_cron_status.json` is one record, rewritten on every flush. It is judged only when its `started_at` is 60 s or more after the copy, and then only its `output` field. Its `started_at` and `updated_at` are 10-digit epochs (`cron_overdue_email.php:75`, `:95`, `:102`), so the whole file would count at least 1 on every run of that job.
- **LM-ON** (`--after-only`). LM-CA1, and LM-CA2's counts since the copy. The old opt-out form late is still a FAIL.
- **LM-RB1** (the rollback). See H.5.

**H.4.6 Migrations, and the 071 note**

- A0: the pin adds none (`[ "$N_MIG" = "0" ]`). `MIG104_*` (`:729-736`) remain, describing what the live book must already carry.
- QF-G1 accepts only 089–091 applied. QF-CA1 reads them still applied, each `_schema_checks` row at its count. R7 reads the ledger the same.
- QF-G2's trial prints `071_dpo_payments.sql:checksum_mismatch`, as 5.18.104's run did. It is a note and is not judged. It is the book's history: 071 was edited on 13 Sep (`df5a100`, `cd65458`) after the server had applied it (root `docs/07`, the 5.18.104 entry). It is left for a later release.

**H.4.7 What the operator reads**

The header, the options, the stages and the summary (partH and partC) say:
- 5.18.104 must be live. The ladder is kept exactly as found. The script never runs the switch tool's `on`, `off`, `skip`, `set` or `erase`.
- Step 12 may follow once 5.18.105 serves and its `--after-only` has passed.
- The rollback keeps every step running, and the masked lines then write numbers again.
- A14 needs the quarantine's `--check` after any copy or rollback later than 04:39:36 UTC on 9 Nov.
- Do not run `scripts/sales-followup.sh` while the deploy, its `--after-only` or its rollback runs: LK-B1 and LK-CA1 would read the change.
- The deploy window: outside 14:55–15:10 UTC and outside the morning list's send (inferred).
- The deploy and its rollback are never in one copyable block (root `docs/44` §16.9).

**partZ** asserts:
- no tool verb outside printed text;
- no `qf_rb1`, `qf_clear_and_baseline`, `qf_byhand` or `CLEAR_SWITCHES` left;
- no `QF_G1" = "first"` and no `REDEPLOY of` left;
- every new code (LK-\*, LM-\*, RB105) is reported in its stage;
- every retired code is silent.

`scripts/sales-followup.sh:19-22` stays as it is. Its sentence describes `deploy-5.18.104.sh`'s rollback and is still true.

## H.5 The rollback

**H.5.1 What it does**

- 5.18.105's `--rollback`, and V1's automatic rollback, go to `BASELINE_COMMIT=994d660` through `do_rollback` (`deploy-5.18.104.sh:12109`):
  - `git checkout 994d660`;
  - `scripts/deploy-hybrid.sh`;
  - `stamp_copy` on `COPY_MARK=evo_webhook.php`;
  - `RB_COPY_AT` written to the state file.
- No switch needs to move. `994d660` is the code the ladder was built on. Its tool and its two ladder classes are the installed ones in both releases, so every key keeps its meaning. The copy leaves `./data` alone, so the keys, the store row, the 089–091 tables and the P7 collections stay as they are.

**H.5.2 What it never runs**

- QF-RB1 and `scripts/sales-followup.sh off all --rollback`, or any other tool verb, at either call site: `--rollback` (`:12178`) and V1 (`:12209`).
- `qf_byhand`'s SQL block (`:7152-7175`), which closes quote follow-ups, pauses offers and removes the ladder's keys. `PUTBACK_SHOW` stays: `994d660`'s code put-back only, in its own block.
- QF-RB3 as written (`:7214-7233`). It requires master without the four jobs, no ladder key, and 0 open quote follow-ups. All three are the opposite under `994d660`.
- VN-RB1 to VN-RB4, OF-RB1, AC-RB1 and `ac_rb_pre`.
- VN-RB6's instruction to rotate the OpenAI key: the voice steps keep running.
- OF-RB2 and AC-RB2 are reworded: "they continue under 994d660 as their switches say".

**H.5.3 Before `ROLLBACK` is typed (`rb_pre105`)**

- LK-RB0: the keyed reading, with the kept key. Without the key file it uses a key of its own run.
- QC-RB2's count, as now.
- **The step-12 notice.** If `offers_live_enabled` is set, it says in capitals:
  - `994d660` writes numbers again in the lines 5.18.105 masked, the opt-out line among them (`evo_webhook.php:381`), while offers run. That goes against the ruling that step 12 waits for 5.18.105.
  - It names the operator's own alternative, `bash scripts/sales-followup.sh off offers-live`, alone on its line. The script never runs it, and never prints it in one block with this command.
- A chase that is on is a plain note: its STOPs write the number again, as they do under 5.18.104 today, which the owner accepted on 9 Nov.
- **Recommended default: the rollback is never blocked by the ladder.** It is an emergency act, and V1's case, a redirecting site, is worse than any log line. Refusing while step 12 is on unless a flag is given is the owner's choice (open). V1's rollback always proceeds.

**H.5.4 After the put-back (RB105, read-only)**

- **The files.**
  - Every file 5.18.105 changed is `994d660`'s, by Git's sha and by `PREV_CHANGED_SHA256S`.
  - `PREV_KEPT` is unchanged.
  - master equals `b59e7ce5…`, with `quote_chase`, `quote_daily_list`, `ai_media` and `offers_send` registered last.
  - RB's event processor reads `UG_SIGNATURE` and `SS_SIGNATURE`, `994d660`'s.
- **The ladder.**
  - LK-RB1: every key in every place must equal LK-RB0 (FAIL otherwise).
  - The installed tool's `show` must equal LK-RB0's `show`.
  - LK-CA2's "never fell" is judged against LK-RB0's row counts.
  - The open quote follow-ups and the running offers are counted, a note: they are `994d660`'s own and carry on.
- **The old lines back (LM-RB1).** It reads the installed text: each hunk's removed block of `LM_HUNKS` present once and its added block absent, counted as blocks (H.4.5). LN-03's loader line alone is still at `lib/QuotationService.php:851` after the rollback, as at `994d660`; its block is not. The count of lines that now write a number again is printed, in capitals.
- **The rest.**
  - R7: the ledger is the same, 091 last.
  - V3g, with no excuse branch.
  - The files 5.18.105 adds (tests only) are left on disk, and no runtime file of `994d660` names one. That is QC-RB1 re-pointed; its control on the pin is that `tests/test_log_mask.php` names `tests/log_mask/`.
  - QC-RB2 is counted again.
  - The carried RB checks, as now: the sign-off, the owner rule, ECO Bank, the documents and the quarantine.
- **The command.** The rollback command is printed once, after the verdict, alone.

**H.5.5 After it**

- A14 of any later deploy needs the quarantine's `--check` begun after `RB_COPY_AT`.
- A later deploy of 5.18.105 goes with `--redeploy`:
  - BR1 counts the files 5.18.105 left on disk;
  - QF-G1 reads `live`;
  - the ladder rule holds as on the first deploy.

## H.6 The rehearsal

**H.6.1 Guards**

`scripts/harness/deploy-5.18.105/rehearse.sh` refuses to start:
- on a server path (`/data/ucrm`, `/opt/dishnet`, `docker.sock`);
- unless `deploy-5.18.105.sh` is pinned short and full and pins `T1_SIGNATURE`;
- unless the script holds the `lk` mode's line, and no invocation of a tool verb outside printed text. This is a static grep; its control is a planted copy, which must be refused.
- unless `deploy-5.18.104.sh` is present and pinned to `994d660`;
- unless both wrappers are equal to the committed ones;
- unless its helpers are beside it:
  - `rh105q.py`, an independent read-only reader of the ladder in the four places, the table counts and the log forms;
  - `rh105-ref.php`.

**H.6.2 The cut, and the state it leaves (measured from the code)**

- Python cuts `scripts/harness/deploy-5.18.104/rehearse.sh` and asserts:
  - the anchor `echo; echo "== 104-10. what the rehearsal left behind, and what it never reached =="` occurs once, at line 4378, after line 900;
  - the last section heading before it is 104-7c's (`:4161`);
  - the last non-empty line is exactly `fi   # LIVE104` (`:4376`), and it occurs once. 5.18.104's own "last check" assertion is rewritten to this.
  - the last `check` line inside the block is 7c16's, and it occurs once.
- One edit: `H104_DIR="$(cd "$(dirname "$0")" && pwd)"` (`:112`) becomes `H104_DIR="$(cd "$(dirname "$0")/../deploy-5.18.104" && pwd)"`, so that `H104_ROOT` (`:113`) and the helpers' check (`:127`) still find 5.18.104's directory. Like the cut's other edits, it asserts:
  - the anchor occurs exactly once in the cut, at line 112;
  - it is replaced exactly once;
  - the result holds the new line exactly once and the old line nowhere.

  Otherwise it exits non-zero and writes nothing.
- Each required definition starts exactly one line: `H103_HARNESS=`, `run104()`, `run_line104()`, `fresh104()`, `installed104()`, `left104()`, `nogo104()`, `checkgo104()`, `weak104()`, `live104()`, `sf104()`, `tool104()`, `clk_set104()`, `clk_to104()`, `dsnap104()`, `drest104()`, `kv104()`, `q104()`, `aft104()`, `onall104()`, `expect104()`, `master104()`, `idle104()`, `rbx104()`, `REL104=`, `OUT104=`/`STATE104=`, `R104=`, `H104=`.
- After sourcing:
  - `declare -F` for each function;
  - every variable is set;
  - `LIVE104=yes` is required, else it refuses: 5.18.105's states need the live cases;
  - `FAIL_PRE105=$FAILN`.
- **The state at the cut.** 104-7c ends with `rbx104 "7c15"` (`:3659-3669`), a rollback followed by the quarantine's `--check`. 7c16 then disarms every trigger. What is left:
  - `221c9dc` (5.18.103) serving, every file 5.18.104 changes back at 5.18.103's bytes;
  - 5.18.104's added files on disk;
  - no `allKeys()` key set;
  - 089–091 in the ledger;
  - the quarantine passed;
  - the privacy line unset;
  - the clock wherever 104-7 left it. It only moves forward.

  The record's 104-10i confirms the same end state.
- A development runner may skip exactly the sourcing block and restore `devsnap105`. Its gap from 5.18.104's trial 3 is closed there: it copies the committed script and both wrappers into the restored clone.

**H.6.3 105-pre: a live 5.18.104, as production has it**

- **pre-a.** The cut's state, read by `rh105q.py`.
- **105-1a**, placed here because 5.18.103 serves: `--check` of 5.18.105 must be **NO-GO** at BR1 and Q-G1, with "deploy 5.18.104 first". Its weakened copy, with BASELINE left at `221c9dc`, reads GO and is caught.
- **pre-b.** 5.18.104 deployed again through the real `deploy-5.18.104.sh`: `run104 --answer DEPLOY --redeploy`, the runbook's form for a book 5.18.104 has run on. It must read:
  - QF-G1 redeploy, QF-G4 none set, PASSED;
  - then `aft104` with the copy half an hour back, every pair off.
- **pre-c.** The quarantine's `--check` passes. `$RECS/dnb-5.18.104` is linked to `$OUT104`, so that 5.18.105's `PREV_STATE` and A14 read its `COPY_AT`.
- **pre-d. The ladder walk.**
  - Each step goes through `sf104` (the clone's `scripts/sales-followup.sh`, running the installed tool as the book's owner) under `rh104-clock`. Each is followed by `aft104` with its `expect104` pairs.
  - The steps follow 104-5's order and conditions (`:1363-1460`):
    1. the report wrapper, then `on report --ran <its Kampala date>`, then `--check report` PASS;
    2. `on ack`, then the clock moved 25 h on;
    3. `on tracking`, once the ledger's L-ON has passed;
    4. the clock 73 h on, then `on list --accept-not-yet "<reason>"`;
    5. `on voice-measure`.
  - `dsnap105 L1`: steps 1–5 on. That is production from 10 Nov until the chase.
- **pre-e. The chase test.**
  1. `set persona-confirmed <the clock's Kampala day>`.
  2. `--tty yes on chase`, with tracking on for 72 h or more.
  3. A quotation seeded through the release's own writers after tracking came on, as `seed104` does in 104-pre. The clock moves to its day-1 chase.
  4. One `master104` cycle sends that chase through the stand-in Evolution, claimed in the ledger.
  5. A STOP from the fixture's number through the real webhook under 5.18.104 writes one old-form opt-out line.
  - `dsnap105 L2`: steps 1–6 on, the most likely state when 5.18.105 is deployed.
- **L3**, for the deploy rule only: L2 plus `on agent-cc` (step 1b, optional), then `skip voice-dry-run --reason "…"`, then `on offers-test` after its checks pass.
  - The skip records steps 7–9 skipped by its cascade (`sf_skip_voice-dry-run`, `sf_skip_voice-staff`, `sf_skip_voice-reply`).
  - Without it the tool refuses `on offers-test` with `order:voice-dry-run`: every lower step must be on, recorded skipped or optional (`lib/SalesFollowupLadder.php:369-376`), and steps 7–9 are none of these (`:82-96`).
  - 5.18.104's rehearsal took the same order: the skip at `scripts/harness/deploy-5.18.104/rehearse.sh:1687`, `on offers-test` at `:1700`.
  - Inferred to be reachable from what L2 already passes; the first trial measures it.
- **L4**: L3 plus `on offers-live`, the ruling broken before 5.18.105.
- **Development shortcut.** `drest104 s2` (steps 1–5, `:1456`) and `drest104 mainend` (steps 1–6, the chase answered, `:1673`) replace pre-d and pre-e, with the clock moved past their last stamp. The record run does the walk.
- RH-NOTE: production's 5.18.104 was a first deploy, the rehearsal's a redeploy.

**H.6.4 5.18.105's own sections**

- **105-0. The script read against Git:**
  - the pins and the delta computed from Git;
  - no migration;
  - `COPY_MARK` is in the delta;
  - `PREV_KEPT` and `PREV_CHANGED` match Git, with the four files that moved from kept to changed;
  - `LM_HUNKS` equal Git's diff;
  - the header's commands, each alone;
  - the retired codes silent;
  - the report wrapper pinned to the pin, with `REPORT_SHA256` from Git.
- **105-c.** `--check` must be GO over L2 and over L1.
  - LK-G1 names exactly the steps `rh105q.py` finds on.
  - LM-G1 and LM-G2 read ok.
  - Nothing changes: the keys byte-identical by `rh105q.py`, the book's digest the same, no backup, no state file, no key file, the vault as it was.
- **105-1. Each NO-GO, its weakened copy caught:**
  - an `allKeys()` key held by `<data>/config.json` (LK-G1);
  - a settings key held there gives a note, not a NO-GO. That is gap 11's control: a copy that counts settings as held refuses here and is caught.
  - a part-applied book (QF-G1);
  - a key changed through `sf104` while the `DEPLOY` question waits: one setting, and one `on agent-cc`. LK-B1 must refuse, nothing copied.
  - `--clear-switches`, refused at parse.
- **105-4. The deploy over L2**, `DEPLOY` typed. It must read PASSED, and:
  - LK-CA1 ok, and `rh105q.py` finds the four places' ladder subsets byte-identical before and after;
  - `wason104` = 0: no tool line reading `was on:`;
  - the 089–091 and P7 counts never fell, by the rehearsal's own count;
  - one `master104` cycle after the copy dispatches each job exactly as its flag reads;
  - LK-ON;
  - LM-CA1, and LM-CA2's counts;
  - the rollback printed once, after the verdict, alone.
  - Gap 4's case (§B.1 G-4), inside this deploy: a daily-list actor (104-7's `actor104`, a copy with an injected
    pause) holds its sweep before the DELETE and is released after the copy's start, before stage R reads. LK-CA2
    must still read PASS, `quote_followup_state`'s whole count lower in a note; the weakened copy, its
    `list_last:%` filter removed, FAILs and is caught.
  - The same deploy over L4, after a rollback, for LK-G1's capital note. It changes nothing.
- **105-5. After the deploy.**
  - `--after-only` must read PASSED. LK-AO, read with the kept key, names no key. LM-ON passes. QF-ON judges steps 1–6.
  - Then, through the installed tool: `skip voice-dry-run --reason "…"` (steps 7–9 recorded skipped, without which `on offers-test` is refused, as in L3); then the owner's order, `on offers-test`, its check, then `on offers-live`.
  - `--after-only` again: LK-AO names exactly the keys those commands wrote, as `rh105q.py` finds them changed: the three `sf_skip_*` keys of steps 7–9 and the offers keys. Gap 5's control: the same run with the key file removed says "cannot be compared".
  - Then the drives through the installed 5.18.105, listed in H.6.6.
- **105-6. The rollback** with steps 1–6, 11 and 12 on and steps 7–9 recorded skipped (105-5), `ROLLBACK` typed.
  - It must read PASSED, and no tool runs: `wason104` = 0, and `rh105q.py`'s counts of closed follow-ups, paused offers and settled recipients are unchanged.
  - `994d660` is back by sha.
  - LK-RB1 holds, and the `show` reads the same.
  - The step-12 notice is printed in capitals, with the `off` command alone and never run.
  - LM-RB1's count is printed in capitals. Its control: a STOP after the rollback writes the old form again.
  - V3g passes with no excuse branch.
  - Then the quarantine's `--check`.
- **105-6v. V1's automatic rollback.** A 5.18.105 deploy with a redirect planted, as in 104-6v. Its rollback must keep every key and run no `off`.
- **105-7. The weakened copies, and the redeploy.**
  - 7a, `--check` copies over the live 5.18.104, each refused by the check it weakens:
    - `MASTER_BASE` left at `5400be20…` → A15 NO-GO;
    - `UG_SIGNATURE` left as it was → A6 NO-GO;
    - VN-G4's "less W1" kept → NO-GO.
  - 7b, deploy-run copies, each followed by the rollback and `drest105` where a copy destroyed keys:
    - the clear re-inserted together with LK-CA1 removed: the script reads PASS while `rh105q.py` finds keys moved, so it is caught;
    - the clear alone → LK-CA1 FAILs;
    - QF-CA5's "13 off" kept → FAIL;
    - QF-RB1 called again in V1 → keys gone, caught by `rh105q.py`;
    - the key not kept (`MAP_SALT` per run) → LK-AO cannot name the changed key, caught.
  - 7c: `--redeploy` of 5.18.105 over the live ladder. BR1 counts 5.18.105's files on disk, and the run must read PASSED.
  - 7d: LM-CA1 made to read the old form, in an `--after-only` → FAIL.
- **105-10. The sweeps.**
  - 104-10's sweeps were cut with the anchor. 105-10 runs them over 104's and 105's records alike: Evolution sees only the cases' own sends; uCRM gets GETs only; no token is left; nothing is left in `/tmp`; the deploy's own terminals, logs, sheets and state files carry no seeded name or number.
  - The key file is mode 0600, and its value is searched for in every log, sheet and state file: none.

**H.6.5 The plugin's logs after 5.18.105's copy (gap 7)**

The scans select records by their own timestamp, at or after the moment of 5.18.105's first copy, never by byte offset. A byte offset does not work for these sinks:
- `whLog`, `qwa_log` and `logActivity` put the newest record first and rewrite the whole file (`webhook.php:148`, `:156`; `cron_quote_wa.php:924`; `public.php:733`, written through `saveDataFile`'s rename at `:726`);
- `evoLog` rewrites its file to the last 400 lines once it passes 500 (`cron_evo_sync.php:267`).

The bytes after a size recorded at the copy are therefore older records, or nothing. The stamps:
- `received_at` in `webhook_log.json`; `time` in `quote_wa_log.json` and `activity_log.json`;
- the line's own `[…]` stamp in `evo_sync.log`, `invoice_notify_cron.log`, `data/plugin.log` and PHP's log;
- for `overdue_cron_status.json`, one record rewritten on every flush, its `started_at`. Only its `output` field is judged: `started_at` and `updated_at` are 10-digit epochs (`cron_overdue_email.php:75`, `:95`, `:102`);
- output captured whole after the copy, such as a master cycle's stdout (`logs/master-<label>.out`), is judged whole. The lines with no stamp of their own, such as the watchdog's echo, are read there.

Each stamp is compared with the copy's moment on the clock that wrote it (in the rehearsal, its own clock), in the zone it was written in.

- **N105, numbers.** Every fixture number in its four forms and its last 9 digits, plus the no-9+-digit-run rule on each record's judged text: a line without its stamp; a webhook record's `message` and `data`; a `quote_wa_log.json` record's `msg`; an `activity_log.json` record's `title` and `detail`; `overdue_cron_status.json`'s `output` without its `[H:i:s]` prefixes. It covers every record of every plugin log and sink stamped at or after the copy, **with no exclusion**.
  - In those records, every form that 5.18.105 masks must read 0.
  - Each masked form the rehearsal drives must be present: the positive controls.
- **NM105, names.** It scans the same records, minus the records of the lines where 5.18.105 keeps a name by the ruling. Each such line is excluded by its fixed words, and the exclusion is counted. Where the rehearsal drives that line, the count is a positive control.
- **The logs 5.18.104's runs wrote earlier in the chain.** They still hold 5o11's old-form opt-out line, and pre-e's. The `OPTOUT104` exclusion (`rehearse.sh:2307`) stays for the records stamped before the copy only. 5o13 passes unchanged inside the chain, because it runs on `994d660`.

**H.6.6 Which masked lines the rehearsal drives (gap 6)**

**Driven by the rehearsal** in 105-5, through the installed 5.18.105: LN-01…LN-04, as §H.1.0 settles.
- LN-01, `evo_webhook.php:381`: a STOP through the live cases' real webhook. The masked form must appear exactly once, and no fixture number.
- LN-02, `cron/staff_ssp_report.php:123`: the job run once through master, on a book where SSP is bookable (inferred feasible).
- LN-03, `lib/QuotationService.php:1585`: a quotation WhatsApp whose send throws (inferred feasible; the first trial settles the stand-in that throws).
- LN-04, `lib/WaAutoReplyService.php:1576`: through a WASender stand-in (inferred: one is needed, §H.1.4).

**Proven by the suite only:** every other LN, LN-05…LN-35, including:
- `webhook.php:952`, `:1102`, `:1152`, `:1706`, `:3092`, `:3345`, `:3388` and `:3701`;
- `cron_quote_wa.php:212`, `:229`, `:347`, `:433` and `:670`;
- `cron/wa_watchdog.php:94`, and `cron_lead_alerts.php:128`, `:155` and `:183`;
- `cron_invoice_notify.php:175`/`:356`;
- `cron_evo_sync.php`, `cron_wa_sync.php`, `cron_overdue_email.php`, `cron_leads.php`, `cron/bidal_summary.php`, `cron/cashbook_reconcile.php` and `cron/cash_carry_reminder.php`;
- `includes/notify_actions.php:23`, `includes/post/post_kyc.php:269` and `includes/post/post_cashbook.php:612`.

A rehearsal drive the first trial finds infeasible moves to this list, and docs/74 says so.

**H.6.7 Time**

- **Measured.**
  - 5.18.103's chain to its anchor: 4 h 05 m.
  - 5.18.104's own sections from a snapshot: 2 h 14 m to 2 h 23 m, about 98 runs of its script.
  - 5.18.104's record: 6 h 14 m 23 s (root `docs/07`).
- **Estimated (inferred).**
  - The chain to the cut: about 6 h. `devsnap105`, running since 05:16:25 UTC on 9 Nov, will measure it.
  - 105-pre: 45–75 min. The walk moves the clock; it does not wait for it.
  - 5.18.105's sections: 2–3 h, about 40–50 script runs, a dozen of them deploys or rollbacks.
  - A development trial from `devsnap105`: 3–4 h. Expect 3 to 5 trials.
  - The record run, the whole chain unrestored: 8.5–10 h.
  - The re-rendered report wrapper is first exercised by the 104 chain in the record run. Whether any 104 check reads the BY NUMBER columns is not measured; inferred: none does, since they read the headline's counts.

## H.7 The plan

The plan is sized for option B (gap 6). A step waits only for what it reads.

| # | Step | Work | Machine | Waits for |
|---|---|---|---|---|
| 0a | `devsnap105`: 5.18.104's rehearsal cut before 104-10, saved | — | about 6 h, **running since 05:16:25 UTC, 9 Nov**; ends about 11:30 UTC (inferred) | nothing of 5.18.105 |
| 0b | The quarantine's `--check` after 5.18.104's copy | **done**: 04:39:36 UTC, PASSED, 9 ok / 0 failed / 2 notes (`docs/07`, `3f6b94c`) (gap 9) | — | A new one is needed only after a later copy or rollback (`RB_COPY_AT`) |
| 1 | Owner decisions (below) | 0.5 h | — | — |
| 2 | docs/74 completed | 3–4 h | — | 1 |
| 3 | The build: about 40 hunks in 21 files by the asserted apply script, `PluginLog::tail()`, the report fix, `SAFETY.md`, the manifest | 5–7 h | — | 1 |
| 3t | Tests: the layer and its 11 re-points, 5–7 h; section A's 35 drives with weakened copies, 15–21 h (the 14–19 h estimated for 32, scaled); two fakes, 2–3 h; B–F, 5–7 h; E2's inventory, 2–3 h | 29–41 h | — | 3 |
| 4 | Review and fixes | 6–8 h | — | 3t |
| 5 | The suite twice on the pin, one pass after the other, nothing beside them | — | each about 5 h 10 m–5 h 35 m (5.18.104's: 4 h 54 m, 5 h 10 m, plus an estimated 15–25 min of drives and layer builds), so 11–12 h; a forced re-cut adds a pass | 4. Never across 21:00–24:00 UTC, `test_cashbook_physical_list`'s window. |
| 6 | `derive105.py` (A, B, F→LK, R, V, O, P, L, H, C, Z) and its review | 14–18 h + 4–6 h | seconds | the file list. It reads Git only, so it is written during 5 and re-run if the pin moves. |
| 7 | The rehearsal: writing; development trials from `devsnap105`; one record run | 10–14 h | 3–5 × 3–4 h, then 8.5–10 h | 0a, 6 |
| 8 | docs/07 entry, runbook, docs/74 status; the commands each alone, the rollback in its own block | 3–4 h | — | 7 |

- **Totals: about 75–103 h of work, and 30–42 h of runs.** The runs mostly overlap the writing: the suite runs during the derivation, the trials during the rehearsal's fixes. Elapsed: about 8–11 working days. **5.18.105 is ready about 18–21 Nov** (inferred, counting from 10 Nov).
- **What that means for the ladder.**
  - Steps 3–5 come on 10 Nov. Step 6 (`chase`) comes on about 13 Nov, after tracking's 72 h, without waiting (the owner's ruling, 9 Nov). Its STOPs write the number until 5.18.105 serves.
  - Step 12 (`offers-live`) waits for 5.18.105's deploy and its `--after-only`.
  - The rehearsal therefore takes steps 1–6 on (L2) as the state it deploys over.
  - Steps 7–9 and 11 may come on meanwhile, as the runbook allows. L3 rehearses step 11 on with steps 7–9 recorded skipped: `on offers-test` is refused unless every lower step is on, skipped or optional (`lib/SalesFollowupLadder.php:369-376`). Steps 7–9 on are not rehearsed: 5.18.104's rehearsal skipped them for want of an OpenAI key (`scripts/harness/deploy-5.18.104/rehearse.sh:1687`), and 5.18.105's adds none (inferred).
- **The only faster path** is the split the masking part names: the four lines and the daily ones first, the rest of B later. It costs a second layer, a second derivation and a second rehearsal, about 2–3 days more in total, and is not recommended.
- **Owner decisions** (each has a default):
  - the rollback while step 12 is on: never blocked, with a notice (the default), or refused without a flag;
  - names, ids and amounts stay in masked lines (the default, since the ruling is numbers only). If they go too, E's list and §H.1's edits widen;
  - `post_kyc.php:269` and `post_cashbook.php:612` join B (the default, under ruling B);
  - South Sudan's copies of the same lines are masked too (the default: log text only, D7).

## S. Status, 14 Nov 2026 — built and re-cut; the suite and the rehearsal still to finish; NOT deployed

This section records what was built. The design above stands as written on 9 Nov and is not rewritten. Where this
section and §H disagree, this section records what was built. §S.3 and §S.4 are completed when the suite's two passes
and the rehearsal's record run end.

### S.1 The release

- **Built:** `release/5.18.105` = **`7835906`**, one commit on 5.18.104 (`994d660`): 89 files, 43 added, 46 changed;
  4,175 insertions, 76 deletions. No migration and no setting. **Not pushed:** the branch is local, and is pushed only
  on the owner's word. The development branch keeps it as a patch (`wip/release-5.18.105.patch`, byte for byte
  `git diff 994d660..7835906`), so a container restart cannot lose it.
- **Re-cut on 14 Nov.** The first cut, `f764699` (88 files; 4,131 insertions, 71 deletions), failed two checks of its
  own suite in pass A3 (§S.3): a boundary §H.1.24 missed (§S.2 item 6) and a control of 5.18.104's that the date had
  broken (§S.2 item 7). Both are fixed in four files, tests and `SAFETY.md` only, and the release is cut again as one
  commit on `994d660`, as §H.4.1 requires of the pin. Every runtime file is `f764699`'s, byte for byte. `f764699` is
  kept under `refs/keep/5.18.105-cut1`, and the fix as its own commit under `refs/keep/5.18.105-fix-d623fc1`.
- **How it was built:** in three stages, each built, then reviewed by someone who had not built it, then fixed and
  rechecked: C1 the code, C2 the tests, C3 the re-pointed boundaries. Five working commits, kept locally under
  `refs/keep/wip/5.18.105` (`add6118`), were squashed into the release; the release's tree is `add6118`'s.
- **Outside the tests it changes 23 files:** the 19 log files of §H.1 (35 edits covering 37 log statements,
  LN-01…LN-35); `lib/PluginLog.php` (`tail()`); `tools/sales_followup_report.php` (the BY NUMBER table pads by
  characters, not bytes); `SAFETY.md`; and `manifest.json` (the version line).
- **The tests are 66 files** (43 added, 23 changed). New: `tests/test_log_mask.php` with `tests/log_mask/`
  (sections A–F: each of LN-01…LN-35 driven through its real path in 21 groups; 73 weakened copies, each caught) and
  `tests/fixtures/r105_revert.php`, the layer through which every older boundary still judges `994d660`'s tree. The
  rest are re-points and version pins.
- **Nothing is switched on or off.** No message, send, screen or stored record changes. The deploy and the rollback
  leave every sales follow-up switch as they find it (§H.4, the LK checks).

### S.2 Where the build differs from this design

Each was found by a stage, by a review, or by a test's own drive, and decided by the coordinator; none changes what the
owner ruled.

1. **LN-35 is reached only by a hand-made request.** §H.1.19 says "Uganda: yes", when an accountant records a refund.
   The line is in the handler for the posted field `action=cashbook_add_entry`
   (`includes/post/post_cashbook.php:382`). The panel's cashbook form posts `cb_action=add_entry`, which another block
   handles (`:717`); the retailer app sends `cashbook_add_entry` to the API (`includes/api/api_cashbook.php:387`); and no
   form or script in the repository posts the field this handler reads. So no screen reaches LN-35's line. It is masked
   all the same, and its drive (G21) posts the form by hand.
2. **QN8b, and one more reading of the layer's window.** §H.3.4 asks every weakened copy that plants an edit 5.18.105
   did not make to stand at least 31 lines from every 5.18.105 marker in its file. QN8 was moved to
   `test_quote_company`'s I3 to meet that. QN8b puts 5.18.104's own QN8 back beside it (`CD_I_KEPT` edited), nine lines
   above the I0 re-point's marker, so that N04's `CD_I_KEPT` half is proved to bite again. For that one copy
   `r105_planted_outside()` counts only a marker inside the planted hunk or above it, which is the layer's own rule: a
   marker below a hunk cannot mark it. `SAFETY.md`'s list of these copies does not name QN8b; the test's own comment
   does (`tests/quote_followup/mutants_N.php`).
3. **Older faults the drives met — recorded, not changed.** Each reads the same on `994d660`'s own files, measured in
   the drive's harness. 5.18.105 changes none of them; each is the owner's to decide, as a later release.
   - **Renewal reminders are never sent.** `renewal_remind_log` is not in `lib/SqliteStore.php`'s `$FLAT_TABLES`, so its
     stamp is never read back and every run is a first-run dry run (G09).
   - **The queued quotation PDFs are dropped.** `quote_wa_state` is not in that list either: `pdf_pending` is never
     found, and is saved back empty (G10).
   - **The `cashbook_add_entry` handler answers 500** after it has done its work: `TransactionIntegrityGuard` is not
     loaded on its path (G21). Item 1 applies: no screen posts to it.
   - **`cron/cashbook_reconcile.php` stops before its send on a book fresh from the migrations** (G06): it needs three
     tables the store makes on first use and a column the cashbook adds when it is first opened. A live book has them.
   - **`cron_wa_sync.php` run alone stops at every row:** it uses `WaInbound` before the file that loads it (G16).
     Under `cron/master.php` an earlier job of the same process has loaded it.
4. **The rehearsal (§H.6), found by its reviews before any run, and one by its second trial.**
   - **105-1a'.** §H.6.3 says the weakened copy, "with BASELINE left at `221c9dc`, reads GO and is caught". Such a
     copy never reaches the live-commit clause: the script's own guard (the pin's parent must be the baseline — "is
     not cut on") stops it first, and no copy can read GO while 5.18.103 serves. The copy now built weakens the clause
     itself, and must be seen to go on to the next clause, which refuses on the installed manifest (5.18.103, not
     5.18.104).
   - **105-1d.** The part-applied book is 091's first table gone **and** 091's `_schema_checks` row gone. QF-G1 reads
     that row, never the table.
   - **pre-d1.** A report that reached its end prints "READ-ONLY — nothing was changed" twice, the report's own last
     line and the wrapper's. Each is required once.
   - **LN-02.** The scratch book is made SSP-bookable in `kyc_config.json`, the file the release merges last.
   - **105-6b.** The tool's `show` is compared by its step lines and the ledger's line, as LK-RB1 compares it. The
     wrapper's own lines carry the time, the installed commit and its log's name.
   - **pre-a0, new.** The cut's book carries four offer claims that 5.18.104's rehearsal planted. They are removed
     before anything else is read (§S.4, T2-b). §H.6.3 begins at pre-a.
5. **QF-G1's refusal for a book that ends at 088.** §H.4.4 gives its words as "5.18.104 is not live — deploy 5.18.104
   first". By the time QF-G1 reads, the record, the manifest and every file are already known to be 5.18.104's (step 0,
   BR1), and `deploy-5.18.104.sh` run on such a server skips its deploy. What such a book lacks is 5.18.104's first
   boot, whose runner applies 089–091. The refusal now says so: open the plugin in uCRM once, then run again.
6. **A boundary §H.1.24 missed: `tests/test_quote_summary.php` M** (found by the suite, pass A3, 14 Nov). It holds
   `webhook.php` byte for byte against `f2055baf`, 5.18.101's release, once 5.18.104's two P7 hunks are taken out by
   their markers. §H.1.24's `webhook.php` row names N05, J3 and CD/QFU I, not this check. So the build left it
   reading the raw file, and 5.18.105's nine `webhook.php` hunks (the `PluginLog` loader and LN-16 … LN-23) made it
   fail: 57 passed, 1 failed. Its one-character control still passed, proving nothing. It now reads `webhook.php`
   through `r105_file()`, as the other boundaries read their files (§H.3.2). A new control asserts that the layered
   file holds no `PluginLog` line and that the raw file, read without the layer, fails. The one-character control is
   planted in the raw file, asserted outside every 5.18.105 marker's window (`r105_planted_outside()`), and read
   through `r105_revert_file()`, which must leave it. `tests/fixtures/r105_revert.php` declares the file. On the
   fixed tree: 59 passed, 0 failed. Three weakened copies of the test each fail it: the raw file read again, the plant
   moved inside LN-20's hunk, and the layer replaced by `994d660`'s whole file. A read of every test against the 23
   files 5.18.105 changes outside the tests (14 Nov, read-only) found no other hold left unpointed.
7. **5.18.104's TL40 control broke with the date** (`tests/sales_followup_tool/TL_tool.php`; pass A3, 396 passed, 1
   failed). TL40 builds its control book with every step switched on ten days before `time()`, and a fixture chase
   fixed on 2 Nov at 10:00 Kampala. From 12 Nov 07:00 UTC the chase fell before `quote_chase_not_before`, Q3-ON
   stopped seeing it and answered exactly as Q5-ON, and the control ("the thirteen callables answer pairwise
   differently") failed on every run. While it failed, the six TL40 weakened copies counted as caught whatever they
   did. The control book and its calls are now judged at one fixed moment, 7 Nov 2026 12:00 Kampala, the day of
   5.18.104's two passes; nothing else in the file changes. On the fixed tree: TL40 11 passed, 0 failed; the whole
   file 397 passed, 0 failed. Weakened copies: with the chase removed, or the moment one second past the edge, the
   control fails; at the edge exactly it passes; with Q3-ON dispatched as Q5-ON, TL40's dispatch check fails, a fault
   the broken control had hidden. The fault is 5.18.104's, but the failing check is in 5.18.105's suite, so it is
   fixed in the re-cut.

### S.3 The release suite

Not complete. The plan is §H.7 step 5: two full passes on the release commit (now `7835906`; until 14 Nov `f764699`),
in a frozen, clean worktree, one after the other,
with nothing beside them. Pass A2 began on 13 Nov at 05:29:32 UTC and was cut by a restart of this container: its
log ends at 10:19:34 UTC inside file 230 of 317 (15,127 checks passed up to there, and 4 failed, all in three of the four
files that fail in this container only). A cut pass does not count. The two passes are run again, and their results
are recorded here and in root `docs/07` when they end. Pass A3 began on 14 Nov at 01:13:12 UTC.

A restart cut pass A3 as well: its log stops at 05:20:21 UTC inside the same file, 230 of 317
(`test_quote_from_ucrm.php`), with 229 files done: 15,126 checks passed and 5 failed. Four of the five are in three
of the four files that fail in this container only. The fifth is one check of `test_quote_followup.php` (767 passed,
1 failed), which read 768 and 0 in pass A2; it is described here when it has been measured. The pass was resumed at
05:32:10 UTC from the start of the file that was cut, by `tests/run.sh`'s own loop begun at that file: the same
directory, the same order, one vault per file, the same frozen worktree. Its log carries a `RESUME` line. It is
recorded as one pass in pieces, not as an uninterrupted pass.

A second restart cut the resumed piece: its log stops at 06:37:03 UTC, again inside `test_quote_from_ucrm.php`, which
had then run 65 minutes without a failure. It was resumed in the same way at 06:53:40 UTC (a second `RESUME` line).
Both cuts came 22 and 25 minutes after the last activity of the session that runs the suite; the container appears to
be reclaimed when that session is idle. That is inferred from the times, not measured. From 06:53 UTC the session is
kept active every nine minutes until the pass ends.

**Pass A3 ended at 10:36:43 UTC: 317 files, 22,975 checks passed, 10 failed.** Seven failures are in the four files
that fail in this container only (`test_install_authorisation` 379/2, `test_mail_quote` 91/1,
`test_migration_integrity` 27/1, `test_secure_file` 22/3), as in 5.18.104's passes. The other three:
- `test_quote_followup` L05 (767/1): through `test_master_104.php` D03, a check of 5.18.104's that compares the
  status tool's output with `221c9dc`'s and masks only the header's "ago" figure. The row's own last-run column
  prints its seconds unmasked, so a second ticking between the two runs flips it. It is rare: it read 768/0 in pass
  A2 and in both of 5.18.104's passes.
- `test_quote_summary` M (57/1, §S.2 item 6) and TL40's control (396/1, §S.2 item 7). These two make the first cut
  wrong, so the release was re-cut (§S.1), and A3 counts for neither of the two passes.
Compared file by file with 5.18.104's pass B, every other difference is a count that 5.18.105's own tests and
re-points add.

**Some of 5.18.104's checks depend on the run date.** A read of every test for the real clock against fixed dates
(14 Nov, read-only) found, besides TL40:
- CK12 (`tests/sales_followup_tool/CK_checks.php`) and HK11 (`tests/test_wa_send_budget.php`) claim a send on
  today's Kampala date, and fail on a Kampala Sunday, when the send window is closed. CK12 also fails on every run
  from 20 Nov 21:00 UTC, when its quotation, valid until 20 Nov, has expired.
- On a Sunday from about 06:00 to 19:00 UTC, `test_pilot_safety` fails, and `test_notify_evo_retry`,
  `test_sales_pilot` and `test_multi_number_routing` skip their follow-up cases.
- wa_offers K07 has passed without exercising its recent-offer exemption since 9 Nov.
- voice_ack K02 will fail from 31 Dec 2026.
- `test_tick_auto_pull_log` can flake at hh:59:5x UTC, and L05 at any second.
None is 5.18.105's, and none is changed here; each is recorded for a later release. So the two passes on `7835906`
run on Monday-to-Saturday Kampala days, outside Sunday daytime UTC, and before 20 Nov 21:00 UTC. As before, files
35–36 (`test_cashbook_physical_list`) never run inside 21:00–24:00 UTC.

**The fixed tests, run on their own on the fixed tree before the re-cut** (14 Nov, 10:41–11:47 UTC, one at a time):
`test_quote_summary` 59/0; TL40 11/0; `test_log_mask` 384/0; `test_sales_followup_tool` 397/0; quote_followup N
and M 291/0; quote_agent_copy J 116/0; catalogue_direct I 107/0; quote_from_ucrm I with its MI copies 46/0. The
two passes on `7835906` are recorded here when they end; pass B1 began on 14 Nov at 11:48:16 UTC.

### S.4 The deploy script and its rehearsal

- **`scripts/deploy-5.18.105.sh`** (14,745 lines, sha256 `c70820cd69af899b…`), pinned to `7835906`. Re-derived on
  14 Nov for the re-cut, from the same eleven parts and 300 edits: only the pin, the counts (89 files, 46 changed,
  386 kept) and the four changed files' sha256 values moved. On `f764699` it was 14,744 lines, sha256
  `c028cde545e042ba…`, the script of trial 5 below. Derived from
  5.18.104's (sha256 `1aafc15bd1cdb1d7…`) by asserted edits in eleven parts, 300 edits, derived twice byte for byte.
  It adds the ladder's rule — LK-G1, LK-B1, LK-CA1, LK-CA2, LK-ON, LK-RB0 and LK-RB1: every ladder key reads after the
  copy, and after a rollback, exactly as before — and the masking checks LM-G1, LM-G2, LM-CA1, LM-CA2 and LM-RB1. It
  refuses `--clear-switches`. `scripts/sales-followup-report.sh` is re-pinned beside it.
- **The rehearsal** is `scripts/harness/deploy-5.18.105/` (`rehearse.sh`, `rh105q.py`, `rh105-ref.php`), chained on
  5.18.104's rehearsal up to just before its 104-10. It was reviewed three times before any run: 13, 8 and 9 confirmed
  defects, each checked against the code, fixed and rechecked (item 4 above lists the ones that amend §H.6).
  - **Development trial 1** (13 Nov, 17:59–19:07 UTC, from the chain's saved state, with pre-d and pre-e taken from
    5.18.104's own snapshots): **2,680 ok, 20 failed** — the three inherited checks, and 17 that were two defects of
    the script, each fixed in the derivation:
    - **T1-a:** AC-CA2 refused in every deploy and `--after-only` (`refused=tree_p7`). Its harness refused a baseline
      that holds `lib/QuoteAgentCopy.php`. That was right while the baseline was 5.18.103; 5.18.104 carries P7 as the
      pin does, so both trees must hold it.
    - **T1-b:** every rollback failed its reference check (`batch1:event-processor`). It still expected 5.18.103's
      worker-owned list, where a rollback of 5.18.105 leaves 5.18.104's, which is the pin's.

    Nothing else failed: each weakened copy was caught, and the drives of LN-01…LN-04 passed.
  - **Sweep 1** (13 Nov, beside trial 2): since both defects were carried lines that still took the baseline for
    5.18.103, two readers went through the script for more of that kind, in the branches a trial does not take, and a
    third checked each finding. Seven were confirmed and fixed in the derivation. One was a false pass: after a
    rollback, a book with none of the four offer tables was a note ("091 never applied"), and is now a FAIL. One was a
    wrong instruction (item 5 of §S.2). Five were false words in notes, an ok line and two failure hints, which spoke
    of 089–091 as this deploy's own. The header's dates for the ladder's steps, written on 9 Nov as a plan, were
    corrected with them.
  - **Development trial 2** (13 Nov, 19:09–20:26 UTC, the ladder walked through the real tool, the script with T1-a
    and T1-b): **2,707 ok, 5 failed** — the three inherited checks and two expectations of the rehearsal, both fixed
    in the rehearsal. No check of the script failed but where a weakened copy or a planted fault made it, and the walk
    (pre-d, pre-e: 19 checks) passed whole on its first run.
    - **T2-a:** 105-4f counted the day's `list_last` rows as its own two. Once the ladder is walked the day already
      holds the row of the walk's own list run. The two planted rows are now counted as a difference.
    - **T2-b:** step 12's check failed in `--after-only` (`ledger_unmapped`). 5.18.104's rehearsal ends with four
      offer claims it planted raw on the book, and the release's check rightly counts an offer ledger row that maps to
      no recipient as a fault. Production has none. They are now removed at the cut (pre-a0), by the ledger's own
      400-day rule under a clock of that step's own; without that clock the ledger refuses the removal, which is the
      step's control.
  - **Development trial 3** (13 Nov, 20:28–21:43 UTC, the ladder walked, every fix above): **2,710 ok, 3 failed** —
    the three inherited checks alone. Nothing of 5.18.105's own sections failed: pre-a0 removed the four planted
    claims, 105-4f read the day's rows as one before the plant and three after, and step 12's check read PASS in
    `--after-only`. Every FAIL line the script printed was a weakened copy's or the planted sign-in fault's.
  - After it, two comments of the script that named the wrong rollback target were corrected (comments only: the
    script's 296th edit and its 295th), and one line of the rehearsal's header.
  - **The record run's first start** (13 Nov, 21:45 UTC, on `0214b50`) was stopped by hand at 22:05 UTC, at 380 ok
    and 1 failed, early in the 5.18.96 stage. The failure was in the 5.18.95 stage, four minutes earlier, and was
    not this release's. It was check 3s' of 5.18.95's rehearsal, which runs a weakened copy of 5.18.95's script. On
    a line the copy keeps unchanged from the script, it refused a pin that the copy run before it had read
    correctly two seconds earlier.
    - **The cause is a hazard the older scripts are known for;** this script's header describes it. Under
      `pipefail`, a list printed into a quiet `grep` can read "no match" although the line is there. The shell
      writes the list one line at a time, the `grep` stops at its first match, and a writer cut off mid-list fails
      the whole pipeline. It takes an unlucky moment. Two review readers were working beside the run; a busy
      machine may make such a moment likelier, but that was not measured.
    - **Shown directly:** with the writer slowed, the pipeline reports a failure (status 141: the writer was cut
      off) although the `grep` had found its line (status 0); the same check read from a here-string reports
      success. Tried 8,000 times afterwards, idle and with the machine loaded, it did not happen again. It is rare.
    - A run with a fourth failure cannot be the record, so it was cut. Its log is kept.
  - **Four lines of this script still had that form,** carried from 5.18.104's: the report's headline (QF-N3), the
    doctor's two readings (QF-G2) and 090's verdict (VN-G7). The last three could turn a pass into a refusal at
    stage A, with nothing changed; the first could only print "could not be read" in a note. None could turn a
    fault into a pass. They now read their variable as a here-string, which delivers the same bytes: 18 inputs
    tried, the old form and the new agree on each. The derivation's last part now refuses a script in which a quiet
    `grep` reads straight from a pipe; with one of the four edits taken out it refuses and names the line. These
    are the script's edits 297 to 300.
  - **The whole script was then read for the same hazard, with every kind of reader that can stop early** (14 Nov;
    two readers working apart, one starting from the readers, the other from the places where a status is judged;
    nothing was run). Both count 686 pipes in 474 pipelines. Where the script judges a pipeline's status (40
    pipelines by one count, 42 by the other), the reader reads its input to the end: `sed` without `q`, `tr`, a
    `grep` that counts or inverts, `xargs`, `tar`, `docker exec -i … cat`, an `awk` whose only `exit` is in `END`,
    or `drained grep -q` (7 uses). The pipelines whose reader can stop early (58 `head` stages, 7 `grep -m1`, the
    `awk`s that exit, 13 functions used as readers) give only text: nothing judges their status. No further line
    of this kind was found, and the script is unchanged. That `tar` reads an archive from Git to its end (8 judged
    pipelines) is known of `tar`, not measured here.
  - **Development trial 4, a short one, of the corrected script** (13 Nov, 22:14–22:21 UTC, on `be8930c`; from the
    chain's saved state, the ladder not walked, its two states taken from 5.18.104's own snapshots; stopped by hand
    after 105-c): 33 ok, 0 failed in 5.18.105's own sections. `--check` read GO over both ladder states, with QF-G2
    and VN-G7 ok and QF-N3's report printed.
  - **The record run's second start** (22:22 UTC, on `be8930c`, nothing beside it) was cut after 8 minutes by a
    restart of the container, at 148 ok and 0 failed. **Its third start** (22:34 UTC) was cut after 71 minutes by
    another restart, in the 5.18.98 stage, at 1,020 ok and 1 failed, that one being the first inherited check
    (98-0); the 5.18.95 stage passed whole. Nothing of the runs' caused the restarts, as far as can be seen. A cut
    run does not count.
  - **Development trial 5** (13 Nov, 23:48 – 14 Nov, 01:12 UTC; from the chain's saved state, the ladder walked; the
    corrected script, on `d433d90`, whose script and rehearsal are `be8930c`'s): **2,710 ok, 3 failed** — the three
    inherited checks alone. 5.18.105's own sections read 153 ok, 0 failed. The script's outputs that hold a FAIL
    line are those of six weakened copies and of the planted sign-in fault, the same seven as in trial 3.
  - **Next:** the whole chain takes about nine hours in one piece, which this container did not give on the night
    of 13 Nov. The suite's passes come first; the whole-chain record run is tried again after them. The results
    are recorded here and in root `docs/07`.

### S.5 Next

- The suite's two passes, then the rehearsal's trials and its record run, then root `docs/07`'s 5.18.105 entry with the
  deploy command and, in its own block, the rollback.
- **The owner's, then:** the word to push `release/5.18.105`; the deploy; its `--after-only`. Step 12, `offers-live`,
  waits for that (the owner's ruling, 9 Nov). Steps 1–6 do not wait.
- **The owner's, separately:** the older faults of §S.2 item 3. The first two are in jobs that run on Uganda (LN-10's
  and LN-14's).
